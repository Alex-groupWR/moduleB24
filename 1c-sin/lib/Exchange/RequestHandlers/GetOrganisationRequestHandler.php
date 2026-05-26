<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\OrganisationService;

class GetOrganisationRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return OrganisationService::getInstance()->getItem($request);
    }
}