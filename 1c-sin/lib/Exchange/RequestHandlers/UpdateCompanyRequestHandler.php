<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\CompanyService;
use Rusgeocom\Rusgeocom\Exchange\Validate\CompanyValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class UpdateCompanyRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен запрос на обновление компании из 1С', $request);

        if (!empty($error = CompanyValidate::checkParams($request))) {
            return $error;
        }

        $result = CompanyService::sync($request);

        $this->logger->info('Результат обработки компании', $result);

        return $result;
    }
}