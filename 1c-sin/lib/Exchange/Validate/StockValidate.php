<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class StockValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'warehouse', 'quantity'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}