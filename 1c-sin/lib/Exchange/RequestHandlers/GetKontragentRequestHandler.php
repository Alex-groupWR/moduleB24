<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\KontragentService;

class GetKontragentRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return KontragentService::get($request);
    }
}