<?php

namespace Rusgeocom\Rusgeocom\Events;

use Bitrix\Crm\CompanyTable;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Messages\MessageProcessor;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Exchange\Services\KontragentService;
use Rusgeocom\Rusgeocom\Exchange\DeleteGuard\DeleteGuard;

class CrmCompany
{
    const ACTION = 'Kontragent';

    public static function onAfterCrmCompanyAdd(array $fields): bool
    {
        if ($fields['ASSIGNED_BY_ID'] != 1){
           return true;
        }

        if (!KontragentService::isSyncFromOneC()) {
            MessageProcessor::processRequest(self::ACTION, $fields);
        }
        return true;
    }

    public static function onAfterCrmCompanyUpdate(array $fields): bool
    {
        if ($fields['ASSIGNED_BY_ID'] != 1){
            return true;
        }

        if (!KontragentService::isSyncFromOneC()) {
            MessageProcessor::processRequest(self::ACTION, $fields);
        }
        return true;
    }

    public static function onBeforeCrmCompanyDelete($id): bool
    {
        if (KontragentService::isSyncFromOneC()) {
            return true; // удаление инициировано самой синхронизацией — не блокируем
        }

        return !DeleteGuard::guard(CCrmOwnerType::Company, (int)$id);
    }
}