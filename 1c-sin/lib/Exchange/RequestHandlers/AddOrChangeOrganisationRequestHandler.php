<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\OrganisationService;
use Rusgeocom\Rusgeocom\Exchange\Validate\OrganisationValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeOrganisationRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;
    private OrganisationService $organisationService;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
        $this->organisationService = OrganisationService::getInstance();
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку смарт-процесса наши организации', $request);

        if (!empty($error = OrganisationValidate::checkParams($request))) {
            return $error;
        }

        $id = !empty($request['b24_id'])
            ? $request['b24_id']
            : $this->organisationService->getExistId($request['guid']);

        if (!$id) {
            $result = $this->organisationService->add($request);
        } else {
            $result = $this->organisationService->update($id, $request);
        }

        return $result;
    }
}