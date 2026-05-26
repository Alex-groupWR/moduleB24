<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\BusinessRegionService;
use Rusgeocom\Rusgeocom\Exchange\Validate\BusinessRegionValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeBusinessRegionRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;
    private BusinessRegionService $businessRegionService;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
        $this->businessRegionService = BusinessRegionService::getInstance();
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку смарт-процесса бизнес регион', $request);

        if (!empty($error = BusinessRegionValidate::checkParams($request))) {
            return $error;
        }

        $id = !empty($request['b24_id'])
            ? $request['b24_id']
            : $this->businessRegionService->getExistId($request['guid']);

        if (!$id) {
            $result = $this->businessRegionService->add($request);
        } else {
            $result = $this->businessRegionService->update($id, $request);
        }

        return $result;
    }
}