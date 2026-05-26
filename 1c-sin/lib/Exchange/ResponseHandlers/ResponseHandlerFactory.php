<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Rusgeocom\Rusgeocom\Exchange\ResponseHandlers\Discounts\GetDiscountResponseHandler;
use Rusgeocom\Rusgeocom\Exchange\ResponseHandlers\Discounts\GetDiscountValuesResponseHandler;

class ResponseHandlerFactory
{
	public static function getByAction(string $action): ResponseHandlerInterface
	{
		$map = [
			'ping' => PingResponseHandler::class,
			'Kontragent' => KontragentResponseHandler::class,
		];

		$class = Arr::first(
			$map,
			fn(string $className, string $methodName) => Str::lower($action) === Str::lower($methodName)
		);
		if (!$class) {
			return new EmptyResponseHandler();
		}

		return new $class();
	}
}