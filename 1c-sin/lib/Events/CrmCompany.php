<?php

namespace Rusgeocom\Rusgeocom\Events;

use Rusgeocom\Rusgeocom\Exchange\Messages\MessageProcessor;
use Rusgeocom\Rusgeocom\Exchange\Services\KontragentService;

class CrmCompany
{
    const ACTION = 'Kontragent';

    public static function onAfterCrmCompanyAdd(array $fields): bool
    {
        if (!KontragentService::isSyncFromOneC()) {
            MessageProcessor::processRequest(self::ACTION, $fields);
        }
        return true;
    }


    public static function onAfterCrmCompanyUpdate(array $fields): bool
    {
        if (!KontragentService::isSyncFromOneC()) {
            MessageProcessor::processRequest(self::ACTION, $fields);
        }
        return true;
    }


}