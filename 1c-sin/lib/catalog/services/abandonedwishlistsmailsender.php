<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists\WishlistDetails;
use Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists\WishlistProduct;
use Rusgeocom\Rusgeocom\Utils\MailService;

final class AbandonedWishlistsMailSender
{
	public function sendMessage(string $email, array $wishlists): void
	{
		$title = 'Новые брошенные списки избранного на сайте';
		$props = [
			'contentTemplate' => 'NotificationAbandonedWishlists',
			'showButton' => false,
		];

		$data = [
			'userWishlists' => [],
			'guestWishlists' => [],
		];

		foreach ($wishlists as $wishlist) {
			if (!$wishlist instanceof WishlistDetails || !$wishlist->getItems()) {
				continue;
			}

			$info = [
				'userId' => $wishlist->getUserId(),
				'name' => $wishlist->getName(),
				'phone' => $wishlist->getPhone(),
				'email' => $wishlist->getEmail(),
				'emailConfirmed' => $wishlist->isEmailConfirmed(),
				'headerCity' => $wishlist->getHeaderCity(),
				'products' => array_map(
					static fn(WishlistProduct $product) => [
						'id' => $product->getId(),
						'name' => $product->getName(),
						'url' => $product->getUrl(),
						'price' => $product->getPrice(),
					],
					$wishlist->getItems(),
				)
			];

			if ($wishlist->getUserId()) {
				$data['userWishlists'][] = $info;
			} else {
				$wishlist['guestWishlists'][] = $info;
			}
		}

		MailService::sendTemplatedMail($data, $props, $email, $title);
	}
}
