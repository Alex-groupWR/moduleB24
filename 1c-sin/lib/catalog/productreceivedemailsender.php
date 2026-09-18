<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Tables\CatalogReceivedProductEmailNotificationTable;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Utils\MailService;
use Rusgeocom\Rusgeocom\Utils\Url;
use Bitrix\Main\EventManager;

class ProductReceivedEmailSender
{
	public static function bindEvents(): void
	{
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', [__CLASS__, 'onAfterIBlockElementUpdate']);
	}

	public static function onAfterIBlockElementUpdate(array $fields): void
	{
		try {
			$isProductInStock = (bool)Availability::getInStockProductIds([$fields['ID']])[$fields['ID']];
			$product = Catalog::getProductById($fields['ID']);

			if ($isProductInStock && $product) {
				foreach (static::getRowsByProductId($fields['ID']) as $row) {
					static::sendMessage($product, $row['EMAIL'], $row['DOMAIN']);
					static::deactivateRow($row['ID']);
				}
			}
		} catch (\Throwable $e) {
			return;
		}
	}

	private static function getRowsByProductId(int $productId): array
	{
		return CatalogReceivedProductEmailNotificationTable::getList([
			'select' => [
				'ID',
				'ACTIVE',
				'PRODUCT_ID',
				'EMAIL',
				'DOMAIN',
			],
			'filter' => [
				'PRODUCT_ID' => $productId,
				'ACTIVE' => 'Y',
			]
		])->fetchAll();
	}

	private static function sendMessage(Product $product, string $email, string $domain): void
	{
		$title = 'Товар в наличии';
		$props = [
			'contentTemplate' => 'NotificationProductAvailable',
		];
		$price = $product->getPrice();
		$oldPrice = $product->getOldPrice();

		$data = [
			'products' => [
				[
					'name' => $product->getTitle(),
					'url' => Url::makeAbsoluteUrl($product->getUrl(), $domain),
					'image' => 'https://' . $_SERVER['SERVER_NAME'] . $product->getDetailPicture()->resize([84])->getSrc(),
					'price' => Price::mailFormat($price),
					'oldPrice' => Price::mailFormat($oldPrice),
					'showOldPrice' => ($oldPrice - $price) > 0,
					'quantity' => null
				],
			],
			'showUserAuth' => true,
			'buttonLink' => Url::makeAbsoluteUrl($product->getUrl(), $domain),
			'buttonText' => 'Добавить в корзину',
		];

		$saleEmail = GeoLocation::getMail($domain);

		MailService::sendTemplatedMail($data, $props, $email, $title, [], $saleEmail);
	}

	private static function deactivateRow(int $id): void
	{
		CatalogReceivedProductEmailNotificationTable::update($id, ['ACTIVE' => 'N']);
	}
}