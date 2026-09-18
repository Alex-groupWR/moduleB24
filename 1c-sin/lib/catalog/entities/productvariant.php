<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Catalog\Price as CatalogPrice;

class ProductVariant extends ProductOffer
{
	public function __construct(array $iblockElement, int $priceModifier = 0)
	{
		parent::__construct($iblockElement);
		CatalogPrice::fillPriceForProduct($iblockElement);
		$this->priceWithDiscount = $iblockElement['PRICE'] + $priceModifier;
	}
}