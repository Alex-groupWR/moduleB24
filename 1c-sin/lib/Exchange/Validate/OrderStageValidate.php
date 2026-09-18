<?php
namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class OrderStageValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'stage', 'category'];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}