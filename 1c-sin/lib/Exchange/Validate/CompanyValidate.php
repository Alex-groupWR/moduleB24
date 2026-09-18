<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class CompanyValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'companyName', 'markDelete'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}