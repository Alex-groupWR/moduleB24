<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Logema\Utils\DateTime;

class XmlCalendar
{
	/**
	 * Расписание праздников на текущий и следующий год
	 *
	 * @return array
	 */
	public static function getDaysFromXml(): array
	{
		$curYear = static::getDaysFromFile(date('Y'));
		$nextYear = static::getDaysFromFile(date('Y') + 1);
		return array_merge($curYear, $nextYear);
	}

	public static function getDaysFromXmlCached(): array
	{
		$cacheId = md5(__METHOD__ . date('Y'));
		$cache = Cache::createInstance();
		$taggedCache = Application::getInstance()->getTaggedCache();
		if ($cache->initCache(3600, $cacheId, '/calendar')) {
			$result = $cache->getVars();
		}
		elseif ($cache->startDataCache()) {
			$taggedCache->startTagCache('/calendar');
			$result = static::getDaysFromXml();
			$taggedCache->registerTag('calendar');
			$taggedCache->endTagCache();
			$cache->endDataCache($result);
		}
		else{
			$result = static::getDaysFromXml();
		}

		return $result;
	}

	private static function getDaysFromFile(string $year): array
	{
		$url = static::getUrl($year);
		$calendar = simplexml_load_file($url);
		$days = [];
		foreach ($calendar->days->day as $day){
			$date = ((array)$day->attributes()->d)[0];
			$date = \DateTime::createFromFormat('Y.m.d', $year . '.' . $date);
			$type = ((array)$day->attributes()->t)[0];
			$type = $type == 1 ? Calendar::WORKDAY_STATUS_HOLIDAY : Calendar::WORKDAY_STATUS_WORKING;
			$days[$date->format('d.m.Y')] = $type;
		}

		return $days;
	}

	private static function getUrl(string $year): string
	{
		return 'http://xmlcalendar.ru/data/ru/' . $year . '/calendar.xml';
	}
}