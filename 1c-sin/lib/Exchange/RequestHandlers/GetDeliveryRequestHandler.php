<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\DeliveryService;

class GetDeliveryRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return DeliveryService::getInstance()->getItem($request);
    }
}