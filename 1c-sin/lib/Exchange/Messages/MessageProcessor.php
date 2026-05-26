<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Messages;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Rusgeocom\Rusgeocom\Exchange\ExchangeService;
use Rusgeocom\Rusgeocom\Exchange\RequestModifiers\RequestModifierFactory;
use Rusgeocom\Rusgeocom\Exchange\RequestOneC\RequestOneCFactory;
use Rusgeocom\Rusgeocom\Exchange\ResponseHandlers\ResponseHandlerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class MessageProcessor
{

//    public static function debugMessage()
//    {
//        try {
//            $response = static::sendMessage('ping', []);
//            echo '<pre>' . print_r($response, true) . '</pre>';
//        }catch (\Throwable $exception){
//            echo '<pre>' . print_r($exception->getMessage(), true) . '</pre>';
//        }
//    }


    public static function processRequest($action, $request): void
    {
        try {
            MessageQueue::add($action, $request,(string) $request['ID']);

            $payload =  static::createRequestOneC($action, $request);

            if (empty($payload)) {
                throw new \Exception('Не удалось создать запрос на отправку в 1с');
            }

            $response = static::sendMessage($action, $payload);
            MessageQueue::saveExchangeResult($request, true, $response);
        } catch (\Throwable $exc) {
            LoggerFactory::get(static::class)->exception($exc, 'Ошибка отправки сообщения', $request);
            MessageQueue::saveExchangeResult($request, false, ['exception' => $exc->getMessage()]);
            return;
        }

        try {
            $result = static::handleResponse($action, $request, $response);
            MessageQueue::saveHandleResult($request, true, $result);
        } catch (\Throwable $exc) {
            LoggerFactory::get(static::class)->exception($exc, 'Ошибка обработки сообщения', $request);
            MessageQueue::saveHandleResult($request, false, ['exception' => $exc->getMessage()]);
        }
    }


    private static function createRequestOneC(string $action, array $request): array
    {
        return RequestOneCFactory::getByAction($action)->handle($request);
    }

	private static function handleResponse(string $action, array $request, array $response): array
	{
		return ResponseHandlerFactory::getByAction($action)->handle($request, $response);
	}

	private static function sendMessage(string $action, array $payload): array
	{
		return ExchangeService::send($action, $payload);
	}
}