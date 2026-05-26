<?php

namespace Rusgeocom\Rusgeocom\Utils;

use CEventLog;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class Logger
{
	/**
	 * @deprecated Use LoggerFactory::get(static::class)
	 */
    public static function write(string $message, string $auditType, string $level = CEventLog::SEVERITY_INFO)
    {
		LoggerFactory::get('Deprecated\\' . $auditType)->log(strtolower($level), $message);
    }
}