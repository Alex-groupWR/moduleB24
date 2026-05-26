<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;


class ProductValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'name', 'markDelete', 'isGroup'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}