Single thread
=============
`````````
$Threads = [];
$Threads[] = new Thread(function(Thread $Thread) {
	$Thread->saveData(file_get_contents($Thread->getLaunchParams()[0]));
});
$Threads[] = clone $Threads[0];
$Threads[] = clone $Threads[0];
 
$Threads[0]->run('http://ya.ru');
$Threads[1]->run('http://mail.ru');
$Threads[2]->run('http://r0.ru');

while (array_filter($Threads, function (Thread $Thread) {return $Thread->isChildAlive();})) {
	sleep(1);
}

array_walk($Threads, function(Thread $Thread) {
	var_dump($Thread->getSavedResult());
});
```

ThreadPool
==========
Allows to execute unlimited number of threads
Manages child tasks and respawns them if they died without setting their status as "complete"
It is useful to execute scripts with memory leak
Can be used for daemon implementation
```
$res = (new ThreadPool(10)) //threads count
	->setTask(function (Thread $Thread) {
		$data = $Thread->getSavedData();
		$i = isset($data['i']) ? $data['i'] : 0;
		$i++;
		var_dump($i);

		$Thread->saveResult([
			'i'   => $i,
			'num' => $Thread->getThreadNumber(),
		]);

		if ($i >= 10) {//script will exit after 10 iterations/launches
			$Thread->markFinished();
		}
	})->setInterruptedHandler(function (Thread $Thread, $signo) {
		//dump current state before exit
		file_put_contents('worker_' . $Thread->getThreadNumber(), json_encode($Thread->getSavedResult()));
		trigger_error('Interrupted with signal ' . $signo);
	})->run();

var_dump($res);

// will return [
//     0 => [
//         'i'   => 10,
//         'num' => 0
//     ],
//     1 => [
//         'i'   => 10,
//         'num' => 1
//     ]
// ];
```