<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\KontragentService;
use Rusgeocom\Rusgeocom\Exchange\Validate\KontragentValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeCompanyRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку контрагента', $request);

        if (!empty($error = KontragentValidate::checkParams($request))) {
            return $error;
        }

        $result = KontragentService::sync($request);

        $this->logger->info('Результат обработки контрагента', $result);

        return $result;
    }
}