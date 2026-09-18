<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Events;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class DeleteGuard
{
    public static function isLinkedToOneC(?string $guid): bool
    {
        return !empty($guid) && $guid !== '00000000-0000-0000-0000-000000000000';
    }

    public static function markAsDeleted(int $entityTypeId, int $id, string $markDeleteField): bool
    {
        Loader::includeModule('crm');

        $logger = LoggerFactory::get(static::class);

        $factory = Container::getInstance()->getFactory($entityTypeId);
        if (!$factory) {
            $logger->error('Фабрика не найдена при попытке пометить на удаление', [
                'entityTypeId' => $entityTypeId,
                'id' => $id,
            ]);
            return false;
        }

        $item = $factory->getItem($id);
        if (!$item) {
            return false;
        }

        if (!$item->hasField($markDeleteField)) {
            $logger->error('Поле markDelete отсутствует у элемента', [
                'entityTypeId' => $entityTypeId,
                'id' => $id,
                'field' => $markDeleteField,
            ]);
            return false;
        }

        $item->set($markDeleteField, true);

        $result = $factory->getUpdateOperation($item)->disableCheckAccess()->launch();

        if (!$result->isSuccess()) {
            $logger->error('Не удалось пометить элемент на удаление вместо физического удаления', [
                'entityTypeId' => $entityTypeId,
                'id' => $id,
                'errors' => $result->getErrorMessages(),
            ]);
            return false;
        }

        $logger->info('Физическое удаление заблокировано, элемент помечен markDelete=true', [
            'entityTypeId' => $entityTypeId,
            'id' => $id,
        ]);

        return true;
    }
}