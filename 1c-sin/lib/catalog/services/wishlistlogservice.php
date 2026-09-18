<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Sale\Internals\FuserTable;
use Carbon\Carbon;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use Rusgeocom\Rusgeocom\Audit\Entities\AuditEvent;
use Rusgeocom\Rusgeocom\Audit\Entities\AuditQueryParams;
use Rusgeocom\Rusgeocom\Audit\Enums\AuditType;
use Rusgeocom\Rusgeocom\Audit\Helpers\PayloadHelper;
use Rusgeocom\Rusgeocom\Audit\Services\AuditService;
use Rusgeocom\Rusgeocom\Catalog\Entities\WishlistAuditPayload;
use Rusgeocom\Rusgeocom\Catalog\Entities\WishlistEventInfo;
use Rusgeocom\Rusgeocom\Delivery\Geo\CityService;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Utils\Normalizer;

class WishlistLogService
{
	private const string ENTITY_TYPE = 'wishlist';
	private const int DAYS_BEFORE_NOTIFICATION = 3;

	public static function onUpdate(int $fuserId, array $productIds = []): void
	{
		self::addLog(
			AuditType::WishlistUpdated,
			$fuserId,
			array_merge(PayloadHelper::makeUserInfoForFuser($fuserId), ['productIds' => $productIds])
		);
	}

	public static function onCheckout(int $fuserId, array $payload = []): void
	{
		self::addLog(
			AuditType::WishlistCheckout,
			$fuserId,
			array_merge(PayloadHelper::makeUserInfoForFuser($fuserId), $payload)
		);
	}

	public static function onAfterClear(int $fuserId): void
	{
		self::addLog(AuditType::WishlistCleared, $fuserId);
	}

	public static function markAbandoned(int $fuserId): void
	{
		self::addLog(AuditType::WishlistAbandoned, $fuserId);
	}

	private static function addLog(AuditType $type, int $fuserId, array $rawPayload = []): void
	{
		AuditService::addToLog(
			new AuditEvent(
				auditType: $type,
				eventDate: new DateTimeImmutable(),
				entityType: self::ENTITY_TYPE,
				entityCode: (string)$fuserId,
				payload: $rawPayload ? WishlistAuditPayload::fromArray($rawPayload) : null,
			)
		);
	}

	public static function getWishlistsForNotification(): array
	{
		$date = Carbon::now()
			->subDays(self::DAYS_BEFORE_NOTIFICATION)
			->subHour()
			->startOfHour();

		$events = self::getLastEventForNotUpdatedSince($date->toDateTimeImmutable());
		if (!$events) {
			return [];
		}

		$fuserIds = array_unique(array_column($events, 'ENTITY_CODE'));
		$wishlistCheckouts = self::getCheckoutInformationForWishlists($fuserIds);
		$wishlistUsers = self::getUsersByFuserIds($fuserIds);

		$wishlists = [];
		foreach ($events as $event) {
			$fuserId = (int)$event['ENTITY_CODE'];
			$checkoutInfo = $wishlistCheckouts[$fuserId];
			if (!$checkoutInfo && !$wishlistUsers[$fuserId]) {
				continue;
			}

			$productIds = $event['PAYLOAD']['productIds'] ?? [];
			if (!$productIds) {
				continue;
			}

			$contactEmail = $checkoutInfo['contactInfo']['email'] ?? null;
			$headerCity = $event['PAYLOAD']['headerCity'] ?? null;
			$cityFias = $checkoutInfo['cityFias'] ?? null;
			if ($cityFias && ($city = CityService::getCityByFias($cityFias))) {
				$headerCity = Normalizer::getNormalizedCityWithRegionName($city->getName(), $city->getRegion());
			}

			$wishlists[] = new WishlistEventInfo(
				fuserId: $fuserId,
				productIds: $productIds,
				domain: $event['PAYLOAD']['domain'] ?? BranchCityService::DEFAULT_DOMAIN,
				userId: $wishlistUsers[$fuserId] ?? null,
				userCheckoutPhone: $checkoutInfo['contactInfo']['phone'] ?? null,
				userCheckoutEmail: $contactEmail ?? null,
				cityFias: $cityFias,
				headerCity: $headerCity,
			);
		}

		return $wishlists;
	}

	private static function getUsersByFuserIds(array $fuserIds): array
	{
		if (!$fuserIds) {
			return [];
		}

		$users = [];
		$iterator = FuserTable::query()
			->addSelect('ID')
			->addSelect('USER_ID')
			->whereIn('ID', $fuserIds)
			->whereNotNull('USER_ID')
			->exec();

		while ($row = $iterator->fetch()) {
			$users[(int)$row['ID']] = (int)$row['USER_ID'];
		}

		return $users;
	}

	private static function getCheckoutInformationForWishlists(array $fuserIds): array
	{
		if (!$fuserIds) {
			return [];
		}

		$params = AuditQueryParams::create()
			->disablePagination()
			->addAuditType(AuditType::WishlistCheckout)
			->addFilter('ENTITY_CODE', $fuserIds);

		$events = AuditService::getByParams($params);

		$checkouts = [];
		foreach ($events as $event) {
			$checkouts[$event['ENTITY_CODE']] = $event['PAYLOAD'];
		}

		return $checkouts;
	}

	private static function getLastEventForNotUpdatedSince(DateTimeImmutable $since): array
	{
		$params = AuditQueryParams::create()
			->disablePagination()
			->setEntityType(self::ENTITY_TYPE)
			->setPeriod(new DatePeriod(
				$since,
				new DateInterval('PT1H'),
				$since->modify('+1 hour'),
				DatePeriod::INCLUDE_END_DATE
			));

		$wishlistsWithoutActions = AuditService::getLastEventIdForEntities($params);
		$eventIds = array_column($wishlistsWithoutActions, 'LAST_ID');
		if (!$eventIds) {
			return [];
		}

		$queryParams = AuditQueryParams::create()
			->disablePagination()
			->addFilter('ID', $eventIds)
			->setAuditTypes([
				AuditType::WishlistUpdated,
				AuditType::WishlistCheckout,
			]);

		return AuditService::getByParams($queryParams);
	}
}
