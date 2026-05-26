<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\ProductService;

class GetProductRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return ProductService::getProduct($request);
    }
}