<?php

namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use \Rusgeocom\Rusgeocom\Cache\Redis;
use Rusgeocom\Rusgeocom\Catalog\Enums\CatalogViewType;

class Settings
{
	/** @var string */
	public const MODULE_ID = 'rusgeocom.rusgeocom';

	/** @var string */
	public const NEW_YEAR_DESIGN_ENABLED_OPTION_CODE = 'is_new_year_design_enabled';

	/** @var string */
	public const EMAIL_CONFIRM_LINK_DAYS_LIFETIME = 'email_confirm_link_lifetime';

	/** @var string */
	public const PRODUCT_IMAGES_LIMIT_IN_SLIDER_IN_SECTION = 'product_images_limit_in_slider_in_section';

	public const PAGE_PRODUCTS_COUNT_IN_SECTION = 'page_products_count_in_section';
	public const MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS = 'max_quantity_of_visible_subsections';

	public const MAX_DAYS_REVIEW_RECOMMENDATION_ALIVE = 'max_days_review_recommendation_alive';

	public const RUSGEOCOM_MANAGERS_ALLOWED_REMOTE_ADDRESSES = 'rusgeocom_managers_allowed_remote_addresses';
	public const string MAX_DAYS_BEFORE_HIDE_DELIVERY_PERIOD = 'max_days_before_hide_delivery_period';
	public const string MAX_DELIVERY_PRICE_FOR_SHOW = 'max_delivery_price_for_show';
	public const string SMS_AUTH_TIMEOUTS = 'sms_auth_timeouts';
	public const string MAX_DESCRIPTION_HEIGHTS_IN_SECTION = 'max_description_heights_in_section';
	public const string LOG_SECTION_USER_FIELDS = 'log_section_user_fields';
	public const string EXPORT_REGISTRATIONS_TOKEN = 'export_registration_token';
	public const string DEFAULT_CATALOG_VIEW = 'default_catalog_view';
	public const string DELIVERY_POPUP_DESCRIPTION = 'delivery_popup_description';
	public const string DELIVERY_DEFAULT_HINT = 'delivery_default_hint';
	public const string VERIFICATION_METROLOGY_OPT_PAPER_PRICE = 'verification_metrology_opt_paper_price';
	public const string VERIFICATION_RETAIL_PAPER_PRICE = 'verification_retail_paper_price';
	public const string HOURS_BEFORE_BASKET_ABANDONED = 'hours_before_basket_abandoned';
	public const string HOURS_FOR_ORDER_BEFORE_BASKET_ABANDONED = 'hours_for_order_before_basket_abandoned';
	public const string MAIN_PAGE_PREVIEW_USERS = 'main_page_preview_users';
	public const string VAT_RATE = 'vat_rate';
	public const string IGNORED_USER_IDS_IN_REPORTS = 'ignored_user_ids_in_reports';

	public static function isNewYearDesignEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::NEW_YEAR_DESIGN_ENABLED_OPTION_CODE, 'N') === 'Y';
	}

	public static function getEmailConfirmLinkLifetime(): int
	{
		return (int)Option::get(self::MODULE_ID, self::EMAIL_CONFIRM_LINK_DAYS_LIFETIME, 1);
	}

	public static function getProductImagesLimitInSlider(): int
	{
		return (int)Option::get(self::MODULE_ID, self::PRODUCT_IMAGES_LIMIT_IN_SLIDER_IN_SECTION);
	}

	public static function getPageProductCountInSection(): int
	{
		return (int)Option::get(self::MODULE_ID, self::PAGE_PRODUCTS_COUNT_IN_SECTION);
	}

	public static function getMaxQuantityOfVisibleSubsections(): int
	{
		$maxSubsectionQuantity = Option::get(self::MODULE_ID, self::MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS, '6');
		return $maxSubsectionQuantity === '' ? 6 : (int)$maxSubsectionQuantity;
	}

	public static function onAfterSetOptionPageProductsCountInSection(): void
	{
		Redis::getInstance()->clearAllNuxtCache();
	}

	public static function getAllowedIpsForRusgeocomManagers(): array
	{
		$addressesText = Option::get(
			self::MODULE_ID,
			self::RUSGEOCOM_MANAGERS_ALLOWED_REMOTE_ADDRESSES,
			'*'
		);
		$addresses = explode(',', str_replace(PHP_EOL, ',', $addressesText));

		foreach ($addresses as $key => &$address) {
			$address = trim($address);
			if (!$address) {
				unset($addresses[$key]);
			}
		}
		unset($address);

		return $addresses;
	}

	public static function isIpAllowedForRusgeocomManager(string $ip): bool
	{
		if (!$ip) {
			return false;
		}

		$addresses = static::getAllowedIpsForRusgeocomManagers();

		return !$addresses || in_array('*', $addresses, true) || in_array($ip, $addresses, true);
	}

	public static function getLogSectionUserFields(): string
	{
		return (string)Option::get(self::MODULE_ID, self::LOG_SECTION_USER_FIELDS);
	}

	public static function getMaxDaysReviewRecommendationAlive(): int
	{
		return (int)Option::get(self::MODULE_ID, self::MAX_DAYS_REVIEW_RECOMMENDATION_ALIVE);
	}

	public static function getMaxDaysBeforeHideDeliveryPeriod(): int
	{
		return (int)Option::get(self::MODULE_ID, self::MAX_DAYS_BEFORE_HIDE_DELIVERY_PERIOD, '7');
	}

	public static function getMaxDeliveryPriceForShow(): int
	{
		return (int)Option::get(self::MODULE_ID, self::MAX_DELIVERY_PRICE_FOR_SHOW, '2000');
	}

	public static function getDefaultCatalogView(): string
	{
		return Option::get(self::MODULE_ID, self::DEFAULT_CATALOG_VIEW, CatalogViewType::Tiles->value);
	}

	public static function getLaravelSettings(): array
	{
		return [
			'new_year_design.is_enabled' => static::isNewYearDesignEnabled(),
			'catalog.max_quantity_of_visible_subsections' => static::getMaxQuantityOfVisibleSubsections(),
			'catalog.vat_rate' => static::getVatRate(),
			'catalog_section.description_heights' => Json::encode(static::getMaxDescriptionHeightsInSection()),
			'catalog_section.page_product_count' => static::getPageProductCountInSection(),
			'catalog_section.default_view' => static::getDefaultCatalogView(),
			'catalog_section.product_images_limit' => static::getProductImagesLimitInSlider(),
			'users.rusgeocom_managers_allowed_remote_addresses' => Json::encode(static::getAllowedIpsForRusgeocomManagers()),
		];
	}

	public static function getLaravelSettingKeyByBitrix(string $module, string $name): string
	{
		if ($module === 'rusgeocom.rusgeocom') {
			return match ($name) {
				static::NEW_YEAR_DESIGN_ENABLED_OPTION_CODE => 'new_year_design.is_enabled',
				static::MAX_DESCRIPTION_HEIGHTS_IN_SECTION => 'catalog_section.description_heights',
				static::MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS => 'catalog.max_quantity_of_visible_subsections',
				static::PAGE_PRODUCTS_COUNT_IN_SECTION => 'catalog_section.page_product_count',
				static::DEFAULT_CATALOG_VIEW => 'catalog_section.default_view',
				static::PRODUCT_IMAGES_LIMIT_IN_SLIDER_IN_SECTION => 'catalog_section.product_images_limit',
				static::RUSGEOCOM_MANAGERS_ALLOWED_REMOTE_ADDRESSES => 'users.rusgeocom_managers_allowed_remote_addresses',
				default => '',
			};
		}

		return '';
	}

	public static function getSmsAuthTimeouts(): array
	{
		$optionValue = Option::get(self::MODULE_ID, self::SMS_AUTH_TIMEOUTS);
		if (!$optionValue) {
			return [];
		}

		$timeouts = explode(',', $optionValue);

		return array_map(
			static fn(string $timeout) => (int)trim($timeout),
			$timeouts
		);
	}

	/**
	 * @return array{desktop: int, tablet: int, mobile: int}
	 */
	public static function getMaxDescriptionHeightsInSection(): array
	{
		$optionValue = Option::get(self::MODULE_ID, self::MAX_DESCRIPTION_HEIGHTS_IN_SECTION, '250,250,250');

		//0 - не ограничено
		$heights = array_map(
			static fn(string $height) => (int)trim($height),
			explode(',', $optionValue)
		);

		//всегда 3 размера
		$missingSizes = 3 - count($heights);
		if ($missingSizes > 0) {
			$lastHeight = end($heights);
			while ($missingSizes--) {
				$heights[] = $lastHeight;
			}
		}

		return array_combine(
			[
				'desktop',
				'tablet',
				'mobile',
			],
			array_slice($heights, 0, 3)
		);
	}

	public static function getExportRegistrationsToken(): string
	{
		return Option::get(self::MODULE_ID, self::EXPORT_REGISTRATIONS_TOKEN);
	}

	public static function getDeliveryPopupDescription(): string
	{
		return Option::get(self::MODULE_ID, self::DELIVERY_POPUP_DESCRIPTION);
	}

	public static function getDeliveryDefaultHint(): string
	{
		return Option::get(self::MODULE_ID, self::DELIVERY_DEFAULT_HINT);
	}

	public static function getMetrologyPaperPrice(int $defaultPrice): int
	{
		return (int)Option::get(self::MODULE_ID, self::VERIFICATION_METROLOGY_OPT_PAPER_PRICE) ?: $defaultPrice;
	}

	public static function getRetailPaperPrice(int $defaultPrice): int
	{
		return (int)Option::get(self::MODULE_ID, self::VERIFICATION_RETAIL_PAPER_PRICE) ?: $defaultPrice;
	}

	public static function getWaitingHoursBeforeAbandonBasket(): int
	{
		return (int)Option::get(self::MODULE_ID, self::HOURS_BEFORE_BASKET_ABANDONED,24);
	}

	public static function getHoursForOrderIfBasketAbandoned(): int
	{
		return (int)Option::get(self::MODULE_ID, self::HOURS_FOR_ORDER_BEFORE_BASKET_ABANDONED,0);
	}

	public static function isUserForMainPagePreview(int $userId): bool
	{
		$previewUsers = explode(',', Option::get(self::MODULE_ID, self::MAIN_PAGE_PREVIEW_USERS));
		return in_array($userId, $previewUsers);
	}

	public static function getVatRate(): float
	{
		return (float)Option::get(self::MODULE_ID, self::VAT_RATE, 20.0);
	}

	public static function getIgnoredUserIdsInReports(): array
	{
		$userIds = explode(',', Option::get(self::MODULE_ID, self::IGNORED_USER_IDS_IN_REPORTS, ''));
		if (!$userIds) {
			return [];
		}

		return array_map(
			static fn(string $userId) => (int)trim($userId),
			$userIds
		);
	}
}
