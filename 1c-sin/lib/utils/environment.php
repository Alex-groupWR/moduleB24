<?php
namespace Rusgeocom\Rusgeocom\Utils;

class Environment
{
	public static function isProd(): bool
	{
		return file_exists($_SERVER['DOCUMENT_ROOT'] . '/.prod');
	}

	public static function isRedesign2023(): bool
	{
		return static::getValue('REDESIGN_2023') === 'true';
	}

	public static function getRecaptchaPublicKey(): string
	{
		return static::getValue('RECAPTCHA_PUBLIC_KEY');
	}

	public static function getTwoGisApiKey(): string
	{
		return static::getValue('TWO_GIS_API_KEY');
	}

	public static function getRecaptchaPrivateKey(): string
	{
		return static::getValue('RECAPTCHA_PRIVATE_KEY');
	}

	public static function getMjmlBinaryRendererPath(): string
	{
		return static::getValue('MJML_BINARY_RENDERER_PATH');
	}

	public static function isMailingEnabled(): bool
	{
		return static::getValue('ENABLE_MAILING') !== 'N';
	}

	public static function getTestYooKassaApiKey(): string
	{
		return static::getValue('TEST_YOOKASSA_API_KEY');
	}

	public static function getTestYooKassaSiteId(): int
	{
		return (int)static::getValue('TEST_YOOKASSA_SHOP_ID');
	}

	public static function getDefaultYooKassaApiKey(): string
	{
		return static::getValue('DEFAULT_YOOKASSA_API_KEY');
	}

	public static function getDefaultYooKassaSiteId(): int
	{
		return (int)static::getValue('DEFAULT_YOOKASSA_SHOP_ID');
	}

	public static function getValue(string $key): string
	{
		return getenv($key) ?: '';
	}

	public static function getBool(string $key): bool
	{
		$value = strtolower(static::getValue($key));
		return in_array($value, ['true', 'y', '1', 'yes']);
	}

	public static function getFrontEnv(): string
	{
		return static::getValue('FRONT_ENV') ?: 'prod';
	}

	public static function getCookieDomain(): string
	{
		return static::getValue('COOKIE_DOMAIN') ?: '.rusgeocom.ru';
	}

	public static function isDebugModeEnabled(): bool
	{
		return static::getValue('DEBUG') === 'true';
	}

	public static function getApiAuthToken(): string
	{
		return static::getValue('LARAVEL_API_TOKEN');
	}

	public static function isSmsRuDisabled(): bool
	{
		return static::getValue('DISABLE_SMSRU') === 'true';
	}

	public static function isSmsCodeVerificationEnabled(): bool
	{
		return static::getValue('SMS_CODE_CHECK') === 'true';
	}

	public static function getSmsRuKey(): string
	{
		return static::getValue('SMSRU_KEY');
	}

	/**
	 * URL сайта без поддомена города
	 */
	public static function getHost(): string
	{
		return static::getValue('HOST') ?: 'rusgeocom.ru';
	}

	public static function getRedisEnv(): string
	{
		return static::getValue('REDIS_ENV') ?: 'prod';
	}

	public static function getRedisUrl(): string
	{
		$host = static::getValue('REDIS_HOST') ?: '127.0.0.1';
		$port = (int)static::getValue('REDIS_PORT') ?: 6379;

		return "tcp://$host:$port";
	}

	public static function getSessionRedisUrl(): string
	{
		$host = static::getValue('SESSION_REDIS_HOST') ?: '127.0.0.1';
		$port = (int)static::getValue('SESSION_REDIS_PORT') ?: 6379;

		return "tcp://$host:$port";
	}

	public static function getDocumentRoot(): string
	{
		return dirname(__DIR__, 5);
	}

	public static function makeAbsolutePath(string ...$parts): string
	{
		return Url::concat(static::getDocumentRoot(), ...$parts);
	}

	public static function isPropertyPricesDisabled(): bool
	{
		return static::getBool('PROPERTY_PRICES_DISABLED');
	}

	public static function isNewDiscountsEnabled(): bool
	{
		return static::getBool('NEW_DISCOUNTS_ENABLED');
	}

	public static function isLaravelExchangeEnabled(): bool
	{
		return Environment::getBool('LARAVEL_EXCHANGE_ENABLE');
	}

	public static function isPriceChangeLoggingEnabled(): bool
	{
		return static::getBool('PRICE_CHANGE_LOGGING_ENABLE');
	}

	public static function isElasticEnabled(): bool
	{
		return !static::getBool('ELASTICSEARCH_DISABLED');
	}

	public static function isElasticLogsEnabled(): bool
	{
		return static::getBool('ELASTICSEARCH_LOG_ENABLED');
	}

	public static function isNewFormatForBotProtectionEnabled(): bool
	{
		return static::getBool('BOT_PROTECTION_BY_RULES');
	}

	public static function isCoreDiscountCalculationEnabled(): bool
	{
		return static::getBool('CORE_DISCOUNTS_ENABLED');
	}

	public static function isDynamicMainPageEnabled(): bool
	{
		return static::getBool('DYNAMIC_MAIN_PAGE_ENABLED');
	}
	public static function isDaDataCleanEnabled(): bool
	{
		return static::getBool('DADATA_CLEAN_ENABLED');
	}

	public static function getDaDataSecretKey(): string
	{
		return static::getValue('DADATA_SECRET_KEY');
	}

    public static function getYandexGeocoderKey(): string
    {
		return static::getValue('YANDEX_GEOCODER_API_KEY');
    }

	public static function isNewComplectActive(): bool
	{
		return static::getBool('NEW_COMPLETE_ACTIVE');
	}

	public static function isRestsExchangeEnabled(): bool
	{
		return static::getBool('EXCHANGE_FOR_RESTS');
	}

	public static function isSmartCaptchaEnabled(): bool
	{
		return static::getBool('SMART_CAPTCHA_ENABLED');
	}

	public static function getSmartCaptchaSecretKey(): string
	{
		return static::getValue('SMART_CAPTCHA_PRIVATE_KEY');
	}

	public static function getSmartCaptchaPublicKey(): string
	{
		return static::getValue('SMART_CAPTCHA_PUBLIC_KEY');
	}
}
