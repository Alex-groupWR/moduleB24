<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

abstract class BaseValidator
{
    use ExchangeHelperTrait;


    public static function validate(array $data, array $requiredKeys): array
    {
        $missing = array_diff($requiredKeys, array_keys($data));

        if (!is_string($data[ExchangeProtocol::GUID])) {
            return self::errorResult(
                'GUID ERROR',
                'Переданный внешний ключ не является строкой',
                $data[ExchangeProtocol::GUID] ?? 'undefined'
            );
        }

        if (!empty($missing)) {
            return self::errorResult(
                'PARAMS ERROR',
                'Не передан обязательный параметр ' . implode(', ', $missing),
                $data[ExchangeProtocol::GUID]
            );
        }

        return [];
    }
}