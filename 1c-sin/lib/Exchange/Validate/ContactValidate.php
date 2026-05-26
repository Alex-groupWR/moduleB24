<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;


class ContactValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'fio', 'markDelete', 'phones', 'emails', 'manage_id', 'company_id'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}