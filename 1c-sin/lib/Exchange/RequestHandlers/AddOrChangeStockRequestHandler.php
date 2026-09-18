<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\StockService;
use Rusgeocom\Rusgeocom\Exchange\Validate\StockValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Utils\Environment;

class AddOrChangeStockRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку остатка', $request);

        if (!empty($error = StockValidate::checkParams($request))) {
            return $error;
        }

        $result = StockService::sync($request);

        $this->logger->info('Результат обработки остатка', $result);

        return $result;
    }
}