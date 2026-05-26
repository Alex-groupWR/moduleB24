<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange;

use Bitrix\Main\Config\Option;

class ExchangeConfig
{
	public static function getSoapUrl(): string
	{
		return Option::get("rusgeocom.rusgeocom", "EXCHANGE_SOAP_URL");
	}


	public static function getLogin(): string
	{
		return Option::get("rusgeocom.rusgeocom", "EXCHANGE_LOGIN");
	}

	public static function getPassword(): string
	{
        return Option::get("rusgeocom.rusgeocom", "EXCHANGE_PASSWORD");
    }

    public static function getExternalLogin(): string
    {
        return Option::get("rusgeocom.rusgeocom", "EXCHANGE_EXTERNAL_LOGIN");
    }

	public static function getExternalPassword(): string
	{
        return Option::get("rusgeocom.rusgeocom", "EXCHANGE_EXTERNAL_PASSWORD");
	}

	public static function isEnabled(): bool
	{
        return Option::get("rusgeocom.rusgeocom", "IS_ENABLE") == 'Y';
	}

	public static function isDebug(): bool
	{
        return Option::get("rusgeocom.rusgeocom", "IS_DEBUG") == 'Y';
	}
}