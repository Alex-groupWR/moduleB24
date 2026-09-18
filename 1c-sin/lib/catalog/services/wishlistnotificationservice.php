<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists\WishlistDetails;
use Rusgeocom\Rusgeocom\Delivery\Geo\CityService;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Types\PhoneNumber;
use Rusgeocom\Rusgeocom\User\Services\UserRepository;
use Rusgeocom\Rusgeocom\User\UserService;

final class WishlistNotificationService
{
	private const array USER_GROUPS_FOR_EXCLUDE = [
		ADMIN_GROUP_ID,
		MANAGER_GROUP_ID,
		MANAGER_RUSGEOCOM_GROUP_ID,
		CONTENT_EDITOR_GROUP_ID,
	];

	public static function notifyAboutAbandonedWishlists(): void
	{
		$forNotifications = self::getWishlistsForNotification();

		$branchService = BranchCityService::getInstance();
		$sender = new AbandonedWishlistsMailSender();
		foreach ($forNotifications as $domain => $abandonedWishlists) {
			$branch = $branchService->getCityByDomain($domain);
			$sender->sendMessage($branch->getEmail(), $abandonedWishlists);

			foreach ($abandonedWishlists as $abandonedWishlist) {
				WishlistLogService::markAbandoned($abandonedWishlist->getId());
			}
		}
	}

	/**
	 * @return array<string, WishlistDetails[]>
	 * @throws \Rusgeocom\Rusgeocom\Api\Exceptions\ApiException
	 */
	private static function getWishlistsForNotification(): array
	{
		$events = WishlistLogService::getWishlistsForNotification();
		if (!$events) {
			return [];
		}

		$forNotifications = [];
		$repository = new UserRepository();
		foreach ($events as $event) {
			$phone = $event->getUserCheckoutPhone();
			$userId = $event->getUserId();
			if ($phone && !$userId && PhoneNumber::isValid($phone)) {
				$userId = $repository->getByPhone(new PhoneNumber($phone))?->getId()->getValue();
			}

			//Для следующих групп проверка не нужна
			if ($userId && UserService::hasAtLeastOneGroup($userId, self::USER_GROUPS_FOR_EXCLUDE)) {
				continue;
			}

			$cityFias = $event->getCityFias();
			$city = $cityFias ? CityService::getCityByFias($cityFias) : null;
			$domain = $city
				? CityService::getBranchCityByReceiverCity($city)->getDeliveryDomain()
				: $event->getDomain();

			$forNotifications[$domain][] = AbandonedWishlistDetailsFactory::makeFromEventInfo(
				$event,
				$userId ?? 0,
				$domain
			);
		}

		return $forNotifications;
	}
}
