<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Tools\Log;

class LoggerFactory
{
	public static function get(string $targetClass): LoggerInterface
	{
		return new FileLogger($targetClass);
	}
}