<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\WarehouseService;
use Rusgeocom\Rusgeocom\Exchange\Validate\WarehouseValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeWarehouseRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку склада', $request);
        if (!empty($error = WarehouseValidate::checkParams($request))) {
            return $error;
        }

        $store = !empty($request['b24_id']) ? $request['b24_id'] : WarehouseService::checkExistWarehouse($request['guid']);

        if (!$store ) {
            $store = WarehouseService::addWarehouse($request);
        } else {
            $store = WarehouseService::updateWarehouse($store['b24_id'], $request);
        }

        return $store;
    }
}