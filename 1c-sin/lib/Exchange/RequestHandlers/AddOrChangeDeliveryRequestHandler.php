<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\DeliveryService;
use Rusgeocom\Rusgeocom\Exchange\Validate\DeliveryValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeDeliveryRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;
    private DeliveryService $deliveryService;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
        $this->deliveryService = DeliveryService::getInstance();
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку смарт-процесса бизнес регион', $request);

        if (!empty($error = DeliveryValidate::checkParams($request))) {
            return $error;
        }

        $id = !empty($request['b24_id'])
            ? $request['b24_id']
            : $this->deliveryService->getExistId($request['guid']);

        if (!$id) {
            $result = $this->deliveryService->add($request);
        } else {
            $result = $this->deliveryService->update($id, $request);
        }

        return $result;
    }
}