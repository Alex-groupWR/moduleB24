<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

enum ReviewSource : string
{
	case Site = 'site';
	case Yandex = 'yandex';
	case Ozon = 'ozon';

	public static function fromBxProp(string $xmlId): self
	{
		return match ($xmlId) {
			'Y' => self::Yandex,
			'ozon' => self::Ozon,
			default => self::Site,
		};
	}
}