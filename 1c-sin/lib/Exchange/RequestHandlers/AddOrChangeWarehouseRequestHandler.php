<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\WarehouseService;
use Rusgeocom\Rusgeocom\Exchange\Services\WarehouseServiceSP;
use Rusgeocom\Rusgeocom\Exchange\Validate\WarehouseValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeWarehouseRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;
    private WarehouseServiceSP $warehouseService;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
        $this->warehouseService = WarehouseServiceSP::getInstance();

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

        //дублируем в СП
        $id = !empty($request['b24_id'])
            ? $request['b24_id']
            : $this->warehouseService->getExistId($request['guid']);

        if (!$id) {
            $this->warehouseService->add($request);
        } else {
            $this->warehouseService->update($id, $request);
        }

        return $store;
    }
}