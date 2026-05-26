<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Services\ContactService;
use Rusgeocom\Rusgeocom\Exchange\Validate\ContactValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class AddOrChangeContactRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен новый запрос на обработку контакта ', $request);
        if (!empty($error = ContactValidate::checkParams($request))) {
            return $error;
        }


        $id = !empty($request['b24_id']) ? $request['b24_id'] : ContactService::getExistId($request['guid']);

        if (!$id) {
            $agreement = ContactService::addContact($request);
        } else {
            $agreement = ContactService::updateContact($id, $request);
        }

        return $agreement;
    }
}