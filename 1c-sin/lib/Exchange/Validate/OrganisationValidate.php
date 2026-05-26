<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;


class OrganisationValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'name', 'markDelete', 'INN', 'KPP'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}