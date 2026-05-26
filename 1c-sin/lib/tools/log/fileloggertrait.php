<?php

namespace Rusgeocom\Rusgeocom\Tools\Log;

trait FileLoggerTrait
{
	use LoggerTrait;

	public function log($level, $message, array $context = array()): void
	{
		LoggerFactory::get(static::class)->log($level, $message, $context);
	}
}