<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Tools\Log;

use Throwable;

interface LoggerInterface
{
	public function log(string $level, string $message, array $context = array()): void;
	public function info(string $message, array $context = []): void;
	public function error(string $message, array $context = []): void;
	public function warning(string $message, array $context = []): void;
	public function exception(Throwable $exc, string $message, array $context = []): void;
}
