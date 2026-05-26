<?php
namespace Rusgeocom\Rusgeocom\Utils;

use CHTTP;
use Rusgeocom\Rusgeocom\Types\Uri;

class UrlRewrite
{
	public static function resolveUrl(string $requestUri)
	{
		$io = \CBXVirtualIo::GetInstance();
		$arUrlRewrite = array();
		if(file_exists($_SERVER['DOCUMENT_ROOT']."/urlrewrite.php"))
			include($_SERVER['DOCUMENT_ROOT']."/urlrewrite.php");

		if (!CHTTP::isPathTraversalUri($_SERVER["REQUEST_URI"]))
		{
			foreach($arUrlRewrite as $val)
			{
				if(preg_match($val["CONDITION"], $requestUri))
				{
					if (strlen($val["RULE"]) > 0)
						$url = preg_replace($val["CONDITION"], (strlen($val["PATH"]) > 0 ? $val["PATH"]."?" : "").$val["RULE"], $requestUri);
					else
						$url = $val["PATH"];

					$val['RESULT_URL'] = $url;

					if(($pos=strpos($url, "?"))!==false)
					{
						$params = substr($url, $pos+1);
						parse_str($params, $vars);
						unset($vars["SEF_APPLICATION_CUR_PAGE_URL"]);

						$_GET += $vars;
						$_REQUEST += $vars;
						$_SERVER["QUERY_STRING"] = $QUERY_STRING = CHTTP::urnEncode($params);
						$url = substr($url, 0, $pos);
					}

					$url = _normalizePath($url);

					if (!$io->ValidatePathString($url))
						continue;

					$urlTmp = strtolower(ltrim($url, "/\\"));
					$urlTmp = str_replace(".", "", $urlTmp);
					$urlTmp7 = substr($urlTmp, 0, 7);

					if (($urlTmp7 == "upload/" || ($urlTmp7 == "bitrix/" && substr($urlTmp, 0, 16) != "bitrix/services/" && substr($urlTmp, 0, 18) != "bitrix/groupdavphp")))
						continue;

					$ext = strtolower(GetFileExtension($url));
					if ($ext != "php")
						continue;

					$val['RESULT_VALUES'] = static::makeParamsFromRule($val['RULE'] ?: '', $val['RESULT_URL']);

					return $val;
				}
			}
		}

		return [];
	}

	private static function makeParamsFromRule(string $rule, string $url): array
	{
		if (!$rule){
			return [];
		}

		// Битрикс втыкает в начало свои параметры, поэтому посреди строки могут быть вопросы
		if (strpos($url, '?') !== false){
			$splittedUrl = explode('?', $url);
			$path = $splittedUrl[0];
			$queryString = str_replace($path . '?', '', $url);
			$queryString = str_replace('?', '&', $queryString);
			$url = $path . '?' . $queryString;
		}
		$uri = new Uri($url);

		$params = [];
		$queryParts = explode('&', $rule);
		foreach ($queryParts as $part){
			$splittedPart = explode('=', $part);
			if (count($splittedPart) === 2){
				$params[$splittedPart[0]] = $uri->getParam($splittedPart[0]) ?: $splittedPart[1];
			}
		}

		return $params;
	}
}