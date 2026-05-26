<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Messages;

use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Rusgeocom\Rusgeocom\Utils\OrmResult;

class MessageQueue
{
    private static array $messageHashesBySync = [];

    public static function getNewMessages(int $count): array
    {
        return MessageTable::query()
            ->setSelect(static::getMessageSelect())
            ->where('STATUS', MessageTable::STATUS_NEW)
            ->addOrder('DATE_INSERT', 'ASC')
            ->setLimit($count)
            ->exec()
            ->fetchAll();
    }

    public static function getExchangeFailMessages(int $count, int $maxTryCount, int $tryIntervalMinutes): array
    {
        return MessageTable::query()
            ->setSelect(static::getMessageSelect())
            ->where('STATUS', MessageTable::STATUS_EXCHANGE_FAIL)
            ->where('TRY_COUNT', '<=', $maxTryCount)
            ->where('DATE_SENT', '<', DateTime::createFromTimestamp(time() - $tryIntervalMinutes * 60))
            ->addOrder('TRY_COUNT', 'ASC')
            ->addOrder('DATE_INSERT', 'ASC')
            ->setLimit($count)
            ->exec()
            ->fetchAll();
    }

    public static function getHandleFailMessages(int $count): array
    {
        return MessageTable::query()
            ->setSelect(static::getMessageSelect())
            ->where('STATUS', MessageTable::STATUS_HANDLE_FAIL)
            ->addOrder('DATE_INSERT', 'ASC')
            ->setLimit($count)
            ->exec()
            ->fetchAll();
    }

    public static function add(string $action, array $payload, $syncId = ''): void
    {
        $convertedPayload = Json::encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $payloadHash = static::getPayloadHash($convertedPayload);

        if (static::isAlreadySent($syncId, $payloadHash)) {

            return;
        }


        $fields = [
            'STATUS' => MessageTable::STATUS_NEW,
            'ACTION' => $action,
            'PAYLOAD' => $convertedPayload,
        ];
        if ($syncId) {
            $fields['SYNC_ID'] = $syncId;
        }


        $result = MessageTable::add($fields);

        OrmResult::ensureSuccess($result);

        static::updateMessageHashForSync($syncId, $payloadHash);

        // Отменяем предыдущие сообщения с тем же SYNC_ID, если они ещё не обработаны
        if ($syncId) {
            $iterator = MessageTable::query()
                ->addSelect('ID')
                ->where('SYNC_ID', $syncId) // Старые отменяем
                ->whereNot('ID', $result->getId()) // Новый оставляем
                ->whereNotIn('STATUS', [
                    MessageTable::STATUS_HANDLE_SUCCESS,
                    MessageTable::STATUS_CANCEL
                ]) // Успешные и отменённые не трогаем
                ->exec();
            while ($row = $iterator->fetch()) {
                MessageTable::update($row['ID'], ['STATUS' => MessageTable::STATUS_CANCEL]);
            }
        }
    }

    /**
     * Проверяет отправлялось ли сообщение с абсолютно таким-же содержимым в рамках текущего сеанса, либо ранее
     *
     * @param string $syncId
     * @param string $payloadHash
     * @return bool
     */
    private static function isAlreadySent(string $syncId, string $payloadHash): bool
    {
        if (!$syncId) {
            return true;
        }

        return $payloadHash === static::getLastMessageHash($syncId);
    }

    private static function getLastMessageHash(string $syncId): string
    {
        if (!isset(static::$messageHashesBySync[$syncId])) {
            $lastPayload = MessageTable::query()
                ->addSelect('PAYLOAD')
                ->where('SYNC_ID', $syncId)
                ->setOrder(['ID' => 'DESC'])
                ->setLimit(1)
                ->exec()
                ->fetch()['PAYLOAD'] ?? '';

            static::$messageHashesBySync[$syncId] = static::getPayloadHash($lastPayload);
        }

        return static::$messageHashesBySync[$syncId];
    }

    private static function getPayloadHash(string $payloadJson): string
    {
        return md5($payloadJson);
    }

    private static function updateMessageHashForSync(string $syncId, string $payloadHash): void
    {
        if (!$syncId) {
            return;
        }

        static::$messageHashesBySync[$syncId] = $payloadHash;
    }

    public static function saveExchangeResult(array $message, bool $isSuccess, array $response): void
    {
        $fields = [
            'TRY_COUNT' => $message['TRY_COUNT'] + 1,
            'STATUS' => $isSuccess ? MessageTable::STATUS_EXCHANGE_SUCCESS : MessageTable::STATUS_EXCHANGE_FAIL,
            'DATE_SENT' => new DateTime(),
        ];

        if ($response) {
            if (isset($response['files'])) {
                foreach ($response['files'] as $key => $file) {
                    if (isset($file['data'])) {
                        $response['files'][$key]['data'] = 'deleted_base64';
                    }
                }
            }
            $fields['RESPONSE'] = Json::encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $lastMessageId = self::getLastMessageId($message['ID']);
        $result = MessageTable::update($lastMessageId, $fields);
        OrmResult::ensureSuccess($result);
    }

    public static function saveHandleResult(array $message, bool $isSuccess, array $result = []): void
    {
        $fields = [
            'STATUS' => $isSuccess ? MessageTable::STATUS_HANDLE_SUCCESS : MessageTable::STATUS_HANDLE_FAIL,
            'DATE_HANDLE' => new DateTime(),
        ];

        if ($result) {
            $fields['RESULT'] = Json::encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $result = MessageTable::update($message['ID'], $fields);
        OrmResult::ensureSuccess($result);
    }

    private static function getMessageSelect(): array
    {
        return [
            'PAYLOAD',
            'ACTION',
            'ID',
            'TRY_COUNT',
        ];
    }

    private static function getLastMessageId(int $syncId): string
    {
        return MessageTable::query()
        ->setSelect(static::getMessageSelect())
        ->where('STATUS', MessageTable::STATUS_NEW)
        ->where('SYNC_ID', $syncId)
        ->addOrder('DATE_INSERT', 'ASC')
        ->exec()
        ->fetch()['ID'];
    }

    public static function deleteOldMessages(int $count, int $olderThanDays): void
    {
        $iterator = MessageTable::query()
            ->addSelect('ID')
            ->whereIn('STATUS', [MessageTable::STATUS_HANDLE_SUCCESS, MessageTable::STATUS_CANCEL])
            ->where('DATE_HANDLE', '<', DateTime::createFromTimestamp(time() - $olderThanDays * 24 * 60 * 60))
            ->addOrder('DATE_HANDLE', 'ASC')
            ->setLimit($count)
            ->exec();
        while ($row = $iterator->fetch()) {
            MessageTable::delete($row['ID']);
        }
    }
}