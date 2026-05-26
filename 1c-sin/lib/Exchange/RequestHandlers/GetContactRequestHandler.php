<?php
namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\ContactService;
class GetContactRequestHandler implements RequestHandlerInterface
{
    public function handle(array $request): array
    {
        return ContactService::getContact($request);
    }
}