<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Context;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Web\Uri;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Seo\Utils as SeoUtils;
use Rusgeocom\Rusgeocom\Types\Uri as RusgeocomUri;

class Url
{
	static $page;

	const CANONICAL_PAGINATION = 'PAGINATION';
	const CANONICAL_SECTION = 'SECTION';
	const CANONICAL_FILTER = 'FILTER';
	const CANONICAL_FILTER_WITH_LINK = 'FILTER_WITH_LINK';
	const DEFAULT_URL = '/';

	public static function getBaseUrl(string $domain = ''): string
	{
		if ($domain) {
			return 'https://' . $domain  . '.'. Environment::getHost();
		}

		$scheme = Context::getCurrent()->getRequest()->isHttps() ? 'https' : 'http';

		return $scheme . '://' . Context::getCurrent()->getServer()->getServerName();
	}

	public static function makeAbsoluteUrl(string $relativeUrl, string $domain = ''): string
	{
		return static::getBaseUrl($domain) . '/' . ltrim($relativeUrl, '/');
	}

	public static function makeDefaultAbsoluteUrl(string $relativeUrl): string
	{
		return static::concat('https://www' . GeoLocation::getParentDomain(), $relativeUrl);
	}

	public static function makeAbsoluteUriWithParams(string $relativeUrl, string $domain, array $queryParams = []): RusgeocomUri
	{
		$uri = new RusgeocomUri(static::getBaseUrl($domain) . '/' . ltrim($relativeUrl, '/'));

		if ($queryParams) {
			$uri->addParams($queryParams);
		}

		return $uri;
	}

	public static function getCurrentAbsoluteUrl()
	{
		return static::makeAbsoluteUrl($_SERVER['REQUEST_URI']);
	}

	public static function getRefererUrl(): string
	{
		return $_SERVER['HTTP_REFERER'] ?: static::getBaseUrl();
	}

	public static function getCanonical(): string
	{
		return static::getCanonicalForUrl($_SERVER['REQUEST_URI']);
	}

	public static function getCanonicalForUrl(string $url): string
	{
		$parsedUri = new Uri($url);
		$url = static::getBaseUrl();

		if (in_array(static::$page, [static::CANONICAL_SECTION, static::CANONICAL_PAGINATION, static::CANONICAL_FILTER_WITH_LINK])) {
			$url .= $parsedUri->getPath();
		} elseif (static::$page == static::CANONICAL_FILTER) {
			$url .= static::eraseFilterPart($parsedUri->getPath());
		} else {
			$url .= $parsedUri->getPath();
		}

		// Убираем всё после амперсанда
		if (strpos($url, '&') !== false){
			$url = explode('&', $url)[0];
		}

		$url = trim(urldecode($url));
		$url = static::removeEndSubstring($url, '/f');
		$url = static::removeEndSubstring($url, '/f/');

		return $url;
	}

	public static function removeEndSubstring(string $url, string $substring): string
	{
		if (strpos($url, '/f') === strlen($url) - 2){
			$url = str_replace('/f', '', $url);
		}

		return $url;
	}

	public static function needCanonical(): bool
	{
		$page = static::$page;

		if (static::isPaginationPage()) {
			$page = static::CANONICAL_PAGINATION;
		}

		if (static::isFilterPage()) {
			$uri = (new Uri($_SERVER['REQUEST_URI']))->deleteParams(['clear_cache'])->getPath();
			$page = SeoUtils::haveLinkForUri($uri) ? static::CANONICAL_FILTER_WITH_LINK : static::CANONICAL_FILTER;
		}

		if(!$page) {
			$page = static::getCanonical();
		}

		static::setPage($page);

		return (bool)static::$page;
	}

	/**
	 * Удаляет GET-параметр из URL.
	 * Оставлен для обратной совместимости.
	 *
	 * @param $url
	 * @param $name
	 * @param bool $amp
	 * @return mixed|string
	 */
	public static function deleteGet($url, $name, $amp = true) {

		// Заменяем сущности на амперсанд, если требуется
		$url = str_replace("&amp;", "&", $url);

		$uri = new \Bitrix\Main\Web\Uri($url);
		$uri->deleteParams([$name]);
		$url = $uri->getUri();

		// Заменяем амперсанды обратно на сущности, если требуется
		if ($amp) {
			$url = str_replace("&", "&amp;", $url);
		}

		return $url;
	}

	/**
	 * @return bool
	 */
	public static function isPaginationPage(): bool
	{
		foreach (Context::getCurrent()->getRequest()->getQueryList() as $key => $val) {
			if (strpos($key, 'PAGEN_') !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return bool
	 */
	public static function isFilterPage(string $url = ''): bool
	{
		if (!$url){
			$url = $_SERVER['REQUEST_URI'];
		}

		return strpos($url, '/f/') || strpos($url, '/f?') || strpos($url, 'price_from=') || strpos($url, 'price_to=');
	}

	/**
	 * @return bool
	 */
	public static function isSeoFilterPage(string $url = ''): bool
	{
		if (!$url){
			$url = $_SERVER['REQUEST_URI'];
		}

		return strpos($url, '/f/');
	}

	/**
	 * @param $link
	 *
	 * @return false|mixed|string
	 */
	protected static function eraseFilterPart($link)
	{
		$filterIdentifier = strpos($link,'/f/');
		$filterIdentifier = $filterIdentifier !== false ? '/f/' : '/f';

		return stristr($link, $filterIdentifier, true) ?: $link;
	}

	public static function setPage($page)
	{
		static::$page = $page;
	}

	public static function showCanonical()
	{
		global $APPLICATION;
		if (static::needCanonical() && $APPLICATION->getPageProperty('canonical') != 'empty') {
			Asset::getInstance()->addString('<link rel="canonical" href="' . Url::getCanonical() . '">');
		} else {
			$APPLICATION->SetPageProperty('canonical', '');
		}
	}

	public static function clearAllGetParams($url)
	{
		foreach ($_GET as $param => $value) {
			$url = static::deleteGet($url, $param);
		}

		return $url;
	}

	public static function hasGetParams(string $url = ''): bool
	{
		return str_contains($url, '?');
	}

	public static function clearUtmGetParams($url)
	{
		foreach ($_GET as $param => $value) {
			if (strpos($param, 'utm_') === 0 || $param == 'yclid' || $param == 'gclid'){
				$url = static::deleteGet($url, $param);
			}
		}

		return $url;
	}

	public static function concat(string ...$parts): string
	{
		if (!$parts) {
            return '';
        }

        $result = $parts[0];
        $count = count($parts);
        for ($i = 1; $i < $count; $i++) {
			$result = rtrim($result, '/') . '/' . ltrim($parts[$i], '/');
		}

        return $result;
	}

	public static function formatSlash(string $url): string
	{
		if (!$url || static::isAbsoluteUrl($url)){
			return $url;
		}

		return '/' . ltrim($url, '/');
	}

	public static function isAbsoluteUrl(string $url): bool
	{
		return strpos($url, 'http') === 0;
	}

	public static function tryToEraseEqual($url): string
	{
		return ($url && substr($url, -1) == '=') ? substr($url, 0, -1) : $url;
	}

	public static function redirect301(string $url): void
	{
		LocalRedirect($url, true, '301 Moved Permanently');
	}
}