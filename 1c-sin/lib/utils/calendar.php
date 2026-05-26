<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Logema\Utils\DateTime;
use PhpImap\Exception;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;

class Calendar
{
	public const WORKDAY_STATUS_WORKING = 1; // Рабочий
	public const WORKDAY_STATUS_WEEKEND = 2; // Выходной
	public const WORKDAY_STATUS_HOLIDAY = 3; // Праздник
	protected const WORKDAYS_FILE_PATH = '/ajax/workdays.csv';
	protected static $scheduleCache = [];
	protected static $dayStatusMapCache = [];

	/**
	 * Прибавляет рабочие дни с учётом праздников и выходных
	 *
	 * @param DateTime $date
	 * @param int      $days
	 * @param string   $domain
	 * @param bool     $checkWeekends
	 * @param bool     $checkHolidays
	 * @return DateTime
	 * @throws \Exception
	 */
	public static function addWorkDays(DateTime $date, int $days, string $domain, $checkWeekends = true, $checkHolidays = true) : DateTime
	{
		$days = static::getDeliverySchedule($domain, $days + 1, $checkWeekends, $checkHolidays)[$days];
		return DateTime::fromPhpDateTime($date->toPhpDateTime()->add(new \DateInterval('P'. $days .'D')));
	}

	public static function getNextWorkDayForDomain(DateTime $date, string $domain): DateTime
	{
		$counter = 0;
		do{
			$date = DateTime::fromTimestamp($date->getTimestamp() + 86400);

			if ($counter++ > 100){ // Никому нельзя доверять
				throw new Exception('Бесконечный цикл в календаре.');
			}
		}
		while (static::getDayStatusForDomain($date, $domain) == static::WORKDAY_STATUS_WEEKEND);

		return $date;
	}

	public static function isNextDayWorkingDayForDomain(string $domain) : bool
	{
		$nextDate = DateTime::fromTimestamp(DateTime::now()->getTimestamp() + 86400);

		return static::getDayStatusForDomain($nextDate, $domain) === static::WORKDAY_STATUS_WORKING;
	}

	/**
	 * Дополнительные рабочие дни
	 *
	 * @return array
	 */
	public static function getWorkDays(): array
	{
		$days = [];
		$fileName = $_SERVER['DOCUMENT_ROOT'] . static::WORKDAYS_FILE_PATH;
		if (($stream = fopen($fileName, 'r')) !== false) {
			fgetcsv($stream, 0, ';'); // Пропускаем первую строку
			while (($row = fgetcsv($stream, 0, ';')) !== false) {
				if (count($row) == 5){
					$date = \DateTime::createFromFormat('d.m.Y', "{$row[0]}.{$row[1]}.{$row[2]}");
					$status = static::getStatusByName($row[3]);
					$domain = $row[4];

					if ($status){
						$days[$domain][$date->format('d.m.Y')] = $status;
					}
				}
			}
			fclose($stream);
		}

		return $days;
	}

	private static function getStatusByName(string $name): int
	{
		$map = [
			'рабочий' => static::WORKDAY_STATUS_WORKING,
			'выходной' => static::WORKDAY_STATUS_WEEKEND,
			'праздник' => static::WORKDAY_STATUS_HOLIDAY,
		];
		return $map[$name] ?: 0;
	}

	public static function getWorkDaysHash()
	{
		return filemtime($_SERVER['DOCUMENT_ROOT'] . static::WORKDAYS_FILE_PATH);
	}

	/**
	 * Проверяет выходной или рабочий день для конкретного филиала
	 *
	 * @param DateTime $date
	 * @param string   $domain
	 * @return mixed
	 */
	public static function getDayStatusForDomain(DateTime $date, string $domain): int
	{
		$dayCount = ceil(($date->getTimestamp() - time()) / 86400) + 1;
		return static::getDayStatusMap($domain, $dayCount)[$date->format('d.m.Y')];
	}

	/**
	 * Проверяет сегодня выходной или рабочий день для филиала
	 *
	 * @param string $domain
	 * @return mixed
	 */
	public static function getCurrentDayStatusForDomain(string $domain): int
	{
		return static::getDayStatusForDomain(DateTime::now(), $domain);
	}

	/**
	 * Расписание в виде [day] => status, где
	 * day - номер дня доставки (период доставки == 10 дней, значит day == 10),
	 * status - статус дня (Calendar::WORKDAY_STATUS_WEEKEND)
	 *
	 * @param string $domain
	 * @param int    $dayCount
	 * @param bool   $checkWeekends
	 * @param bool   $checkHolidays
	 * @return array
	 * @throws \Exception
	 */
	public static function getDeliverySchedule(
		string $domain,
		int $dayCount,
		bool $checkWeekends = true,
		bool $checkHolidays = true
	): array
	{
		$cacheKeys = [$domain, $dayCount, $checkWeekends, $checkHolidays];
		$cacheId = md5(implode('|', $cacheKeys));
		if (static::$scheduleCache[$cacheId]){
			return static::$scheduleCache[$cacheId];
		}

		// Цифры взяты с потолка, нужно взять дней больше, чтобы пропускать выходные
		$dayStatusMap = static::getDayStatusMap($domain, $dayCount * 2 + 20, $checkHolidays);
		$csvWorkDays = static::getWorkDays()[$domain] ?: [];

		$dayCounter = 0;
		$resultCounter = 0;
		$schedule = [];

		while ($resultCounter < $dayCount){

			if ($dayCounter > $dayCount * 100){ // На всякий случай
				throw new \Exception('Бесконечный цикл в календаре');
			}

			$ts = time() + 86400 * $dayCounter;
			$dateStr = date('d.m.Y', $ts);
			$dayStatus = $dayStatusMap[$dateStr];

			if ($checkWeekends && $dayStatus == Calendar::WORKDAY_STATUS_WEEKEND){
				// Пропускаем выходной
			}
			else if ($checkHolidays && $dayStatus == Calendar::WORKDAY_STATUS_HOLIDAY){
				// Пропускаем праздник
			}
			else if (GeoLocation::isFullWeek($domain) && $csvWorkDays[$dateStr] == Calendar::WORKDAY_STATUS_WEEKEND){
				// Пропускаем выходной для филлиалов без выходных
				// CSV дни в приоритете, даже если не хотим учитывать выходные
			}
			else{
				$schedule[$resultCounter++] = $dayCounter;
			}

			$dayCounter++;
		}

		static::$scheduleCache[$cacheId] = $schedule;
		return $schedule;
	}

	public static function getDayStatusMap(string $domain, int $dayCount, $checkHolidays = true): array
	{
		$cacheKeys = [$domain, $dayCount, $checkHolidays];
		$cacheId = md5(implode('|', $cacheKeys));
		if (static::$dayStatusMapCache[$cacheId]){
			return static::$dayStatusMapCache[$cacheId];
		}

		$xmlDates = XmlCalendar::getDaysFromXmlCached(); // Из XML-календаря
		$csvDates = $domain ? static::getWorkDays()[$domain] : []; // Из csv с исключениями

		$schedule = [];

		for ($i = 0; $i < $dayCount; $i++) {
			$ts = time() + 86400 * $i;
			$dateStr = date('d.m.Y', $ts);
			$isWeekend = in_array(date('N', $ts), [6, 7]);
			$defaultStatus = $isWeekend ? Calendar::WORKDAY_STATUS_WEEKEND : Calendar::WORKDAY_STATUS_WORKING;

			if ($checkHolidays){

				// Просто берём первый заполненный по приоритету источника
				$dayStatus = $csvDates[$dateStr] ?: $xmlDates[$dateStr] ?: $defaultStatus;
			}
			else{

				// Ищем первый заполненный НЕ праздник
				if ($csvDates[$dateStr] && $csvDates[$dateStr] != Calendar::WORKDAY_STATUS_HOLIDAY){
					$dayStatus = $csvDates[$dateStr];
				}
				elseif ($xmlDates[$dateStr] && $xmlDates[$dateStr] != Calendar::WORKDAY_STATUS_HOLIDAY){
					$dayStatus = $xmlDates[$dateStr];
				}
				else{
					$dayStatus = $defaultStatus;
				}
			}

			$schedule[$dateStr] = $dayStatus;
		}

		static::$dayStatusMapCache[$cacheId] = $schedule;
		return $schedule;
	}
}