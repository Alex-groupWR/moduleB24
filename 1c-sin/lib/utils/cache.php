<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache as BitrixCache;
use Bitrix\Main\IO\Directory;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;

class Cache
{
	protected static $isCacheEnabled = true;

	/** @var string[] */
	protected $keys = [];

	/** @var string[] */
	protected $tags = [];

	/** @var bool[] */
	protected $conditions = [];

	protected $time = 86400; // День
	protected $callback;

	public static function disableCache()
	{
		static::$isCacheEnabled = false;
	}

	public static function create(): Cache
	{
		return new static();
	}

	public static function clearByTag(string $tag): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag($tag);
	}

	public static function cleanDirectory(string $directory): void
	{
		BitrixCache::createInstance()->cleanDir($directory);
	}

	protected function __construct() { }

	public function setCallback($callback): Cache
	{
		$this->callback = $callback;
		return $this;
	}

	public function setIblockId(int $iblockId): Cache
	{
		$this->addTag('iblock_id_' . $iblockId);
		return $this;
	}

	/**
	 * @param string[] $keys
	 * @return $this
	 */
	public function setKeys(array $keys): Cache
	{
		$this->keys = $keys;
		return $this;
	}

	public function addKey(?string $key): Cache
	{
		$this->keys[] = $key;
		return $this;
	}

	/**
	 * Условия срабатывания кеша
	 * Например, addCondition($arParams['CACHE_TYPE'] != 'N')
	 *
	 * @param bool $condition
	 * @return $this
	 */
	public function addCondition(bool $condition): Cache
	{
		$this->conditions[] = $condition;
		return $this;
	}

	protected function isCacheEnabled(): bool
	{
		foreach ($this->conditions as $condition){
			if (!$condition){
				return false;
			}
		}

		return static::$isCacheEnabled;
	}

	public function addTag(string $tag): Cache
	{
		$this->tags[] = $tag;
		return $this;
	}

	public function setTags(array $tags): Cache
	{
		$this->tags = $tags;
		return $this;
	}

	protected function getPath(): string
	{
		return '/' . implode('/', $this->tags);
	}

	public function setTime(int $timeSec): Cache
	{
		$this->time = $timeSec;
		return $this;
	}

	protected function getCacheId(): string
	{
		return md5(json_encode(array_merge($this->keys, $this->tags)));
	}

	protected function execute()
	{
		return call_user_func($this->callback);
	}

	public function getResult()
	{
		if (!$this->keys){
			throw new \Exception('Не указаны ключи кеша');
		}

		if (!$this->callback){
			throw new \Exception('Не указана функция кеша');
		}

		$cache = BitrixCache::createInstance();
		$taggedCache = Application::getInstance()->getTaggedCache();
		if ($this->isCacheEnabled() && $cache->initCache($this->time, $this->getCacheId(), $this->getPath())) {
			$result = $cache->getVars();
		}
		elseif ($this->isCacheEnabled() && $cache->startDataCache()) {
			$taggedCache->startTagCache($this->getPath());
			$result = $this->execute();
			foreach ($this->tags as $tag){
				$taggedCache->registerTag($tag);
			}
			$taggedCache->endTagCache();
			$cache->endDataCache($result);
		}
		else{
			$result = $this->execute();
		}

		return $result;
	}

	public static function clearComposite(): void
	{
		$staticHtmlCache = \Bitrix\Main\Composite\Page::getInstance();
		if ($staticHtmlCache){
			$staticHtmlCache->deleteAll();
		}
	}

	public static function deleteCompositePageForProduct(string $code): void
	{
		foreach (GeoLocation::getCities() as $city){
			$path = Application::getDocumentRoot() . '/bitrix/html_pages/' . $city['DOMAIN'] . '.rusgeocom.ru/products/' . $code;
			if (Directory::isDirectoryExists($path)){
				Directory::deleteDirectory($path);
			}
		}
	}
}