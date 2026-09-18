<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog;

use Rusgeocom\Rusgeocom\Utils\MailService;
use Rusgeocom\Rusgeocom\Utils\Url;

class PriceReductionMailSender
{
	public function sendMessage(string $email, array $reductions): void
	{
		$title = 'Снижение цены на товары';
		$props = [
			'contentTemplate' => 'NotificationPriceReduction',
			'showButton' => false,
		];

		$data = [
			'products' => [],
		];
		foreach ($reductions as $reduction) {
			$product = $reduction['product'];
			$productImage = $product->getDetailPicture()->resize([84]);
			$oldPrice = $reduction['oldPrice'];
			$data['products'][] = [
				'productName' => $product->getTitle(),
				'linkToProduct' => Url::makeAbsoluteUrl($product->getUrl(), 'www'),
				'productImageUrl' => 'https://' . $_SERVER['SERVER_NAME'] . $productImage->getSrc(),
				'price' => Price::mailFormat($price = $product->getCurrentPrice()),
				'oldPrice' => Price::mailFormat($oldPrice),
				'showOldPrice' => ($oldPrice - $price) > 0,
				'quantity' => null
			];
		}

		MailService::sendTemplatedMail($data, $props, $email, $title);
	}
}