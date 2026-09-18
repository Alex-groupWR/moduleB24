<?php
// lib/Exchange/DeleteGuard/DeleteGuard.php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\DeleteGuard;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

/**
 * Общая логика: не даём физически удалить сущность, если она синхронизирована
 * с 1С (заполнен guid), а вместо этого помечаем markDelete = true через
 * стандартную Factory Update Operation — так же, как это делает обычный update(),
 * поэтому не ломает исходящую синхронизацию с 1С.
 */
class DeleteGuard
{
    private const NOTIFY_MODULE = 'rusgeocom.rusgeocom';
    private const NOTIFY_EVENT = 'delete_blocked_by_1c';

    /**
     * @return bool true — удаление заблокировано (элемент помечен markDelete),
     *              false — сущность не под управлением реестра или не синхронизирована,
     *              штатное удаление разрешено.
     */
    public static function guard(int $entityTypeId, int $id): bool
    {
        Loader::includeModule('crm');

        $config = DeleteGuardRegistry::getConfig($entityTypeId);
        if (!$config || $id <= 0) {
            return false;
        }

        $factory = Container::getInstance()->getFactory($entityTypeId);
        if (!$factory) {
            return false;
        }

        $item = $factory->getItem($id);
        if (!$item) {
            return false;
        }

        $guid = (string)$item->get($config['guidField']);
        if (!self::isLinkedToOneC($guid)) {
            return false;
        }

        $marked = self::markAsDeleted($factory, $item, $config['markDeleteField']);

        $reason = sprintf(
            '%s "%s" синхронизирован(а) с 1С (GUID: %s) — физическое удаление запрещено. %s',
            $config['label'],
            (string)($item->get('TITLE') ?? $item->getId()),
            $guid,
            $marked ? 'Элемент помечен на удаление (markDelete).' : 'Не удалось пометить элемент, обратитесь к администратору.'
        );

        self::pushNotify($reason);

        LoggerFactory::get(static::class)->warning('Попытка физического удаления заблокирована', [
            'entityTypeId' => $entityTypeId,
            'id' => $id,
            'guid' => $guid,
            'marked' => $marked,
        ]);

        self::throwUiError('Удаление запрещено: элемент синхронизирован с 1С.');

        return true;
    }

    public static function isLinkedToOneC(?string $guid): bool
    {
        return !empty($guid) && $guid !== '00000000-0000-0000-0000-000000000000';
    }

    private static function markAsDeleted($factory, $item, string $markDeleteField): bool
    {
        if (!$item->hasField($markDeleteField)) {
            return false;
        }

        $item->set($markDeleteField, true);

        $result = $factory->getUpdateOperation($item)->disableCheckAccess()->launch();

        return $result->isSuccess();
    }

    /**
     * Пуш-уведомление тому, кто пытался удалить элемент (через модуль "Живая лента"/im).
     * Если модуль im не установлен или нет текущего пользователя (CLI/крон) — просто пропускаем.
     */
    private static function pushNotify(string $message): void
    {
        if (!Loader::includeModule('im')) {
            return;
        }

        $userId = (int)(CurrentUser::get()->getId() ?? 0);
        if ($userId <= 0) {
            return;
        }

        \CIMNotify::Add([
            'TO_USER_ID' => $userId,
            'FROM_USER_ID' => 0, // от имени системы
            'NOTIFY_TYPE' => IM_NOTIFY_SYSTEM,
            'NOTIFY_MODULE' => self::NOTIFY_MODULE,
            'NOTIFY_EVENT' => self::NOTIFY_EVENT,
            'NOTIFY_MESSAGE' => $message,
            'NOTIFY_MESSAGE_OUT' => $message,
        ]);
    }

    private static function throwUiError(string $message): void
    {
        global $APPLICATION;
        if ($APPLICATION) {
            $APPLICATION->ThrowException($message);
        }
    }
}