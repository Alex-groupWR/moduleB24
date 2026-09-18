<?php

namespace Rusgeocom\Rusgeocom\Exchange\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Rusgeocom\Rusgeocom\Exchange\ExchangeService;
use Rusgeocom\Rusgeocom\Exchange\ResponseHandlers\ResponseHandlerFactory;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\OrderBuilder;
use Rusgeocom\Rusgeocom\Exchange\Services\Notifier\DealActivityNotifier;
use Rusgeocom\Rusgeocom\Exchange\Validate\OrderSendToOneCValidate;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Throwable;

class Deal extends Controller
{
    public function configureActions(): array
    {
        return [
            'sendToOneC' => [
                // внутренний вызов из UI сделки, стандартные Csrf/Authentication оставляем
            ],
            'checkSendAccess' => [
                // тоже требует авторизации — стандартные префильтры оставляем
            ],
        ];
    }

    public function checkSendAccessAction(): array
    {
        $userId = (int)CurrentUser::get()->getId();

        return [
            'allowed' => Settings::isUserAllowedToSendDealToOneC($userId),
        ];
    }

    public function sendToOneCAction(int $dealId): array
    {
        $logger = LoggerFactory::get(static::class);
        $userId = (int)CurrentUser::get()->getId();

        if ($dealId <= 0) {
            $this->addError(new Error('Не передан ID сделки', 'INVALID_DEAL_ID'));
            return [];
        }

        $validationErrors = OrderSendToOneCValidate::check($dealId, $userId);
        if (!empty($validationErrors)) {
            $message = implode('; ', $validationErrors);

            DealActivityNotifier::notifyError($dealId, $message);

            $logger->warning('Валидация сделки перед отправкой в 1С не пройдена', [
                'dealId' => $dealId,
                'userId' => $userId,
                'errors' => $validationErrors,
            ]);

            $this->addError(new Error($message, 'VALIDATION_ERROR'));
            return [];
        }

        $payload = OrderBuilder::build($dealId);
        if (empty($payload)) {
            $this->addError(new Error('Сделка не найдена', 'DEAL_NOT_FOUND'));
            return [];
        }

        try {
            $logger->info('Ручная отправка сделки в 1С', ['dealId' => $dealId, 'userId' => $userId, 'payload' => $payload]);

            $response = ExchangeService::send('Order', $payload);
            $handled  = ResponseHandlerFactory::getByAction('Order')->handle($payload, $response);

            $logger->info('Ответ 1С по ручной отправке сделки', ['dealId' => $dealId, 'response' => $handled]);

            return $handled;
        } catch (Throwable $exc) {
            $logger->exception($exc, 'Ошибка ручной отправки сделки в 1С', ['dealId' => $dealId]);

            DealActivityNotifier::notifyError($dealId, $exc->getMessage());

            $this->addError(new Error($exc->getMessage(), 'SEND_ERROR'));
            return [];
        }
    }
}