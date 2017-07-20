<?php namespace axxapy\ThreadPool;

use axxapy\Debug\Log;
use axxapy\Interfaces\Runnable;
use RuntimeException;

/**
 * This script enables launch tasks in several threads, collect data
 *
 * <code>
 * $res = (new ThreadPool(10)) //threads count
 *     ->setTask(function (Thread $Thread) {
 *         $data = $Thread->getSavedData();
 *         $i = isset($data['i']) ? $data['i'] : 0;
 *         $i++;
 *         var_dump($i);
 *
 *         $Thread->saveResult([
 *             'i'   => $i,
 *             'num' => $Thread->getThreadNumber(),
 *         ]);
 *
 *         if ($i >= 10) {//script will exit after 10 iterations/launches
 *             $Thread->markFinished();
 *         }
 *     })->setInterruptedHandler(function (Thread $Thread, $signo) {
 *         //dump current state before exit
 *         file_put_contents('worker_' . $Thread->getThreadNumber(), json_encode($Thread->getSavedResult()));
 *         Log::i(__CLASS__, 'Interrupted with signal ' . $signo);
 *     })->run();
 *
 * var_dump($res);
 * // will return [
 * //     0 => [
 * //         'i'   => 10,
 * //         'num' => 0
 * //     ],
 * //     1 => [
 * //         'i'   => 10,
 * //         'num' => 1
 * //     ]
 * // ];
 * </code>
 */
class ThreadPool implements Runnable {
	const TAG = 'MANAGER';

	private $threads = 1;

	/** @var callable */
	private $Task;

	/** @var callable */
	private $handler_interrupted;

	/** @var callable */
	private $handler_on_tick;

	/**
	 * Forked workers. Only if process is manager.
	 *
	 * @var Thread[]
	 */
	private $Threads = [];

	private $launched = false;

	private $stopping = false;

	private $sleep_timeout_ms     = 20000;//0.2 seconds
	private $sleep_before_sigkill = 5; //seconds

	private $thread_time_limit_sec;

	public function __construct($threads_count = 1) {
		$this->threads = (int)$threads_count;
	}

	/**
	 * Task itself. Will be called inside each thread. Will be executed until <code>$Work->markFinished()</code> be
	 * called.
	 *
	 * Call signature: <code>function(Worker $Worker) {}</code>
	 *
	 * @param mixed $Task
	 *
	 * @return $this
	 */
	public function setTask(callable $Task) {
		$this->Task = $Task;
		return $this;
	}

	/**
	 * This callback will be called once for each thread if script will be interrupted.
	 *
	 * Call signature: <code>function(Worker $Worker) {}</code>
	 *
	 * @param mixed $callback
	 *
	 * @return $this
	 */
	public function setInterruptedHandler(callable $callback) {
		$this->handler_interrupted = $callback;
		return $this;
	}

	/**
	 * @param callable $callback_tick
	 *
	 * @return $this
	 */
	public function setTickHandler(callable $callback_tick) {
		$this->handler_on_tick = $callback_tick;
		return $this;
	}

	/**
	 * Sleep timeout between checking children status
	 *
	 * @param int $value_ms
	 *
	 * @return $this
	 */
	public function setSleepTimeout($value_ms) {
		$this->sleep_timeout_ms = (int)$value_ms;
		return $this;
	}

	/**
	 * Sets max execution time for each thread, in seconds
	 *
	 * @param $sec
	 *
	 * @return $this
	 */
	public function setThreadTimeLimit($sec) {
		$this->thread_time_limit_sec = (int)$sec;
		return $this;
	}

	/**
	 * Sets number of threads/workers
	 *
	 * @param int $threads
	 *
	 * @return $this
	 */
	public function setThreadsCount($threads) {
		$this->threads = (int)$threads;
		return $this;
	}

	/**
	 * Returns thread count
	 *
	 * @return int
	 */
	public function getThreadsCount() {
		return $this->threads;
	}

	/**
	 * @param array $params
	 *
	 * @return array|void
	 *
	 * @throws \RuntimeException
	 */
	public function run(array $params = []) {
		if ($this->launched) {
			throw new RuntimeException('Cannot be launched twice');
		}

		if (!$this->Task) {
			throw new RuntimeException('Useless call without task. use ::setTask() method to set task first');
		}

		$this->launched = true;

		//initial fork
		for ($i = 0; $i < $this->threads; $i++) {
			$Thread = new Thread($this->Task, $this->handler_interrupted, $i);
			if ($Thread->run($params)) return;//forked process
			$this->Threads[$i] = $Thread;
		}

		$this->setup_sig_handler();
		$this->updateProcessTitle();

		//respawn or kill (if timeouted) threads
		do {
			$active_threads = count($this->Threads);
			foreach ($this->Threads as $worker_id => $Thread) {
				if ($Thread->isChildAlive()) {
					if ($this->thread_time_limit_sec && time() - $Thread->getStartTime() > $this->thread_time_limit_sec) {
						$this->kill_thread($Thread);
						sleep($this->sleep_before_sigkill);
						$this->kill_thread_sigkill($Thread);
					}
					continue;
				}

				// If the process has already exited but task isn't finished yet
				if (!$Thread->isTaskFinished()) {
					if ($Thread->run($params)) return;
				} else {
					$active_threads--;
				}
			}
			if ($active_threads < 1) break;

			if ($this->handler_on_tick) {
				call_user_func($this->handler_on_tick, $this);
			}

			usleep($this->sleep_timeout_ms);
		} while (!$this->stopping && $this->launched);

		$this->log('All threads have finished');

		//prepare results
		$result = array_map(function (Thread $Thread) {
			return $Thread->getSavedResult();
		}, $this->Threads);

		//reset state
		$this->Threads  = [];
		$this->launched = false;
		$this->stopping = false;

		return $result;
	}

	/**
	 * Stop all children by sending them SIGUSR1(10)
	 *
	 * @return bool
	 */
	public function forceStop() {
		if ($this->stopping) {
			return false;
		}
		$this->stopping = true;

		$this->log('killing children:');
		foreach ($this->Threads as $Thread) {
			$this->kill_thread($Thread);
		}
		$this->log('giving 5 sec to children to exit nicely...');
		sleep($this->sleep_before_sigkill);
		foreach ($this->Threads as $worker_id => $Thread) {
			$this->kill_thread_sigkill($Thread);
		}
		return false;
	}

	private function kill_thread(Thread $Thread) {
		$this->log('killing...', $Thread);
		posix_kill($Thread->getChildPid(), SIGUSR1);
		pcntl_signal_dispatch();
	}

	private function kill_thread_sigkill(Thread $Thread) {
		if (!$Thread->isChildAlive()) {
			$this->log('exited gently, no SIGKILL required.', $Thread);
			return;
		}
		posix_kill($Thread->getChildPid(), SIGKILL);
		$this->log('did not exit. SIGKILL sent.', $Thread);
	}

	/**
	 * Updates process title, adds [WORKER] or [MANAGER] to its description in process list
	 */
	private function updateProcessTitle() {
		$title = '[MANAGER] ' . implode(' ', $_SERVER['argv']); //cli_get_process_title();
		cli_set_process_title($title);
	}

	private function setup_sig_handler() {
		//handle system signals
		if (version_compare(PHP_VERSION, '7.1.0', '>=')) {
			pcntl_async_signals(true);
		} else {
			declare(ticks = 1);
		}
		$sig_handler = function ($signo) {
			$this->log('SIGNAL RECEIVED: ' . $signo);
			$this->forceStop();
			die;
		};
		pcntl_signal(SIGTERM, $sig_handler); //Termination. "Nice" killing. Can be caught unlike SIGKILL.
		pcntl_signal(SIGHUP, $sig_handler); //Terminal window was closed
		pcntl_signal(SIGUSR1, $sig_handler); //User-defined. Used to kill workers by manager.
		pcntl_signal(SIGINT, $sig_handler);  //Interrupt, Ctrl+C
	}

	private function log($msg, Thread $Thread = null) {
		if ($Thread) {
			Log::i(self::TAG, 'Thread #' . $Thread->getThreadNumber() . ', pid ' . $Thread->getChildPid() . ': ' . $msg);
		}
		Log::i(self::TAG, $msg);
	}
}
