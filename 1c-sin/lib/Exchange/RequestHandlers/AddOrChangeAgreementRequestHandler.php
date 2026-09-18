<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Local\Backoffice\ActivityService;
use Rusgeocom\Rusgeocom\Exchange\Services\AgreementService;
use Rusgeocom\Rusgeocom\Exchange\Validate\AgreementValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeAgreementRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку смарт-процесса соглашение', $request);

        if (!empty($error = AgreementValidate::checkParams($request))) {
            return $error;
        }

        $typeId = AgreementService::getInstance()->determineType($request);
        $service = AgreementService::getInstance()->setType($typeId);

        $id = !empty($request['b24_id'])
            ? $request['b24_id']
            : $service->getExistId($request['guid']);

        if (!$id) {
            $result = $service->add($request);
        } else {
            $result = $service->update($id, $request);
        }

        if (!empty($request['URL_1c'])) {
            ActivityService::create1CLog($result['b24_id'], $request['titleActivity']??'Соглашение в 1с', $request['URL1c'], $typeId);
        }

        return $result;
    }
}