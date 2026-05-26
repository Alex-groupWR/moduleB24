<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Utils;

use \Bitrix\Main\Config\Option as BxOption;

class Option
{
	private const MODULE_ID = 'rusgeocom.rusgeocom';

	public static function set(string $key, string $value): void
	{
		BxOption::set(static::MODULE_ID, $key, $value);
	}

	public static function unset(string $key): void
	{
		BxOption::set(static::MODULE_ID, $key, '');
	}

	public static function get(string $key, string $default = ''): string
	{
		return BxOption::get(static::MODULE_ID, $key, $default);
	}
}