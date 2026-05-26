<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestOneC;

class RequestOneCFactory
{
	public static function getByAction(string $action): RequestOneCInterface
	{
		$map = [
			'Kontragent' => CreateRequestKontragent::class,
		];

		return isset($map[$action])
			? new $map[$action]()
			: new SameModifier();
	}
}