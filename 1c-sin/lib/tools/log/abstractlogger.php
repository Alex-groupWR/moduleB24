<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Tools\Log;

use Bitrix\Main\Application;
use Throwable;

abstract class AbstractLogger implements LoggerInterface
{
	public abstract function log(string $level, string $message, array $context = array()): void;

	public function info(string $message, array $context = []): void
	{
		$this->log(LogLevel::INFO, $message, $context);
	}

	public function debug(string $message, array $context = []): void
	{
		$this->log(LogLevel::DEBUG, $message, $context);
	}

	public function error(string $message, array $context = []): void
	{
		$this->log(LogLevel::ERROR, $message, $context);
	}

	public function warning(string $message, array $context = []): void
	{
		$this->log(LogLevel::WARNING, $message, $context);
	}

	public function exception(Throwable $exc, string $message, array $context = []): void
	{
		$context['exception'] = [
			'message' => $exc->getMessage(),
			'file' => $exc->getFile() . ':' . $exc->getLine(),
			'trace' => $exc->getTrace(),
		];

		$this->error($message, $context);
	}
}