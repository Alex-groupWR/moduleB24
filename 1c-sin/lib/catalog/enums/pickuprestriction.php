<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

enum PickupRestriction
{
	case None;
	case NotMoscow;
	case All;

	public static function getByXmlId(string $xmlId): self
	{
		return match ($xmlId) {
			'Y' => self::NotMoscow,
			'Y_STRICT' => self::All,
			default => self::None,
		};
	}
}
