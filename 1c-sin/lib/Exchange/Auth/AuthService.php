<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Auth;

use Bitrix\Main\Type\DateTime;
use Exception;
use Rusgeocom\Rusgeocom\Exchange\ExchangeConfig;
use Rusgeocom\Rusgeocom\Utils\OrmResult;
use Rusgeocom\Rusgeocom\Utils\Uuid;

class AuthService
{
	private const SESSION_LIFE_TIME_HOURS = 1;

	public static function checkAuth(string $sessionId): bool
	{
		return (bool)SessionTable::query()
			->addSelect('ID')
			->where('SESSION_UUID', $sessionId)
			->where('DATE_INSERT', '>', DateTime::createFromTimestamp(time() - static::SESSION_LIFE_TIME_HOURS * 60 * 60))
			->exec()
			->fetch();
	}

	public static function login(string $clientGuid, string $password): string
	{
        $clients = [
            ExchangeConfig::getExternalLogin() => md5(ExchangeConfig::getExternalLogin().ExchangeConfig::getExternalPassword())
        ];

		if ($clients[$clientGuid] !== $password) {
			throw new Exception('Нет доступа');
		}

		$sessionId = Uuid::create();

		$fields = [
			'CLIENT_UUID' => $clientGuid,
			'SESSION_UUID' => $sessionId,
		];
		$result = SessionTable::add($fields);
		OrmResult::ensureSuccess($result);

		return $sessionId;
	}
}