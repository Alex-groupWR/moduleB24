<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange;

use Bitrix\Main\Web\Json;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class ExchangeProtocol
{
	public const KEY_RESULT = 'results';
	public const KEY_PARAMS = 'Params';
	public const KEY_ACTION = 'Func';
	public const KEY_ERROR = 'error';
	public const KEY_ERROR_TEXT = 'error_text';
	public const KEY_REQUEST_ID = 'idZapros';
	public const KEY_SESSION_ID = 'GuidClient';

	public const ACTION_LOGIN = 'getguid';
	public const ACTION_SYNC_PACKET = 'syncPacket';
	public const ENTITY_TYPE = 'entity_type';
	public const ITEM = 'items';
	public const B24ID = 'b24_id';
	public const GUID = 'guid';
	public const STATUS = 'status';
    public const CURRENCY = [
        643 => 'RUB',
        398 => 'KZT'
    ];

	public static function serialize(array $data): string
	{
		return base64_encode(Json::encode($data, JSON_THROW_ON_ERROR));
	}

	public static function deserialize(string $value): array
	{
		$decoded = base64_decode($value);

		try {
			return Json::decode($decoded);
		} catch (\Throwable $exc) {
			LoggerFactory::get(static::class)->exception($exc, 'Ошибка при десериализации пакета', [
				'json' => $decoded
			]);

			throw $exc;
		}
	}
}