<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;


class AgreementValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'name', 'markDelete'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}