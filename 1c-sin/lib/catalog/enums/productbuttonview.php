<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

/**
 * Значения свойства BTN_VIEW
 */
enum ProductButtonView: string
{
	case Unspecified = 'unspecified';
	case Buy = 'buy';
	case Request = 'request';
	case Consultation = 'consultation';

	public static function fromNameOrDefault(string $name): self
	{
		return match ($name) {
			'В корзину' => self::Buy,
			'Заказать' => self::Request,
			'Получить консультацию' => self::Consultation,
			default => self::Unspecified,
		};
	}
}
