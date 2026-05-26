<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange;

use Exception;
use Rusgeocom\Rusgeocom\Exchange\Transport\TransportInterface;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;
use Rusgeocom\Rusgeocom\Utils\Option;

class ExchangeClient
{
	private const OPTION_SESSION_ID = 'exchange_session_id';
	private const OPTION_SESSION_TS = 'exchange_session_ts';
	private const SESSION_LIFE_TIME_HOURS = 1; // Должно быть 24, но по факту протухает раньше

	private TransportInterface $transport;
	private string $clientGuid;
	private string $password;
	private LoggerInterface $logger;

	public function __construct(TransportInterface $transport, string $clientGuid, string $password)
	{
		$this->logger = LoggerFactory::get(static::class);
		$this->transport = $transport;
		$this->clientGuid = $clientGuid;
		$this->password = $password;
	}

	public function send(string $action, array $payload = []): array
	{
		$request = [
			ExchangeProtocol::KEY_SESSION_ID => $this->getSessionId(),
			ExchangeProtocol::KEY_ACTION => $action,
			ExchangeProtocol::KEY_PARAMS => $payload,
		];

		$response = $this->transport->send($request);

		if ($response['error'] && in_array(trim($response['error']), $this->getSessionErrors())) {
			// Может просто протухла сессия, перелогинимся
			$this->resetSession();
			$request[ExchangeProtocol::KEY_SESSION_ID] = $this->getSessionId();
			$response = $this->transport->send($request);
		}

		if ($response['error']) {

			$this->logger->error('1С вернула ошибку', ['response' => $response]);
			throw new Exception(trim($response['error']));
		}

		// Если понадобится ID запроса, то он есть тут
		// $requestId = $response[ExchangeProtocol::KEY_REQUEST_ID];

		return $response;
	}

	private function getSessionErrors(): array
	{
		return [
			'Сесия просрочена', // Так присылают
			'Сессия просрочена', // Если вдруг проснётся совесть и исправят
			'Нет доступа', // Так присылали раньше
		];
	}

	private function resetSession(): void
	{
		Option::unset(static::OPTION_SESSION_ID);
	}

	private function getSessionId(): string
	{
		$sessionId = Option::get(static::OPTION_SESSION_ID);
		$ts = (int)Option::get(static::OPTION_SESSION_TS);
		$isExpired = time() > $ts + static::SESSION_LIFE_TIME_HOURS * 60 * 60;
		if ($sessionId && !$isExpired) {
			return $sessionId;
		}

		return $this->login();
	}

	private function login(): string
	{

		$request = [
            'Func' => 'getguid',
			'Login' => $this->clientGuid,
			'Pass' =>  $this->password,
		];

		$response = $this->transport->send($request);

		$sessionId = $response[ExchangeProtocol::KEY_SESSION_ID] ?? '';
		if (!$sessionId) {
			$this->logger->error('Ошибка авторизации', ['response' => $response]);
			throw new Exception($response['error'] ?: 'Ошибка авторизации');
		}

		Option::set(static::OPTION_SESSION_ID, $sessionId);
		Option::set(static::OPTION_SESSION_TS, (string)time());

		return $sessionId;
	}
}