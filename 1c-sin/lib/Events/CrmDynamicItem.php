<?php
// lib/Events/CrmDynamicItem.php

namespace Rusgeocom\Rusgeocom\Events;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\ORM\EntityError;
use Bitrix\Crm\Item;
use Rusgeocom\Rusgeocom\Exchange\DeleteGuard\DeleteGuard;

class CrmDynamicItem
{
    public static function onBeforeCrmDynamicItemDelete(int $entityTypeId, int $id): bool
    {
        return !DeleteGuard::guard($entityTypeId, $id);
    }
}
