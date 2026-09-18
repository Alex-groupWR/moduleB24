<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\Elements\ElementCatalogTable;
use Bitrix\Main\DI\ServiceLocator;
use CIBlockSection;
use Exception;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Core\Discounts\Enums\DiscountAmountView;
use Rusgeocom\Core\Discounts\Enums\GuestDiscountView;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResult;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResultSlim;
use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductOffer;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductOption;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductVariant;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;
use Rusgeocom\Rusgeocom\Sale\Services\CustomerService;
use Rusgeocom\Rusgeocom\Utils\Cache;
use Rusgeocom\Rusgeocom\Utils\Iblock;
use Rusgeocom\Rusgeocom\Utils\User;

class Catalog
{
	public const PAGE_PRODUCT_COUNT = 32;
	private static $urlToSectionIdCache = [];

	public static function query(CatalogQueryParams $params): CatalogResult
	{
		/** @var CatalogResult $result */
		$result = self::queryBase(CatalogProcessor::create($params));

		if ($params->needManagerStocks()) {
			$result->getProducts()->fillManagerStocks($params->getDomain(), $params->getSectionId());
		}

		if ($params->needWholesalePrices()) {
			$result->getProducts()->fillWholesalePrices();
		}

		if ($params->needDiscountPreview()) {
			$result->getProducts()->fillDiscountPreviews();
		}

		$result->getProducts()->fillDiscountViewParams();
		$result->getProducts()->fillPickupRestrictionType();
		if ($params->needDiscountBags()) {
			$customer = ServiceLocator::getInstance()->get(CustomerService::class)->getCurrentCustomerAccount();
			$result->getProducts()->fillDiscountBags($customer);
			$result->getProducts()->fillCalculatedPrices(
				$customer,
				$params->needDetailPriceCalculation(),
			);
		}

		if (User::isAuthorized()) {
			$result->getProducts()->hideAuthorizationHint();
		}

		return $result;
	}

	public static function querySlim(CatalogQueryParams $params): CatalogResultSlim
	{
		return self::queryBase(CatalogProcessorSlim::create($params));
	}

	private static function queryBase(CatalogProcessorBase $processor): CatalogResult|CatalogResultSlim
	{
		$callback = function() use ($processor) {
			return $processor->execute();
		};

		/** @var CatalogResultSlim $result */
		$result = Cache::create()
			->addCondition(!User::isAuthorized()) // Для авторизованных не кешируем
			->addTag($processor::class)
			->setIblockId(CATALOG_IBLOCK_ID)
			->addKey($processor::class)
			->addKey($processor->getParams()->getHash())
			->setCallback($callback)
			->setTime(900)
			->getResult();

		return $result;
	}

	/**
	 * @param CatalogQueryParams $params
	 * @return int[]
	 */
	public static function queryProductIds(CatalogQueryParams $params): array
	{
		return CatalogProcessor::create($params)->getProductIds();
	}

	private static function getOfferSelect(): array
	{
		return array_merge(
			[
				'ID',
				'NAME',
				'PREVIEW_TEXT',
				'PROPERTY_CHECK',
				'PROPERTY_CML2_LINK',
				'PROPERTY_POVERKA',
				'PROPERTY_H1',
				'PROPERTY_ARTIKUL',
				'PROPERTY_ID_NOACTIV',
				'PROPERTY_REQUIRED_IDS',
				'PROPERTY_WORK_DAYS_ADD',
				'PROPERTY_RECEIPT_WITHOUT_VAT',
			],
			PriceTypes::getPriceFields()
		);
	}

	public static function getVariantById(int $id): ?ProductVariant
	{
		$select = static::getOfferSelect();
		$filter = [
			'=ID' => $id,
			'=ACTIVE' => 'Y',
			'=PROPERTY_CHECK' => false,
			'=PROPERTY_POVERKA' => false,
		];
		$ibElement = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)->getElementByFilter($filter, $select);
		if (!$ibElement) {
			return null;
		}

		return static::makeOffersFromIbElements([$ibElement])[$ibElement['ID']];
	}

	public static function getVariantsForProduct(int $productId): array
	{
		if (!$productId) {
			return [];
		}

		$select = static::getOfferSelect();
		$filter = [
			'=PROPERTY_CML2_LINK' => $productId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_CHECK' => false,
			'=PROPERTY_POVERKA' => false,
		];
		$sort = ['SORT' => 'ASC'];
		$ibElements = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)
			->getElementsByFilter($filter, $select, 0, $sort);
		return static::makeOffersFromIbElements($ibElements);
	}

	private static function makeOffersFromIbElements(array $ibElements): array
	{
		$productIds = array_unique(array_column($ibElements, 'PROPERTY_CML2_LINK_VALUE'));

		$hasAuthDiscount = Price::isAuthorizedDiscountForCurrentUser();
		$prices = static::getMainProductPrices($productIds);
		$priceModifiers = [];

		$dataProperties = static::getAdditionalPropertiesForProducts($productIds);
		$isComplectList = [];
		foreach ($dataProperties as $data) {
			switch ($data['PROPERTY_CODE']) {
				case "PRICE_MODIFIER" :
					$priceModifiers[$data['IBLOCK_ELEMENT_ID']] = (int)$data['VALUE'];
					break;
				case 'IS_CORRECT_COMPLECT' :
					if((bool)$data["VALUE"]){
						$isComplectList[] = $data['IBLOCK_ELEMENT_ID'];
					}
					break;
			}
		}
		foreach ($isComplectList as $idElement) {
			unset($priceModifiers[$idElement]);
		}
		$offers = [];
		foreach ($ibElements as $ibElement) {
			if ($ibElement['PROPERTIES']['CHECK']['VALUE'] || $ibElement['PROPERTIES']['POVERKA']['VALUE']) {
				$offers[$ibElement['ID']] = new ProductOption($ibElement);
			} else {
				$productId = $ibElement['PROPERTY_CML2_LINK_VALUE'];
				$price = $prices[$productId] ?? [];
				foreach(PriceTypes::getReplaceableTypes() as $priceType) {
					if (isset($price[$priceType->getPriceProperty()])) {
						$ibElement[$priceType->getPriceProperty()] = $price[$priceType->getPriceProperty()];
						$ibElement[$priceType->getCurrencyProperty()] = $price[$priceType->getCurrencyProperty()];
					}
				}
				$offers[$ibElement['ID']] = new ProductVariant(
					$ibElement,
					$hasAuthDiscount
						? 0
						: ($priceModifiers[$productId] ?? 0),
				);
			}
		}

		return $offers;
	}

	private static function getAdditionalPropertiesForProducts(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$properties = [
			Price::PRICE_MODIFIER_CODE,
			'IS_CORRECT_COMPLECT',
		];
		//достаём скидку для физ лица и модификатор цен для всех офферов
		return IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->where('IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->where('IBLOCK_ELEMENT.ACTIVE', 'Y')
			->whereIn('IBLOCK_ELEMENT_ID', $productIds)
			->whereIn('IBLOCK_PROPERTY.CODE', $properties)
			->setCacheTtl(60)
			->cacheJoins(true)
			->fetchAll();
	}

	private static function getMainProductPrices(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$prices = [];
		$iterator = PriceTable::query()
			->addSelect('PRODUCT_ID')
			->addSelect('PRICE')
			->addSelect('CURRENCY')
			->addSelect('CATALOG_GROUP_ID')
			->whereIn('CATALOG_GROUP_ID', PriceTypes::getIdForAuthorizedDiscountPriceTypes())
			->whereIn('PRODUCT_ID', $productIds)
			->exec();
		while ($row = $iterator->fetch()) {
			$typeId = $row['CATALOG_GROUP_ID'];
			$prices[$row['PRODUCT_ID']]['CATALOG_PRICE_' . $typeId] = $row['PRICE'];
			$prices[$row['PRODUCT_ID']]['CATALOG_CURRENCY_' . $typeId] = $row['CURRENCY'];
		}

		return $prices;
	}

	/**
	 * @param int $productId
	 * @return ProductOffer[]
	 */
	public static function getOffersForProduct(int $productId): array
	{
		if (!$productId) {
			return [];
		}

		$select = static::getOfferSelect();
		$filter = [
			'=PROPERTY_CML2_LINK' => $productId,
			'=ACTIVE' => 'Y',
		];
		$sort = ['SORT' => 'ASC'];
		$ibElements = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)
			->getElementsByFilter($filter, $select, 0, $sort);

		return static::makeOffersFromIbElements($ibElements);
	}

	/**
	 * @return ProductOffer[]
	 */
	public static function getOffersByIds(array $offerIds, bool $withInactive = false): array
	{
		if (!$offerIds) {
			return [];
		}

		$select = static::getOfferSelect();
		$filter = [
			'=ID' => $offerIds,
		];
		if (!$withInactive) {
			$filter['=ACTIVE'] = 'Y';
		}
		$sort = ['SORT' => 'ASC'];
		$ibElements = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)
			->getElementsByFilter($filter, $select, 0, $sort);

		return static::makeOffersFromIbElements($ibElements);
	}

	/**
	 * @param int[] $optionIds
	 * @return ProductOption[]
	 */
	public static function getOptionsByIds(array $optionIds): array
	{
		$options = self::getOffersByIds($optionIds);
		foreach ($options as $option) {
			if (!($option instanceof ProductOption)) {
				throw new Exception('В списке есть не только опции');
			}
		}

		return $options;
	}

	public static function getCountByFilter(array $filter, int $sectionId = 0): int
	{
		$params = CatalogQueryParams::create()
			->setFilter($filter)
			->setSectionId($sectionId)
			->setShowComplects(true)
			->setShowOutOfStock(true)
			->setShowAccessors(true);

		return CatalogProcessor::create($params)->getTotalCount();
	}

	public static function getProductById(int $id): ?Product
	{
		return static::getProductsByIds([$id])->getById($id);
	}

	public static function checkExistsById(int $id): bool
	{
		return static::getCountByFilter(['ID' => $id]) > 0;
	}

	public static function getProductsByIds(
		array $ids,
		bool $withInactive = false,
		bool $withDetailPrices = false,
	): ProductCollection {
		if (!$ids) {
			return new ProductCollection();
		}

		$params = CatalogQueryParams::create()
			->setShowComplects(true)
			->setShowArchive(true)
			->setShowAccessors(true)
			->setShowInactive($withInactive)
			->setNeedDetailPriceCalculation($withDetailPrices)
			->disablePagination()
			->addSort('ID', $ids)
			->addFilter('ID', $ids);

		return static::query($params)->getProducts();
	}

	public static function getProductIdsByOfferIds(array $offerIds): array
	{
		if (!$offerIds) {
			return [];
		}

		$select = [
			'ID',
			'PROPERTY_CML2_LINK',
		];

		$offers = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)->getElementsByIds($offerIds, $select);
		$map = [];
		foreach ($offers as $offer) {
			$map[$offer['ID']] = $offer['PROPERTIES']['CML2_LINK']['VALUE'];
		}

		return $map;
	}

	public static function getProductIdByOfferId(int $offerId): int
	{
		return static::getProductIdsByOfferIds([$offerId])[$offerId];
	}

	/**
	 * Какие-то костыли из старого компонента каталога
	 *
	 * @param string $url
	 * @return int
	 */
	public static function extractSectionIdFromUrl(string $url): int
	{
		if (!static::$urlToSectionIdCache[$url]) {
			$page = $url;
			$pozi = strripos($page, '/f?price-from');

			if ($pozi !== false) {
				$prer = explode('?', $page);

				$prer = explode('/f', $prer[0]);
			} else {
				$prer = explode('?', $page);

				$prer = explode('/f/', $prer[0]);
			}


			$rest = substr($prer[0], -1);
			if ($rest == '/') {
				$prer[0] = substr($prer[0], 0, -1);
			}
			$pres = explode('/index.php', $prer[0]);


			$uf_arresult = CIBlockSection::GetList(['SORT' => 'DESC'],
				["IBLOCK_ID" => CATALOG_IBLOCK_ID, "UF_ALIASE" => $pres[0]], false,
				['ID', 'UF_ALIASE', 'CODE'], ['nTopCount' => 1]);
			if ($uf_value = $uf_arresult->Fetch()) {
				$sectiont = $uf_value;
			}

			if ($prer[0][0] == '/') {
				$preg = explode('/', $page);

				unset($preg[0]);
				$pregs = implode('/', $preg);

				$pregs = explode('?', $pregs);
				$pregs[0] = explode('/f/', $pregs[0])[0];
			}
			if (empty($sectiont)) {
				$uf_arresult = CIBlockSection::GetList([],
					["IBLOCK_ID" => CATALOG_IBLOCK_ID, "UF_ALIASE" => $pregs[0]], false,
					['ID', 'UF_ALIASE', 'CODE'], ['nTopCount' => 1]);
				if ($uf_value = $uf_arresult->Fetch()) {
					$sectiont = $uf_value;
				}
			}

			$result = '';

			if (!empty($sectiont)) {
				$result = $sectiont['ID'];
			}

			static::$urlToSectionIdCache[$url] = $result ?: 0;
		}

		return static::$urlToSectionIdCache[$url];
	}

	public static function hasPickupRestrictions(Product $product): bool
	{
		if (!$product->isComplect()) {
			return $product->isPickupRestricted();
		}

		return IblockElementPropertyTable::getCount(
				[
					'IBLOCK_ELEMENT_ID' => array_column($product->getComplectItemsIdQuantity(), 'id'),
					'IBLOCK_PROPERTY.CODE' => 'RESTRICT_PICKUP',
				]
			) > 0;
	}

	public static function getDiscountViewParams(array $productIds): array
	{
		$enums = Iblock::getEnumerationXmlIdByCodes(
			['GUEST_DISCOUNT_VIEW', 'DISCOUNT_DISPLAY_TYPE'],
			CATALOG_IBLOCK_ID
		);

		$iterator = ElementCatalogTable::query()
			->addSelect('ID', 'PRODUCT_ID')
			->addSelect('GUEST_DISCOUNT_VIEW.VALUE', 'GUEST_DISCOUNT_VIEW_ID')
			->addSelect('DISCOUNT_DISPLAY_TYPE.VALUE', 'DISCOUNT_DISPLAY_TYPE_ID')
			->whereIn('ID', $productIds)
			->exec();

		$params = [];
		while ($properties = $iterator->fetch()) {
			$previewTypeName = $enums[$properties['GUEST_DISCOUNT_VIEW_ID']] ?? '';
			$viewType = $properties['GUEST_DISCOUNT_VIEW_ID']
				? (GuestDiscountView::tryFrom($previewTypeName) ?? GuestDiscountView::None)
				: GuestDiscountView::None;

			$displayTypeName = $enums[$properties['DISCOUNT_DISPLAY_TYPE_ID']] ?? '';
			$displayType = $properties['DISCOUNT_DISPLAY_TYPE_ID']
				? (DiscountAmountView::tryFrom($displayTypeName) ?? DiscountAmountView::Percent)
				: ($viewType === GuestDiscountView::FirstBuy ? DiscountAmountView::Total : DiscountAmountView::Percent);

			$params[(int)$properties['PRODUCT_ID']] = [
				'GUEST_DISCOUNT_VIEW' => $viewType,
				'DISCOUNT_DISPLAY_TYPE' => $displayType,
			];
		}

		return $params;
	}

	public static function getIblockHelper(): IblockHelper
	{
		return IblockHelper::forIblock(CATALOG_IBLOCK_ID);
	}
}