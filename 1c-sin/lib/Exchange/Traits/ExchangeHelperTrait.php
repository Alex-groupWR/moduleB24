<?php

namespace Rusgeocom\Rusgeocom\Exchange\Traits;

use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;

trait ExchangeHelperTrait
{
    protected static function errorResult(string $statusError, string $msg, string $guid): array
    {
        return [
            ExchangeProtocol::KEY_ERROR => $statusError,
            ExchangeProtocol::KEY_ERROR_TEXT => $msg,
            ExchangeProtocol::GUID => $guid,
        ];
    }

    protected static function successResult(int $b24Id,  string $guid, string $status): array
    {
        return [
            ExchangeProtocol::B24ID => $b24Id,
            ExchangeProtocol::GUID => $guid,
            ExchangeProtocol::STATUS => $status,
        ];
    }


    protected static function getEnumValueId(string $fieldName, string $value): ?int
    {
        $res = \CUserFieldEnum::GetList([], [
            'USER_FIELD_NAME' => $fieldName,
            'VALUE' => $value
        ]);
        return ($enum = $res->Fetch()) ? (int)$enum['ID'] : null;
    }

}