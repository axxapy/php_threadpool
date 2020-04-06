<?php namespace axxapy\ThreadPool;

use axxapy\Debug\Log;
use axxapy\Interfaces\Storage;
use axxapy\Storages\ApcuStorage;
use axxapy\Storages\FileStorage;
use axxapy\Storages\SecondLevelStorage;
use axxapy\Interfaces\Runnable;
use RuntimeException;
use Throwable;

/**
 * Represents classic Thread interface.
 * Usage:
 * <code>
 * $Threads = [];
 * $Threads[] = new Thread(function(Thread $Thread) {
 *     $Thread->saveData(file_get_contents($Thread->getLaunchParams()[0]));
 * });
 * $Threads[] = clone $Threads[0];
 * $Threads[] = clone $Threads[0];
 *
 * $Threads[0]->run('http://ya.ru');
 * $Threads[1]->run('http://mail.ru');
 * $Threads[2]->run('http://r0.ru');
 *
 * while (array_filter($Threads, function (Thread $Thread) {return $Thread->isChildAlive();})) {
 *     sleep(1);
 * }
 *
 * array_walk($Threads, function(Thread $Thread) {
 *     var_dump($Thread->getSavedResult());
 * });
 * </code>
 */
class Thread implements Runnable {
	const TAG = 'THREAD';

	/** @var Callable */
	private $Closure;

	/** @var Callable */
	private $OnInterruptedClosure;

	/** @var Storage */
	private $StatusStorage;

	private $parent_pid;

	private $child_pid;

	private $payload = [];

	private $task_finished = false;

	private $thread_number;

	private $saved_result;

	private $start_time;

	public function __construct(Callable $Closure, Callable $OnInterruptedClosure = null, $num = -1) {
		$this->thread_number        = (int)$num;
		$this->Closure              = $Closure;
		$this->OnInterruptedClosure = $OnInterruptedClosure;
		$this->parent_pid           = getmypid();
	}

	public function __clone() {
		$this->child_pid     = 0;
		$this->saved_result  = null;
		$this->task_finished = false;
		$this->StatusStorage = null;
	}

	private function getStatusStorage() {
		if (!$this->StatusStorage) {
			$prefix = 'Thread.' . getmypid() . '-' . $this->thread_number . '.';
			try {
				$ApcuStorage = new ApcuStorage();
				$this->StatusStorage = new SecondLevelStorage($ApcuStorage, $prefix);
			} catch (Throwable $ex) {
				$this->StatusStorage = new FileStorage(tempnam(sys_get_temp_dir(), $prefix));
			}
		}
		return $this->StatusStorage;
	}

	/**
	 * isForked or isChild - tells whether current process is a forked child (true) or not
	 *
	 * @return bool
	 */
	public function isForked() {
		return $this->child_pid === 0;
	}

	/**
	 * If task finished, it wont be forked again after exit
	 *
	 * @return bool
	 */
	public function isTaskFinished() {
		if (!$this->isForked()) {
			$this->load_state();
		}

		return $this->task_finished;
	}

	/**
	 * Returns pid for child process.
	 * Will return zero if be called from child process.
	 *
	 * @return int
	 */
	public function getChildPid() {
		return $this->child_pid;
	}

	/**
	 * Returns true if child process still active.
	 *
	 * @return bool
	 */
	public function isChildAlive() {
		if ($this->parent_pid == getmypid() && !$this->child_pid) {
			return false;
		}
		if ($this->isForked()) {
			return true;
			//for some reason php messes with sigs. I don't know, why. Just disable this check for now.
			//throw new RuntimeException('Method supposed to be called only from master process.');
		}
		$res = pcntl_waitpid($this->getChildPid(), $status, WNOHANG);
		return !($res == -1 || $res > 0);
	}

	private function save_state() {
		$this->getStatusStorage()
			->Set('finished', $this->task_finished)
			->set('data', $this->saved_result)
			->Commit();
	}

	private function load_state() {
		$Storage = $this->getStatusStorage();
		if (method_exists($Storage, 'Reload')) $Storage->Reload();
		$this->task_finished = (bool)$Storage->Get('finished', false);
		$this->saved_result  = $Storage->Get('data');
	}

	/**
	 * Marks worker's task as finished - it wont be reforked after exit.
	 */
	public function markFinished() {
		$this->task_finished = true;
		$this->save_state();
		return $this;
	}

	/**
	 * @deprecated use getPayload()
	 */
	public function getLaunchParams() {
		return $this->payload;
	}

	/**
	 * Returns worker's launch params (which were passed to Worker::run() method)
	 *
	 * @return array
	 */
	public function getPayload() {
		return $this->payload;
	}

	/**
	 * Return thread's start time
	 *
	 * @return int
	 */
	public function getStartTime() {
		return $this->start_time;
	}

	/**
	 * Return worker's thread number. Constant.
	 *
	 * @return int
	 */
	public function getThreadNumber() {
		return $this->thread_number;
	}

	/**
	 * Returns worker's global data. Survive refork.
	 *
	 * @return mixed
	 */
	public function getSavedResult() {
		if (!$this->isForked()) {
			$this->load_state();
		}
		return $this->saved_result;
	}

	/**
	 * Saves global worker's data which will be available both to master and to next fork
	 *
	 * @param mixed $result
	 *
	 * @return $this
	 */
	public function saveResult($result) {
		$this->saved_result = $result;
		$this->save_state();
		return $this;
	}

	/**
	 * Launches worker.
	 * Worker will fork itself and launch Closure handler
	 *
	 * @param array $params
	 *
	 * @return bool
	 * @throws RuntimeException
	 */
	public function run(array $params = []) {
		if ($this->isForked()) {
			throw new RuntimeException('Can not be relaunched from child thread');
		}

		if ($this->isChildAlive()) {
			throw new RuntimeException('Thread already launched');
		}

		$this->load_state();
		$this->start_time = time();
		$this->payload    = $params;
		$this->child_pid  = pcntl_fork();
		if ($this->isForked()) {
			$this->setup_sig_handler();
			$this->updateProcessTitle();

			$sid = posix_setsid();
			$sid && call_user_func($this->Closure, $this);

			posix_kill($sid, 9); //on some builds die could not work
			die(0); // die after attempt to kill: sometimes even after script dies, for waits for parent to finish

			throw new RuntimeException('die() does not work! This should never happen!');

			return true;
		}
	}

	private function setup_sig_handler() {
		//handle system signals
		declare(ticks = 1);
		$sig_handler = function ($signo) {
			Log::i(self::TAG, "[{$this->thread_number}] SIGNAL RECEIVED: {$signo}");
			if ($this->OnInterruptedClosure) {
				call_user_func($this->OnInterruptedClosure, $this, $signo);
			}
			Log::i(self::TAG, "[{$this->thread_number}] THREAD HAS BEEN KILLED WITH SIGNO {$signo}");
			die;
		};
		pcntl_signal(SIGTERM, $sig_handler); //Termination. "Nice" killing. Can be caught unlike SIGKILL.
		pcntl_signal(SIGHUP, $sig_handler); //Terminal window was closed
		pcntl_signal(SIGUSR1, $sig_handler); //User-defined. Used to kill workers by manager.
		pcntl_signal(SIGINT, $sig_handler);  //Interrupt, Ctrl+C
	}

	/**
	 * Updates process title, adds [WORKER] or [MANAGER] to its description in process list
	 */
	private function updateProcessTitle() {
		$title = '[' . self::TAG . '] [' . $this->thread_number . '] ' . implode(' ', $_SERVER['argv']); //cli_get_process_title();
		cli_set_process_title($title);
	}

	public function __destruct() {
		//for some reason, forked processes were getting some memory of another forked processes and were removing files.
		//I know, sounds crazy, but try to remove second condition and dump child_pid && current pid.
		if ($this->isForked() || $this->parent_pid != getmypid()) return;
		$this->StatusStorage->Destroy();
	}
}
