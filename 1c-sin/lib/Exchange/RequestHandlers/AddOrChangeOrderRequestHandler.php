<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\OrderService;
use Rusgeocom\Rusgeocom\Exchange\Validate\OrderValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeOrderRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;
    private OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->logger       = LoggerFactory::get(static::class);
        $this->orderService = $orderService;
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку заказа', $request);

        $error = OrderValidate::checkParams($request);
        if (!empty($error)) {
            $this->logger->warning('Ошибка валидации запроса заказа', $error);
            return $error;
        }

        $result = $this->orderService->sync($request);

        $this->logger->info('Результат обработки заказа', $result);

        return $result;
    }
}