<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

enum Reaction: string
{
	case Liked = 'liked';
	case Disliked = 'disliked';
	case Unliked = 'unliked';

	public static function getActiveList(): array
	{
		return [
			self::Liked,
			self::Disliked,
		];
	}
}
