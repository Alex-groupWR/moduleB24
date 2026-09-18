<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Entities\WishlistEventInfo;
use Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists\WishlistDetails;
use Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists\WishlistProduct;
use Rusgeocom\Rusgeocom\Personal\Services\PersonalService;
use Rusgeocom\Rusgeocom\Utils\Url;

final class AbandonedWishlistDetailsFactory
{
	public static function makeFromEventInfo(
		WishlistEventInfo $eventInfo,
		int $userId,
		string $domain = '',
	): WishlistDetails {
		$personalInfo = $userId ? PersonalService::getUserById($userId) : null;
		$emailConfirmed = $userId && PersonalService::isEmailConfirmed($userId);

		return new WishlistDetails(
			id: $eventInfo->getFuserId(),
			phone: $personalInfo?->getPhone()->getNumber() ?? ($eventInfo->getUserCheckoutPhone() ?? ''),
			userId: $userId,
			name: $personalInfo?->getFIO(),
			email: $personalInfo?->getEmail() ?? $eventInfo->getUserCheckoutEmail(),
			emailConfirmed: $emailConfirmed,
			items: self::makeItems($eventInfo, $domain),
			headerCity: $eventInfo->getHeaderCity(),
		);
	}

	private static function makeItems(WishlistEventInfo $eventInfo, string $domain): array
	{
		$items = [];

		$products = Catalog::getProductsByIds($eventInfo->getProductIds());

		foreach ($eventInfo->getProductIds() as $productId) {
			if (!$product = $products->getById($productId)) {
				continue;
			}

			$items[] = new WishlistProduct(
				id: $product->getId(),
				name: $product->getName(),
				url: Url::makeAbsoluteUrl($product->getUrl(), $domain),
				price: $product->getPrice(),
			);
		}

		return $items;
	}
}