<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\BusinessRegionService;

class GetBusinessRegionRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return BusinessRegionService::getInstance()->getItem($request);
    }
}