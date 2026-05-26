<?php

namespace Rusgeocom\Rusgeocom\Exchange\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Rusgeocom\Rusgeocom\Exchange\Dispatcher;
use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Web\Json;
use Bitrix\Main\Error;
use Throwable;

class CallAction  extends Controller
{

    public function configureActions(): array
    {
        return [
            'execute' => [
                '-prefilters' => [
                    ActionFilter\Csrf::class,
                    ActionFilter\Authentication::class,
                ],
            ],
        ];
    }

    public function executeAction(): ?array
    {
        $rawInput = $this->request->getInput();
        $data = Json::decode($rawInput)['data'];

        if (!$data) {
            $this->addError(new Error("", ""));
            return ['error' => 'Не передано обязательное поле data'];
        }

        try {
            $request = ExchangeProtocol::deserialize($data);
            $response = Dispatcher::handlePacket($request);

            if (isset($response['error'])) {
                $this->addError(new Error($response['error'], "exchange_error"));
                return ['error' => $response['error']];
            }

            return $response;
        } catch (Throwable $exception) {
            $this->addError(new Error($exception->getMessage(), "server_error"));
            return ['error' => $exception->getMessage()];
        }
    }
}
