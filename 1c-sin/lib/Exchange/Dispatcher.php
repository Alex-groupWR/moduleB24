<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange;

use Exception;
use Rusgeocom\Rusgeocom\Exchange\Auth\AuthService;
use Rusgeocom\Rusgeocom\Exchange\RequestHandlers\RequestHandlerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;
use Bitrix\Main\Error;
use Throwable;

class Dispatcher
{
    private static ?LoggerInterface $logger = null;

    public static function handlePacket(array $packet): array
    {
        try {
            if (ExchangeConfig::isDebug()) {
                static::log($packet);
            }

            static::checkAuth($packet);

            $action = static::requireStringParameter($packet, ExchangeProtocol::KEY_ACTION);

            if ($action === ExchangeProtocol::ACTION_LOGIN) {
                return static::login($packet);
            }

            if ($action === ExchangeProtocol::ACTION_SYNC_PACKET) {
                $results = [];
                foreach ($packet[ExchangeProtocol::KEY_PARAMS] as $param) {
                    $entity = $param[ExchangeProtocol::ENTITY_TYPE];
                    $actionClass = RequestHandlerFactory::getByAction($entity);

                    $entityResults = [];

                    if (!$actionClass) {
                        $entityResults[] = [
                            ExchangeProtocol::KEY_ERROR => 'ENTITY NOT FOUND',
                            ExchangeProtocol::KEY_ERROR_TEXT => 'Сущность не поддерживается',
                            ExchangeProtocol::GUID => $param[ExchangeProtocol::GUID],
                        ];
                    } else {
                        foreach ($param[ExchangeProtocol::ITEM] as $item) {
                            $entityResults[] = $actionClass->handle($item ?? []);
                        }
                    }

                    $results[] = [
                        ExchangeProtocol::ENTITY_TYPE => $entity,
                        ExchangeProtocol::KEY_RESULT => $entityResults,
                    ];
                }
                return [ExchangeProtocol::KEY_RESULT  => $results];
            }


            return [
                ExchangeProtocol::KEY_ACTION => $action,
                ExchangeProtocol::KEY_RESULT => RequestHandlerFactory::getByAction($action)
                    ->handle($packet[ExchangeProtocol::KEY_PARAMS] ?? [])
            ];
        } catch (Throwable $exc) {
            static::getLogger()->error($exc->getMessage(), static::shrinkPacketForLog($packet));
            return static::makeError($exc);
        }
    }

    private static function login(array $packet): array
    {
        $clientGuid = static::requireStringParameter($packet, 'Login');
        $password = static::requireStringParameter($packet, 'Pass');

        return [
            ExchangeProtocol::KEY_SESSION_ID => AuthService::login($clientGuid, $password),
        ];
    }

    private static function checkAuth(array $packet): void
    {
        // Скипаем пакет авторизации
        if ($packet[ExchangeProtocol::KEY_ACTION] === ExchangeProtocol::ACTION_LOGIN) {
            return;
        }

        $sessionId = static::requireStringParameter($packet, ExchangeProtocol::KEY_SESSION_ID);
        if (!AuthService::checkAuth($sessionId)) {
            throw new Exception('Нет доступа');
        }
    }

    private static function requireStringParameter(array $packet, string $key): string
    {
        if (!(string)$packet[$key]) {
            throw new Exception('Не передан обязательный параметр ' . $key);
        }

        return (string)$packet[$key];
    }

    private static function makeError(Throwable $exc): array
    {
        return [
            'error' => $exc->getMessage(),
        ];
    }

    private static function log(array $packet): void
    {
        static::getLogger()->info(
            $packet[ExchangeProtocol::KEY_ACTION] ?? 'Неизвестный запрос',
            static::shrinkPacketForLog($packet)
        );
    }

    private static function getLogger(): LoggerInterface
    {
        if (!static::$logger) {
            static::$logger = LoggerFactory::get(static::class);
        }

        return static::$logger;
    }

    private static function shrinkPacketForLog(array $packet): array
    {
        $processedPacket = $packet;
        if (isset($processedPacket[ExchangeProtocol::KEY_PARAMS]['files'])) {
            foreach ($processedPacket[ExchangeProtocol::KEY_PARAMS]['files'] as $key => $file) {
                if (isset($file['data'])) {
                    $processedPacket[ExchangeProtocol::KEY_PARAMS]['files'][$key]['data'] = 'deleted_base64';
                }
            }
        }

        if (isset($processedPacket['Pass'])) {
            $processedPacket['Pass'] = '***';
        }

        return $processedPacket;
    }
}