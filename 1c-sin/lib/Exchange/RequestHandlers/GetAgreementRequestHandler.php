<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\AgreementService;
class GetAgreementRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        $typeId  = AgreementService::getInstance()->determineType($request);
        return AgreementService::getInstance()->setType($typeId)->getItem($request);
    }
}