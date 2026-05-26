<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Tools\Log;

use Bitrix\Main\Application;
use Illuminate\Support\Str;

class FileLogger extends AbstractLogger
{
	public const string ROTATE_BY_SIZE = 'size';
	public const string ROTATE_BY_MONTH = 'month';
	public const int MAX_MONTHS = 6;

	private string $targetClass;
	private string $logDir = '/log/';
	private string $logFileType = '.log';
	private string $oldLogFileEnd = '_old';
	private int $logFileMaxSize = 10485760; // 10МБ
	private string $rotateType = self::ROTATE_BY_SIZE;

	public function log(string $level, string $message, array $context = []): void
	{
		$path = explode('\\', $this->targetClass);
		$logFileName = end($path) . '_' . $level;
		unset($path[key($path)]);
		$logFileDir = Application::getDocumentRoot() . $this->logDir . implode('/', $path);
		if (!is_dir($logFileDir) && !mkdir($logFileDir, 0777, true)) {
			throw new \RuntimeException(sprintf('Directory "%s" was not created', $logFileDir));
		}

		if ($this->getRotateType() === self::ROTATE_BY_MONTH) {
			$logFileName .= '_' . date('Y-m');
		}

		$logFile = $logFileDir . '/' . $logFileName . $this->logFileType;
		$this->rotateLogsIfNeeded($logFile, $logFileDir, $logFileName);
		file_put_contents(
			$logFile,
			date('[d.m.Y H:i:s]:') . $message .
			($context ? ' | context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '') . "\n",
			FILE_APPEND
		);
	}

	public function setRotateType(string $type): AbstractLogger
	{
		$this->rotateType = $type;
		return $this;
	}

	public function getRotateType(): string
	{
		return $this->rotateType;
	}

	private function rotateLogsIfNeeded(string $logFile, string $path, string $logName): void
	{
		match ($this->getRotateType()) {
			self::ROTATE_BY_MONTH => $this->rotateByMonths($path, $logName),
			default => $this->rotateBySize($logFile, $path, $logName)
		};
	}

	private function rotateBySize(string $logFile, string $path, string $logName): void
	{
		if (file_exists($logFile) && filesize($logFile) > $this->logFileMaxSize) {
			rename($logFile, $path . '/' . $logName . $this->oldLogFileEnd . $this->logFileType);
		}
	}

	private function rotateByMonths(string $path, string $logName): void
	{
		$searchName = Str::beforeLast($logName, '_');
		$firstAllowedDate = strtotime('-' . self::MAX_MONTHS . ' months');
		foreach (glob($path . '/' . $searchName . '*') as $logFile) {
			if (is_file($logFile) && filemtime($logFile) < $firstAllowedDate) {
				unlink($logFile);
			}
		}
	}

	public function __construct(string $targetClass)
	{
		$this->targetClass = $targetClass;
	}
}