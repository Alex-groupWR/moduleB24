<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\DealService;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeDealRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку сделки', $request);

//        if (!empty($error = DealValidate::checkParams($request))) {
//            return $error;
//        }

        $result = DealService::sync($request);

        $this->logger->info('Результат обработки сделки', $result);

        return $result;
    }
}