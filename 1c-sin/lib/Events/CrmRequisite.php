<?php
// lib/Events/CrmRequisite.php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Events;

use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\Entity\Event;
use Bitrix\Main\Entity\EventResult;
use Bitrix\Main\Entity\EntityError;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class CrmRequisite
{

    public static function onBeforeDelete(Event $event): EventResult
    {
        $result = new EventResult();

        $primary = $event->getParameter('id') ?? $event->getParameter('primary') ?? [];
        $id = (int)($primary['ID'] ?? $primary ?? 0);
        if ($id <= 0) {
            return $result;
        }

        $requisite = (new EntityRequisite())->getList([
            'filter' => ['=ID' => $id],
            'select' => ['ID', 'XML_ID', 'RQ_COMPANY_NAME'],
            'limit' => 1,
        ])->fetch();

        $guid = (string)($requisite['XML_ID'] ?? '');
        if (!DeleteGuard::isLinkedToOneC($guid)) {
            return $result;
        }

        (new EntityRequisite())->update($id, [
            KontragentBuilder::REQUISITE_MARK_DELETE_FIELD => true,
        ]);

        $reason = sprintf(
            'Контрагент "%s" синхронизирован с 1С (GUID: %s) — физическое удаление запрещено. Реквизит помечен на удаление (markDelete).',
            $requisite['RQ_COMPANY_NAME'] ?? $id,
            $guid
        );

        DeleteGuard::pushNotifyPublic($reason); // см. ниже

        $result->addError(new EntityError($reason));

        return $result;
    }
}