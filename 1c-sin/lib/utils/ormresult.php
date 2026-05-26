<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\ORM\Data\AddResult;
use Bitrix\Main\ORM\Data\Result;
use Exception;

class OrmResult
{
	public static function ensureSuccess(Result $result): void
	{
		if (!$result->isSuccess()) {
			throw new Exception(implode('. ', $result->getErrorMessages()));
		}
	}

	public static function ensureSuccessAndGetId(AddResult $result): int
	{
		static::ensureSuccess($result);

		return $result->getId();
	}
}