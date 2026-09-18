<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Price;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Utils\MailService;
use Rusgeocom\Rusgeocom\Utils\Url;

final class AwaitProductMailSender
{
	public static function sendMessage(int $productId, string $userEmail, string $domain): void
	{
		$product = Catalog::getProductById($productId);
		if (!$product) {
			return;
		}

		$title = 'Заявка на предзаказ';
		$props = [
			'contentTemplate' => 'NotificationPreorderConfirmed',
			'needAfterButtonText' => false,
			'showButton' => false,
			'additionalButtons' => [],
		];
		$price = $product->getPrice();
		$oldPrice = $product->getOldPrice();

		$data = [
			'items' => [
				[
					'name' => $product->getTitle(),
					'url' => Url::makeAbsoluteUrl($product->getUrl(), $domain),
					'image' => Url::makeAbsoluteUrl($product->getDetailPicture()->resize([84])->getSrc()),
					'price' => Price::mailFormat($price),
					'oldPrice' => Price::mailFormat($oldPrice),
					'showOldPrice' => ($oldPrice - $price) > 0,
					'quantity' => null
				],
			],
			'showUserAuth' => true,
		];

		$saleEmail = GeoLocation::getMail($domain);
		MailService::sendTemplatedMail($data, $props, $userEmail, $title, [], $saleEmail);
	}
}
