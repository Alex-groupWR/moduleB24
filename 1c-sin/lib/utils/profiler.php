<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Diag\SqlTrackerQuery;
use Illuminate\Support\Str;

class Profiler
{
	/** @var \Bitrix\Main\DB\Connection */
	private $connection;

	/** @var \Bitrix\Main\Diag\SqlTracker */
	private $tracker;

	public function start(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$this->tracker = $connection->startTracker();
	}

	public function stop(): void
	{
		$connection = \Bitrix\Main\Application::getConnection();
		$connection->stopTracker();
	}

	public function getResult(): array
	{
		$queries = [];
		/** @var SqlTrackerQuery $query */
		foreach ($this->tracker->getQueries() as $query){

			$trace = [];
			foreach ($query->getTrace() as $traceItem){
				if ($traceItem['file']){
					$trace[] = $traceItem['file'] . ':' . $traceItem['line'];
				} else {
					$trace[] = $traceItem['function'];
				}
			}

			$queries[] = [
				'time' => round($query->getTime() * 1000, 5), // Перевод в мс и округление
				'sql' => Str::of($query->getSql())->replace("\n\t", ' ')->replace("\n", ' '),
				'trace' => $trace,
			];
		}

		return [
			'queries' => $queries,
		];
	}
}