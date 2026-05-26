<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;
use Rusgeocom\Rusgeocom\Exchange\Services\WarehouseService;

class GetWarehouseRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return WarehouseService::getWarehouse($request);
    }
}