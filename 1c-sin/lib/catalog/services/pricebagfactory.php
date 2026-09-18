<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Entities\PriceBag;
use Rusgeocom\Rusgeocom\Catalog\Entities\WholesalePrice;
use Rusgeocom\Rusgeocom\Catalog\Price;

class PriceBagFactory
{
	private static ?PriceBagFactory $instance = null;

	public static function getInstance(): static
	{
		if (!static::$instance){
			static::$instance = new static();
		}

		return static::$instance;
	}

	public function makeFromProductProperties(array $product): PriceBag
	{
		$props = $product['PROPERTIES'];
		$mainOffer = [];
		foreach ($product['OFFERS'] as $offer){
			if (!$offer['PROPERTIES']['CHECK']['VALUE']){
				$mainOffer = $offer;
				break;
			}
		}

		Price::fillPriceForProduct($product, $mainOffer);

		$oldComplectCheck = $props['COMPLECT_MAIN_PRODUCT'] && $props['COMPLECT_ITEMS'] && $props['UUID_1S_MULTI'];
		$isComplect = $props["IS_CORRECT_COMPLECT"] || $oldComplectCheck;
		$basePrice = Price::getPriceForProduct(BASE_PRICE_ID, $product, $mainOffer) ?? 0; //исходная базовая цена
		$currentPrice = (int)$product['PRICE'];
		if (!$isComplect && $currentPrice === $basePrice) {
			$priceModifier = (int)$product['PROPERTIES'][Price::PRICE_MODIFIER_CODE] ?: 0;
			$currentPrice = max($basePrice + $priceModifier, 0);
		}

		$oldPrice = $this->makeOldPrice($product, $basePrice, $isComplect);

		return new PriceBag(
			[
				PriceTypes::RRC_PRICE_CODE => $basePrice, // Для обратной совместимости, может уже не нужно
				BASE_PRICE_CODE => $basePrice,
				PriceTypes::CURRENT_PRICE_KEY => $currentPrice,
				PriceTypes::OLD_PRICE_KEY => $oldPrice,
				PriceTypes::USER_PRICE_CODE => Price::getUserPriceForProduct($product),
				PriceTypes::COMPANY_PRICE_CODE => Price::getCompanyPriceForProduct($product),
				PriceTypes::WHOLESALE_PRICE_CODE_PREFIX => $this->makeWholesalePrices($product, $basePrice, $oldPrice),
			]
		);
	}

	private function makeWholesalePrices(array $product, int $basePrice, int $oldPrice): array
	{
		$wholesalePrices = [];

		foreach (Price::getWholesalePrices($product) as $level => $sourcePrice) {
			$key = Price::WHOLESALE_PRICE_PREFIX . $level;
			$wholesalePrices[$key] = new WholesalePrice(
				$level,
				$currentPrice = $sourcePrice['PRICE'],
				$this->getDashedPrice($currentPrice, $oldPrice),
				$sourcePrice['QUANTITY'] ?? 0
			);
		}

		if (!$wholesalePrices) {
			return [];
		}

		$firstLevelKey = Price::WHOLESALE_PRICE_PREFIX . '1';
		if (!$wholesalePrices[$firstLevelKey]) {
			$wholesalePrices[$firstLevelKey] = new WholesalePrice(
				1,
				$basePrice,
				$this->getDashedPrice($basePrice, $oldPrice),
			);
		}

		usort($wholesalePrices, static function (WholesalePrice $a, WholesalePrice $b) {
			return $a->getLevel() <=> $b->getLevel();
		});

		return $wholesalePrices;
	}

	private function getDashedPrice(int $currentPrice, int $oldPrice): int
	{
		return $oldPrice > $currentPrice ? $oldPrice : 0;
	}

	public function makeOldPrice(array $product, int $basePrice, bool $isComplect): int
	{
		$currentPrice = $product['PRICE'];
		$maxPrice = max($currentPrice, $basePrice);
		if (!$isComplect) {
			//Есть модификатор цены, который уменьшит цену, и не заполнена старая цена
			if (!$product['PROPERTIES']['PRICE_OLD']) {
				return $maxPrice > $currentPrice ? $maxPrice : 0;
			}

			$oldPrice = (int)$product['PROPERTIES']['PRICE_OLD'];
			//Вернем старую цену, если она больше розничной
			if ($oldPrice > $maxPrice) {
				return $oldPrice;
			}
			return $basePrice > $currentPrice ? $basePrice : 0;
		}

		//Для комплектов смотрим только на заполненеи свойства
		$oldPrice = (int)$product['PROPERTIES']['PRICE_OLD'];
		return $oldPrice > $maxPrice ? $oldPrice : 0;
	}
}