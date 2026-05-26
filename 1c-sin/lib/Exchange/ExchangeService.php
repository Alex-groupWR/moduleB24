<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange;

use Exception;
use Rusgeocom\Rusgeocom\Exchange\Messages\MessageQueue;
use Rusgeocom\Rusgeocom\Exchange\Transport\TransportFactory;

class ExchangeService
{
	private static ExchangeClient $client;

	public static function send(string $action, array $payload = []): array
	{
		if (!ExchangeConfig::isEnabled()) {
			throw new Exception('Обмен с 1С отключен');
		}

		return static::getClient()->send($action, $payload);
	}

	public static function enqueue(string $action, array $payload, string $syncId = ''): void
	{
		if (!ExchangeConfig::isEnabled()) {
			return;
		}

		MessageQueue::add($action, $payload, $syncId);
	}

	private static function getClient(): ExchangeClient
	{
		if (!isset(static::$client)) {
			static::$client = new ExchangeClient(
				TransportFactory::createSoap(),
				ExchangeConfig::getLogin(),
                md5(ExchangeConfig::getLogin().ExchangeConfig::getPassword())
			);
		}

		return static::$client;
	}
}