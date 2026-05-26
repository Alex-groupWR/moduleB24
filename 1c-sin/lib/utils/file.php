<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Application;

class File
{
	public static function executeGetContent(string $filePath): string
	{
		global $APPLICATION;
		ob_start();
		include $_SERVER['DOCUMENT_ROOT'] . $filePath;
		return ob_get_clean() ?: '';
	}

	public static function getFilesFromDir(string $dirPth): array
	{
		return array_diff(scandir($dirPth), ['.', '..']);
	}

	public static function getFilesFromDirByModificationTime(string $dirPth): array
	{
		$files = [];
		foreach (static::getFilesFromDir($dirPth) as $file) {
			$path = $dirPth . '/' . $file;
			if (is_dir($path)) {
				continue;
			}

			$files[$file] = filemtime($path);
		}
		arsort($files);

		return array_keys($files);
	}

	public static function getAbsolutePath(string $relativePath): string
	{
		return $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($relativePath, '/');
	}

	public static function saveFromBase64(string $fileName, string $content, string $uploadDir): string
	{
		$tempFilename = tempnam(sys_get_temp_dir(), 'php');
		file_put_contents($tempFilename, base64_decode($content));

		$hashedName = md5($fileName);
		$path = $uploadDir . substr($hashedName, 0, 2) . '/' . substr($hashedName, 2, 2) . '/';

		$realPath = Application::getDocumentRoot() . $path;
		if (!is_dir($realPath)) {
			mkdir($realPath, 0750, true);
		}
		rename($tempFilename, $realPath . $fileName);

		return $path . $fileName;
	}
}