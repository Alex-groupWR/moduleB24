<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Utils;

use InvalidArgumentException;

class Uuid
{
	public static function create(): string
	{
		return \Ramsey\Uuid\Uuid::uuid4()->toString();
	}

	public static function parse(string $value): string
	{
		if (!\Ramsey\Uuid\Uuid::isValid($value)) {
			throw new InvalidArgumentException('UUID is not valid');
		}

		return $value;
	}
}