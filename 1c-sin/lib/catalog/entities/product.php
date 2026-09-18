<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Logema\Utils\TextHelper;
use Rusgeocom\Core\Discounts\Contracts\CalculatedPriceInterface;
use Rusgeocom\Core\Discounts\Contracts\PriceBagInterface;
use Rusgeocom\Core\Discounts\Enums\DiscountAmountView;
use Rusgeocom\Core\Discounts\Enums\GuestDiscountView;
use Rusgeocom\Core\Utils\PriceUtils;
use Rusgeocom\Rusgeocom\Api\Helpers\CatalogDetailHelper;
use Rusgeocom\Rusgeocom\Catalog\Enums\PickupRestriction;
use Rusgeocom\Rusgeocom\Catalog\Price;
use Rusgeocom\Rusgeocom\Catalog\Services\Complects;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Payment\Services\PaymentService;
use Rusgeocom\Rusgeocom\Sale\Entities\Discounts\DiscountBag;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Types\Uri;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use Rusgeocom\Rusgeocom\Ui\Tools;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Rusgeocom\Rusgeocom\Utils\Url;

class Product implements \JsonSerializable, ReceiptProduct
{
	public const MAIN_BUTTON_TYPE_BUY = 'buy'; // В корзину
	public const MAIN_BUTTON_TYPE_INFORM_ADMISSION = 'inform-admission'; // Сообщить о поступлении
	public const MAIN_BUTTON_TYPE_ORDER = 'order'; // Заказать (используется только в поверке)
	public const MAIN_BUTTON_TYPE_SEND_PRICE_REQUEST = 'send-price-request'; // Кнопка оставить заявку (заказать), а попап сообщить о поступлении
	public const MAIN_BUTTON_TYPE_INVOICE_PRICE = 'invoice-price'; // Запросить цену
	public const MAIN_BUTTON_TYPE_CHOOSE_ANALOGUE = 'analog-request'; //Подобрать аналог
	public const STOCK_STATUS_IN_STOCK = [
		'name' => 'В наличии',
		'color' => '#0c994d',
	];
	public const STOCK_STATUS_OUT_OF_STOCK = [
		'name' => 'Нет в наличии',
		'color' => '#8f8f8f',
	];
	public const STOCK_STATUS_ON_REQUEST = [
		'name' => 'Под заказ',
		'color' => '#8f8f8f',
	];

	public const STOCK_STATUS_WAITING = [
		'name' => 'Ожидается',
		'color' => '#8f8f8f',
	];

	private const SHOW_OUT_OF_STOCK_YES = '1418';
	private const HIDE_UNDER_ORDER_YES = '3009';
	private const OUT_OF_PRODUCTION = '1363';
	private const ARCHIVE_YES = '2058';
	private const BADGE_DETAIL_PAGE_COUNT = 3;
	private const BADGE_SECTION_PAGE_COUNT = 2;
	public const string FIRST_DISCOUNT_HINT = 'Скидка действует на первую покупку после регистрации';

	/** @var array */
	protected $rating;

	/** @var array */
	protected $stocks = [];

	/** @var int */
	protected $detailPictureId;

	protected $mainButtonType = '';

	protected $sectionId = 0;
	protected $currency = '';
	protected $isPriceOnRequest = false;
	protected $isShowOutOfStock = false;
	protected $isShowStockLabel = true;
	protected $canCompare = false;
	protected $hasVerificationCertificate = false;
	protected $url = '';
	protected $name = '';
	protected $nameEn = '';
	protected $title = '';
	protected $description = '';
	protected $id = 0;
	protected $defaultVariantId = 0;
	/** @var Badge[] $badges */
	protected $badges = [];
	protected $stockStatus;
	protected $isComplect = false;
	protected $complectName = '';
	protected $complectItems = [];
	protected $complectItemsIdQuantity = [];
	protected $complectItemsInStocks = [];
	protected $isComplectAvailableInStocks = false;
	protected $otherImages;
	protected array $photoDescriptions;
	protected $gosreestrNumber = '';
	protected $isManagerPreview = false;
	protected $images3d = [];
	protected $canAddToFavorite;
	/**
	 * @deprecated замена на enum PickupRestriction
	 */
	protected bool $isPickupRestricted;
	protected ?int $pickupRestrictionId = null;
	protected ?PickupRestriction $pickupRestriction = null;

	protected bool $isShowToWholesalers;

	private $imageLimit;
	private $canPayByCard = true;
	protected $vendorResetTime = 0;
	private $accessorSort;
	protected string $brand;
	protected ?string $uniqueOffer;
	private $hideVatText;
	public $additionalWorkingDays;

	/** @var Image */
	protected $detailPicture;
	protected string $articul;
	protected bool $showWholesalePrices = false;
	protected bool $showDiscountPreview = false;
	protected bool $needVatInReceipt = false;
	protected string $discountHint = '';
	protected PriceBag $priceBag;
	protected ?DiscountBag $discountBag = null;
	protected GuestDiscountView $discountView;
	protected bool $authorizationHintVisible = true;
	protected bool $indexingDisabled = false;
	protected DiscountAmountView $discountDisplay;
	protected bool $isArchive = false;
	protected ?CalculatedPriceInterface $calculatedPrice = null;
	protected bool $applyDiscountsWithoutStock = false;

	public function __construct(
		array $iblockElement,
		array $rating,
		int $inStock,
		array $badges,
		PriceBag $priceBag,
	) {
		$this->rating = $rating;
		$props = $iblockElement['PROPERTIES'];
		$this->badges = $badges;
		$this->gosreestrNumber = $props["CODE_GOSREESTR"];
		$this->priceBag = $priceBag;

		$mainOffer = [];
		foreach ($iblockElement['OFFERS'] as $offer){
			if (!$offer['PROPERTIES']['CHECK']['VALUE']){
				$mainOffer = $offer;
				break;
			}
		}

		$this->makeImagesFor3D($props['IMAGES_3D_FOLDER'] ?: '');

		$this->articul = $props['ARTICUL'] ?: '';
		$oldComplectCheck = $props['COMPLECT_MAIN_PRODUCT'] && $props['COMPLECT_ITEMS'] && $props['UUID_1S_MULTI'];
		$this->isComplect = $props["IS_CORRECT_COMPLECT"] || $oldComplectCheck;
		$this->name = trim(htmlspecialchars_decode($iblockElement['NAME'])) ?: '';
		$this->nameEn = trim(htmlspecialchars_decode($props['NAME_EN'])) ?: '';
		$this->id = $iblockElement['ID'] ?: 0;
		$this->accessorSort = $props['ACCESSOR_SORT'];
		$this->sectionId = $iblockElement['IBLOCK_SECTION_ID'] ?: 0;
		$this->photoDescriptions = $iblockElement['PHOTO_DESCRIPTIONS'] ?: [];
		$this->complectName = $props['COMPLECT_NAME'] ?: '';
		$this->complectItems = $props['COMPLECT_ITEMS'];
		$this->isPriceOnRequest = !!$props['ON_REQUEST'] || !!$props['PRICE_ON_REQUEST'];
		$this->detailPictureId = $iblockElement['DETAIL_PICTURE'] ?: 0;
		$this->otherImages = $props['PHOTO_OTHER'] ?: null;
		$this->isShowOutOfStock = $props['SHOW_OUT_OF_STOCK'] == 'Да'; //TODO: Поле используется в catalogDetailPage для совместимости, удалить когда будет не нужно
		$this->canCompare = !!$props['COMPARE_GROUP'];
		$this->isArchive = $props['ARCHIVE'] === static::ARCHIVE_YES;
		$this->canAddToFavorite = !$this->isArchive;
		$this->url = $props['ALIASE'] ? Url::formatSlash($props['ALIASE']) : Url::DEFAULT_URL;
		$this->hasVerificationCertificate = !!$props['HAS_VERIFICATION_CERTIFICATE'];
		$this->title = $props['H1'] ? trim(htmlspecialchars_decode($props['H1'])) : $this->getName();
		$this->description = $iblockElement['PREVIEW_TEXT'];
		$this->defaultVariantId = $iblockElement['OFFER_ID'] ?: $mainOffer['ID'] ?: $this->id;
		$this->currency = $iblockElement['CURRENT_CURRENCY'];
		$this->vendorResetTime = (int)$props['VENDOR_RESET_TIME'];
		$this->brand = $props['BRAND_REF'] ?: '';
		$this->hideVatText = (bool)$props['HIDE_VAT_TEXT'];
		$this->additionalWorkingDays = $props['WORK_DAYS_ADD'];
		$this->isPickupRestricted = $props['RESTRICT_PICKUP'] !== null;
		$this->pickupRestrictionId = (int)$props['RESTRICT_PICKUP'];
		$this->uniqueOffer = $props['UNIQUE_TRADE_OFFER'];
		$this->isShowToWholesalers = $props['DONT_SHOW_TO_WHOLESALERS'] === null;
		$this->needVatInReceipt = empty($props['RECEIPT_WITHOUT_VAT']);
		$this->discountHint = $props['AUTH_DISCOUNT_HINT'] ?? '';
		$this->discountView = GuestDiscountView::None;
		$this->applyDiscountsWithoutStock = $props['APPLY_DISCOUNTS_WITHOUT_STOCK'] !== null;

		// Неактивные сюда попадут только при особом фильтре для менеджеров
		$this->isManagerPreview = $iblockElement['ACTIVE'] !== 'Y';
		$this->imageLimit = Settings::getProductImagesLimitInSlider();
		$this->indexingDisabled = (bool)$props['ADD_ROBOTS_NOINDEX'];
		$this->discountDisplay = DiscountAmountView::Percent;

		$this->setStockStatusAndMainButtonType($inStock, $props);
	}

	public function setDiscountViewParams(GuestDiscountView $discountView, DiscountAmountView $discountDisplay): void
	{
		$this->discountView = $discountView;
		$this->discountDisplay = $discountDisplay;
	}

	public function setCalculatedPrice(?CalculatedPriceInterface $calculatedPrice): void
	{
		$this->calculatedPrice = $calculatedPrice;
	}

	public function hideVatText(): bool
	{
		return $this->hideVatText;
	}

	public function hideAuthorizationHint(): void
	{
		$this->authorizationHintVisible = false;
	}

	public function setStockStatusAndMainButtonType(int $inStock, $props): void
	{
		$isComplect = $this->isComplect();
		$onlyInDlrStoreId = false;

		if ($isComplect) {
			$this->complectItemsInStocks = Complects::getQuantityComplectItemsInStocks($this->complectItems);
			$this->complectItemsIdQuantity = Complects::getComplectItemsIdsAndQuantity($this->complectItems);
			$this->isComplectAvailableInStocks = Complects::checkAvailableComplect(
				$this->complectItemsInStocks, $this->complectItemsIdQuantity
			);
			$onlyInDlrStoreId = !Complects::checkAvailableComplectWithoutDlrStore(
				$this->complectItemsInStocks, $this->complectItemsIdQuantity
			);
		}

		$isProductInStock = (!$isComplect && $inStock > 0) || ($isComplect && $this->isComplectAvailableInStocks());
		$hasPrice = !$this->isPriceOnRequest() && $this->getPrice();
		$hasTransitPostav = $props['TRANSIT_POSTAV'] > 0;
		$isHideUnderOrder = $props['HIDE_UNDER_ORDER'] == static::HIDE_UNDER_ORDER_YES;
		$isShowOutOfStock = $props['SHOW_OUT_OF_STOCK'] == static::SHOW_OUT_OF_STOCK_YES;
		$isOutOfProduction = current($props['RECOMM_TYPE']) == static::OUT_OF_PRODUCTION;

		if ($hasPrice) {
			if ($isProductInStock) {
				// Цена есть, товар есть на любом складе
				$this->mainButtonType = static::MAIN_BUTTON_TYPE_BUY;
				$this->stockStatus = static::STOCK_STATUS_IN_STOCK;
				if ($onlyInDlrStoreId) {
					$this->canPayByCard = false;
				}
			} else {
				$this->canPayByCard = false;
				$this->mainButtonType = static::MAIN_BUTTON_TYPE_BUY;
				if ($hasTransitPostav) {
					//Цена есть, товара нет на складе, свойство "Транзит Поставщик" > 0
					$this->stockStatus = static::STOCK_STATUS_WAITING;
				} elseif ($inStock < 0) {
					//Цена есть, товар есть на любом складе в отрицательном количестве
					$this->stockStatus = static::STOCK_STATUS_IN_STOCK;
				} else {
					// Цена есть, товара нет на складе, пустой транзит поставщика
					if ($isOutOfProduction) {
						// Цена есть, товара нет, товар снят с производства
						$this->mainButtonType = static::MAIN_BUTTON_TYPE_INFORM_ADMISSION;
						$this->stockStatus = static::STOCK_STATUS_OUT_OF_STOCK;
					} else if ($isHideUnderOrder) {
						$this->stockStatus = [];
					} elseif ($isShowOutOfStock) {
						$this->stockStatus = static::STOCK_STATUS_OUT_OF_STOCK;
					} else {
						$this->stockStatus = static::STOCK_STATUS_ON_REQUEST;
					}
				}
			}
		} else {
			if ($isProductInStock) {
				$this->mainButtonType = static::MAIN_BUTTON_TYPE_INVOICE_PRICE;
				$this->stockStatus = static::STOCK_STATUS_IN_STOCK;
			} else {
				$this->mainButtonType = static::MAIN_BUTTON_TYPE_SEND_PRICE_REQUEST;
				if ($hasTransitPostav) {
					$this->stockStatus = static::STOCK_STATUS_WAITING;
				} elseif ($isOutOfProduction) {
					$this->mainButtonType = static::MAIN_BUTTON_TYPE_CHOOSE_ANALOGUE;
					// Нет цены, нет товара, товар снят с производства
					$this->stockStatus = static::STOCK_STATUS_OUT_OF_STOCK;
				} elseif ($isHideUnderOrder) {
					$this->stockStatus = [];
				} elseif ($isShowOutOfStock) {
					$this->stockStatus = static::STOCK_STATUS_OUT_OF_STOCK;
				} else {
					$this->stockStatus = static::STOCK_STATUS_ON_REQUEST;
				}
			}
		}

		$this->isShowStockLabel =
			$this->inStock()
			|| $this->stockStatus == static::STOCK_STATUS_WAITING
			|| ($this->stockStatus == static::STOCK_STATUS_ON_REQUEST && !$isHideUnderOrder)
			|| ($this->stockStatus == static::STOCK_STATUS_OUT_OF_STOCK && $isShowOutOfStock);
	}

	public function getCalculatedPrice(): ?CalculatedPriceInterface
	{
		return $this->calculatedPrice;
	}

	public function setDiscountBag(?DiscountBag $discountBag): void
	{
		$this->discountBag = $discountBag;
	}

	public function getDiscountBag(): ?DiscountBag
	{
		return $this->discountBag;
	}

	public function hasAuthorizationDiscount(): bool
	{
		if ($this->getDiscountBag()) {
			return $this->getDiscountBag()->hasAuthorizedDiscount();
		}

		return $this->getPrice() !== $this->getBasePrice()
			&& ($this->getPrice() === $this->getUserPrice() || $this->getPrice() === $this->getCompanyPrice());
	}

	public function hasAuthorizationDiscountLabel(): bool
	{
		if($calculatedPrice = $this->getCalculatedPrice()) {
			return $this->authorizationHintVisible
				&& $calculatedPrice->hasAuthorizationLabel();
		}

		return $this->discountView->canDisplayAuthorizationLabel()
			&& $this->hasAuthorizationDiscount()
			&& $this->authorizationHintVisible;
	}

	public function getIndividualDiscount(): int
	{
		//Не вся разница в цене - индивидуальная скидка, разобраться нужны ли правки
		return $this->getBasePrice() - $this->getPrice();
	}

	public function getArticul(): string
	{
		return $this->articul;
	}

	public function setManagerStocks(array $stocks): Product
	{
		$this->stocks = $stocks;
		return $this;
	}

	public function setShowWholesalePrices(bool $value): Product
	{
		$this->showWholesalePrices = $value;
		return $this;
	}

	public function setShowDiscountPreview(bool $value): Product
	{
		$this->showDiscountPreview = $value;
		return $this;
	}

	public function isManagerPreview(): bool
	{
		return $this->isManagerPreview;
	}

	public function getSectionId(): int
	{
		return $this->sectionId;
	}
	public function getAdditionalWorkingDays(): ?string
	{
		return $this->additionalWorkingDays;
	}

	public function getPrice(): int
	{
		return $this->priceBag->get(PriceTypes::CURRENT_PRICE_KEY);
	}

	public function getBasePrice(): int
	{
		return (int)$this->priceBag->get(BASE_PRICE_CODE);
	}

	public function getUserPrice(): ?int
	{
		return $this->priceBag->get(PriceTypes::USER_PRICE_CODE);
	}

	public function getCompanyPrice(): ?int
	{
		return $this->priceBag->get(PriceTypes::COMPANY_PRICE_CODE);
	}

	public function getPriceWithDiscount(): int
	{
		if($calculatedPrice = $this->getCalculatedPrice()) {
			if ($calculatedPrice->isEmpty()) {
				return 0;
			}

			if (!$this->hasAuthorizationDiscountLabel()) {
				return $calculatedPrice->getPrice();
			}

			$oldPrice = $calculatedPrice->getOldPrice() ?: $this->getBasePrice();
			return $oldPrice - $calculatedPrice->getDiscountSum();
		}

		if ($this->discountBag && !$this->discountBag->isEmpty()) {
			$discountAmount = $this->hasAuthorizationDiscountLabel()
				? $this->discountBag->getAuthorizedDiscount()
				: $this->discountBag->getPriorityDiscount();
			return $this->getBasePrice() - $discountAmount;
		}

		return $this->getPrice();
	}

	public function getActualPrice(): int
	{
		if ($calculatedPrice = $this->getCalculatedPrice()) {
			return $calculatedPrice->getPrice();
		}

		if ($this->discountBag) {
			return $this->getBasePrice() - $this->discountBag->getPriorityDiscount();
		}

		return $this->getPrice();
	}

	public function getWholesalePriceByLevel(int $level): ?WholesalePrice
	{
		foreach (($this->priceBag->get(PriceTypes::WHOLESALE_PRICE_CODE_PREFIX) ?? []) as $wholesalePrice) {
			if ($wholesalePrice->getLevel() === $level) {
				return $wholesalePrice;
			}
		}

		return null;
	}

	public function getPriceByType(PriceType $priceType): int
	{
		if ($this->isPriceOnRequest()) {
			return 0;
		}

		if (Str::contains($priceType->getName(), PriceTypes::WHOLESALE_PRICE_CODE_PREFIX)) {
			$price = $this->getWholesalePriceByLevel(
				(int)Str::replace(PriceTypes::WHOLESALE_PRICE_CODE_PREFIX, '', $priceType->getName())
			);
			return $price ? $price->getPrice() : 0;
		}

		return $this->getPrice();
	}

	public function getBrand(): string
	{
		return $this->brand;
	}

	public function getOldPrice(): int
	{
		if ($calculatedPrice = $this->getCalculatedPrice()) {
			$oldPrice = $calculatedPrice->getOldPrice() ?: $this->getBasePrice();
		} else {
			$oldPrice = $this->priceBag->get(PriceTypes::OLD_PRICE_KEY) ?? 0;
		}

		// При наличии скидок 1С - не используем Старую цену
		$guestDiscountHidden = $this->authorizationHintVisible && $this->discountView === GuestDiscountView::None;
		if ($this->getDiscountBag()?->isEmpty() === false && !$guestDiscountHidden) {
			return $this->getBasePrice();
		}

		if (!$oldPrice && $this->getCurrentPrice() < $this->getBasePrice()) {
			return $this->getBasePrice();
		}

		return $oldPrice;
	}

	public function getCurrency(): string
	{
		return $this->currency ?: '';
	}

	public function isPriceOnRequest(): bool
	{
		return $this->isPriceOnRequest;
	}

	public function resizeDetailPicture(array $size, array $smallSize = []): Product
	{
		$this->detailPicture = $this->getDetailPicture()->resize($size, $smallSize);
		return $this;
	}

	public function resizeForAccessorsTab(): Product
	{
		$this->detailPicture = $this->getDetailPicture()->resize([200], [144], [192]);
		return $this;
	}

	public function getDetailPicture(): Image
	{
		if (!$this->detailPicture){
			$this->detailPicture = Image::fromIblockElement(
				$this->detailPictureId,
				$this->photoDescriptions[$this->detailPictureId] ?: $this->getName()
			);
		}

		return $this->detailPicture;
	}

	/**
	 * @return Image[]
	 */
	public function getPhotos(): array
	{
		$photos = [];
		$photos[] = $this->getDetailPicture();
		foreach ($this->otherImages as $photo){
			$photos[] = Image::fromIblockElement($photo, $this->photoDescriptions[$photo] ?: $this->getName());
		}

		foreach ($photos as $index => $photo){
			$photos[$index] = $photo->resizeForProductList();
		}

		return $photos;
	}

	public function getPhotosForDetail(): array
	{
		$photos = [$this->getDetailPicture()];
		foreach ($this->otherImages as $photo){
			$photos[] = Image::fromIblockElement($photo, $this->photoDescriptions[$photo] ?: $this->getName());
		}

		foreach ($photos as $index => $image){
			$photos[$index] = $image->resizeForProductDetail();
		}

		return $photos;
	}

	public function makeImagesFor3D(string $imagesFolder): void
	{
		$this->images3d = [];
		if (!$imagesFolder) {
			return;
		}
		$serverUrl = IS_PROD ? 'https://static.rusgeocom.ru' : 'https://www.nuxt.rusgeocom.sb.logema.tech';
		$relativePath = IS_PROD
			? '/home/bitrix/ext_www/static.rusgeocom.ru/'
			: '/home/bitrix/ext_www/nuxt.rusgeocom.sb.logema.tech/';
		$fullPath = $relativePath . trim($imagesFolder, '/') . '/';

		$files = scandir($fullPath);

		if (!$files) {
			$this->images3d = [];
			return;
		}
		$files = array_diff($files, ['.', '..']);
		sort($files, SORT_NATURAL);
		$files = array_map(function($item) use ($serverUrl, $imagesFolder){
			return $serverUrl . $imagesFolder . $item;
		}, $files);

		$this->images3d = $files;
	}

	public function get3dImages(): array
	{
		return $this->images3d;
	}

	public function isShowOutOfStock(): bool
	{
		return !!$this->isShowOutOfStock;
	}

	public function isShowStockLabel(): bool
	{
		return $this->isShowStockLabel;
	}

	public function inStock(): bool
	{
		return $this->stockStatus == static::STOCK_STATUS_IN_STOCK;
	}

	public function getVendorResetTime(): int
	{
		return $this->vendorResetTime;
	}

	public function isComplect(): bool
	{
		return $this->isComplect;
	}

	public function isComplectAvailableInStocks(): bool
	{
		return $this->isComplectAvailableInStocks;
	}

	public function getComplectItemsIdQuantity(): array
	{
		return $this->complectItemsIdQuantity;
	}

	public function getComplectName(): string
	{
		return $this->complectName;
	}

	public function getStockStatus() : array
	{
		return $this->stockStatus;
	}

	public function canPayByCard(): bool
	{
		return $this->canPayByCard;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function canCompare(): bool
	{
		return $this->canCompare;
	}

	public function canAddToFavorite(): bool
	{
		return $this->canAddToFavorite;
	}

	public function getDefaultVariantId(): int
	{
		return $this->defaultVariantId;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function setUrl(Uri $uri): void
	{
		$this->url = $uri->getUri();
	}

	public function addUrlParams(array $params): void
	{
		$uri = new Uri($this->url);
		$uri->addParams($params);
		$this->url = $uri->getUri();
	}

	public function hasVerificationCertificate(): bool
	{
		return $this->hasVerificationCertificate;
	}

	public function getRating(): array
	{
		return $this->rating;
	}

	public function getAllBadgesCodes(): array
	{
		return array_keys($this->badges);
	}

	public function getBadgesForSection(): array
	{
		$limit = static::BADGE_SECTION_PAGE_COUNT;
		$sectionBadges = $this->getBadgesForPlace(Badge::BADGES_SECTION_PLACE_CODE);
		if (Arr::first($sectionBadges, static fn(Badge $badge) => $badge->getCode() === Badge::BADGE_GOSREESTR_CODE)) {
			$limit++;
		}
		return array_slice($sectionBadges, 0, $limit);
	}

	public function getBadgesForDetail(): array
	{
		return array_slice($this->getBadgesForPlace(Badge::BADGES_DETAIL_PLACE_CODE), 0, static::BADGE_DETAIL_PAGE_COUNT);
	}

	private function getBadgesForPlace($place): array
	{
		$stickers = [];
		foreach ($this->badges as $badge) {
			if ($badge->getShowPlace() === $place || $badge->getShowPlace() === Badge::BADGES_ALL_PLACE_CODE) {
				if (in_array($badge->getCode(), Badge::INVISIBLE_BADGES_CODES)) {
					continue;
				}
				if ($badge->getCode() !== 'gost') {
					$stickers[] = $badge;
				} elseif ($this->gosreestrNumber) {
					array_unshift(
						$stickers,
						$place === Badge::BADGES_DETAIL_PLACE_CODE
							? $this->getDetailGostBadge()
							: $badge
					);
				}
			}
		}

		//в зависимости от наличия скидки видимо добавляем бейдж
		$canShowDiscountBadge = $place !== Badge::BADGES_DETAIL_PLACE_CODE
			|| $this->getDisplayType() === DiscountAmountView::Percent;
		if ($canShowDiscountBadge) {
			if (
				$place !== Badge::BADGES_DETAIL_PLACE_CODE
				&& $this->getPreviewType() === GuestDiscountView::FirstBuy
				&& $this->hasAuthorizationDiscountLabel()
				&& $firstDiscountBadge = $this->getFirstBuyDiscountBadge()
			) {
				array_unshift($stickers, $firstDiscountBadge);
			} elseif ($discountPercent = $this->getDiscountPercent()) {
				array_unshift($stickers, $this->getDiscountPercentBadge($discountPercent));
			}
		}

		return $this->dropExtraInstallmentAndCreditBadges($stickers);
	}

	public function getDiscountPercent(): ?int
	{
		$oldPrice = $this->getOldPrice() ?: $this->getBasePrice();
		if ($calculatedPrice = $this->getCalculatedPrice()) {
			$currentDiscount = $calculatedPrice->getDiscount();
			if (!$currentDiscount || !$calculatedPrice->getDiscountSum()) {
				return null;
			}

			return $calculatedPrice->getDiscountAmountView() === DiscountAmountView::Percent
				? $currentDiscount
				: PriceUtils::percent($oldPrice, $calculatedPrice->getDiscountSum());
		}

		$discount = $this->calculateDiscount();
		return $discount > 0 ? PriceUtils::percent($oldPrice,  $discount) : null;
	}

	public function canShowDiscount(): bool
	{
		return $this->getPreviewType() !== GuestDiscountView::FirstBuy || !$this->hasAuthorizationDiscountLabel();
	}

	private function calculateDiscount(): int
	{
		if ($this->isPriceHidden() || !$oldPrice = $this->getOldPrice()) {
			return 0;
		}

		return $oldPrice - $this->getCurrentPrice();
	}

	public function getCurrentPrice(): int
	{
		if (!$this->getBasePrice()) {
			return 0;
		}

		if ($calculatedPrice = $this->getCalculatedPrice()) {
			if ($this->authorizationHintVisible) {
				$oldPrice = $calculatedPrice->getOldPrice() ?: $this->getBasePrice();
				return $oldPrice - $calculatedPrice->getDiscountSum();
			}

			return $calculatedPrice->getPrice();
		}

		$discountBag = $this->getDiscountBag();
		if ($discountBag && !$discountBag->isEmpty()) {
			$discount = $this->hasAuthorizationDiscountLabel()
				? $discountBag->getAuthorizedDiscount()
				: $discountBag->getPriorityDiscount();
			return $this->getBasePrice() - $discount;
		}

		return $this->getPrice();
	}

	private function getDiscountPercentBadge(int $discountPercent): Badge
	{
		return new Badge([
			'UF_CODE' => Badge::DISCOUNT_BADGE_CODE,
			'UF_NAME' => $discountPercent . ' %',
			'UF_BACKGROUND_COLOR' => Badge::BADGE_DISCOUNT_COLOR,
			'UF_TEXT_COLOR' => Badge::BADGE_DISCOUNT_TEXT_COLOR,
			'UF_SHOW_PLACE' => Badge::BADGES_ALL_PLACE_CODE,
			'UF_DISPLAY_LOCATION' => Badge::BADGES_ABOVE_TITLE_CODE,
		]);
	}

	private function getFirstBuyDiscountBadge(): ?Badge
	{
		$amount = $this->calculatedPrice?->getDiscount() ?? $this->makeCurrentDiscountDisplay();
		if (!$amount) {
			return null;
		}

		$currentAmountDisplay = $this->calculatedPrice?->getDiscountAmountView() ?? $this->discountDisplay;
		$discountType = $currentAmountDisplay === DiscountAmountView::Percent ? '%' : '₽';
		$formattedAmount = '-' . $amount . $discountType;

		return new Badge([
			'UF_CODE' => Badge::FIRST_BUY_DISCOUNT_BADGE_CODE,
			'UF_NAME' => $formattedAmount . Badge::BADGE_FIRST_BUY_DISCOUNT_SUFFIX,
			'UF_BACKGROUND_COLOR' => Badge::BADGE_FIRST_BUY_DISCOUNT_COLOR,
			'UF_TEXT_COLOR' => Badge::BADGE_FIRST_BUY_DISCOUNT_TEXT_COLOR,
			'UF_SHOW_PLACE' => Badge::BADGES_ALL_PLACE_CODE,
			'UF_DISPLAY_LOCATION' => Badge::BADGES_ABOVE_TITLE_CODE,
		]);
	}

	private function getDetailGostBadge(): Badge
	{
		$gostBadge["UF_CODE"] = Badge::BADGE_GOSREESTR_CODE;
		$gostBadge["UF_NAME"] = Badge::BADGE_GOSREESTR_TEXT;
		$gostBadge["UF_BACKGROUND_COLOR"] = Badge::BADGE_GOSREESTR_DETAIL_COLOR;
		$gostBadge["UF_TEXT_COLOR"] = '';
		$gostBadge["UF_BORDER_COLOR"] = '';
		$gostBadge["UF_SHOW_PLACE"] = Badge::BADGES_ALL_PLACE_CODE;
		$gostBadge["UF_DISPLAY_LOCATION"] = Badge::BADGES_ABOVE_TITLE_CODE;

		return new Badge($gostBadge);
	}

	public function getDiscountHint(): string
	{
		return $this->discountHint;
	}

	private function dropExtraInstallmentAndCreditBadges(array $stickers): array
	{
		$installmentBadgePriority = [
			'rassrochka10',
			'rassrochka10Hidden',
			'rassrochka6',
			'installment',
			'rassrochkaHidden'
		];
		$creditBadgePriority = [
			'credit',
			'creditHidden'
		];
		$badgeCodes = $this->getAllBadgesCodes();

		$foundInstallment = false;
		foreach ($installmentBadgePriority as $badgeCode) {
			if (in_array($badgeCode, $badgeCodes, true)) {
				if ($foundInstallment) {
					unset($stickers[$badgeCode]);
				}
				if (!$foundInstallment) {
					$foundInstallment = true;
				}
			}
		}
		$foundCredit = false;
		foreach ($creditBadgePriority as $badgeCode) {
			if (in_array($badgeCode, $badgeCodes, true)) {
				if ($foundCredit) {
					unset($stickers[$badgeCode]);
				}
				if (!$foundCredit) {
					$foundCredit = true;
				}
			}
		}

		return array_values($stickers);
	}

	public function getShortName(?Localizer $localizer = null): string
	{
		return Tools::truncateText($this->getName($localizer), 41, '..');
	}

	public function getName(?Localizer $localizer = null): string
	{
		if ($localizer){
			return $localizer->localize($this->name, $this->nameEn);
		}

		return $this->name;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function setMainButtonType(string $type): Product
	{
		$this->mainButtonType = $type;
		return $this;
	}

	public function getMainButtonType(): string
	{
		return $this->mainButtonType;
	}

	public function getStocks(): array
	{
		return $this->stocks;
	}

	public function setImageLimit(int $limit): void
	{
		$this->imageLimit = $limit;
	}

	public function makeImages(): array
	{
		$images = [
			'main' => $this->getDetailPicture(),
		];
		if ($this->otherImages){
			foreach ($this->getPhotos() as $image){
				$images['slider'][] = $image;
			}
		} else {
			$images['slider'] = null;
		}

		if (!$this->imageLimit) {
			return $images;
		} else {
			$images['slider'] = is_array($images['slider']) ? array_slice($images['slider'], 0, $this->imageLimit) : null;
		}
		return $images;
	}

	public function makePrices(bool $addDiscountPreview = false, bool $forDetailPage = false): array
	{
		// Цены
		$prices = [];
		if ($this->getPrice() && !$this->isPriceHidden()) {
			if ($this->calculatedPrice) {
				return $this->makePriceFromCalculated();
			}

			$discountDisplay = $forDetailPage ? $this->discountDisplay : DiscountAmountView::Percent;
			$hasFirstDiscount = $this->hasFirstDiscount();
			$hasAuthorizationLabel = $this->hasAuthorizationDiscountLabel();
			$displayBasePriceOverCurrent = $hasAuthorizationLabel && $hasFirstDiscount;

			$prices = [
				'price' => $displayBasePriceOverCurrent ? $this->getBasePrice() : $this->getCurrentPrice(),
				'priceOld' => $this->getOldPrice(),
				'priceRetail' => $this->getBasePrice(),
				'hasAuthorizationLabel' => $hasAuthorizationLabel,
				'hasGreenPrice' => $this->hasGreenPrice() && !$displayBasePriceOverCurrent,
				'currentDiscount' => $forDetailPage ? $this->makeCurrentDiscountDisplay() : $this->getDiscountPercent(),
				'discountPrice' => $this->getCurrentPrice(),
				'discountDisplay' => $discountDisplay->value,
				'discountHint' => $this->getDiscountHint(),
				'hasFirstDiscount' => $hasFirstDiscount,
				'firstDiscountHint' => $hasFirstDiscount ? static::FIRST_DISCOUNT_HINT : null,
				'bestPrice' => null,
			];

			if ($addDiscountPreview && $this->hasPersonalDiscount()) {
				$prices['priceForAuthorized'] = $this->getUserPrice();
			}
			if ($addDiscountPreview && $this->hasCompanyDiscount()) {
				$prices['priceForCompany' ] = $this->getCompanyPrice();
			}
			if ($firsDiscountCalculation = $this->getDiscountBag()?->getFirstDiscountCalculation()) {
				$prices['bestPrice'] = [
					'retailPrice' => $this->getBasePrice(),
					'firstDiscount' => $this->getPreparedBestPriceDiscountRow($firsDiscountCalculation->getDiscount()),
					'currentDiscount' => $this->getPreparedBestPriceDiscountRow(
						$this->getBasePrice() - $this->getCurrentPrice()
					),
					'actualDate' => date('d.m.Y'),
				];
			}
		}

		return $prices;
	}

	private function makePriceFromCalculated(): array
	{
		if (!$this->calculatedPrice || $this->calculatedPrice->isEmpty()) {
			return [];
		}

		$hasFirstDiscount = $this->calculatedPrice->hasFirstDiscount();
		$oldPrice = $this->calculatedPrice->getOldPrice() ?: $this->getBasePrice();
		return [
			'price' => $this->calculatedPrice->getPreviewPrice(),
			'priceOld' => $oldPrice,
			'priceRetail' => $this->getBasePrice(),
			'hasAuthorizationLabel' => $this->calculatedPrice->hasAuthorizationLabel(),
			'hasGreenPrice' => $this->calculatedPrice->hasGreenPrice(),
			'currentDiscount' => $this->calculatedPrice->getDiscount() ?: null,
			'discountPrice' => $oldPrice - $this->calculatedPrice->getDiscountSum(),
			'discountDisplay' => $this->calculatedPrice->getDiscountAmountView()->value,
			'discountHint' => $this->getDiscountHint(),
			'hasFirstDiscount' => $hasFirstDiscount,
			'firstDiscountHint' =>  $hasFirstDiscount ? static::FIRST_DISCOUNT_HINT : null,
			'bestPrice' => $this->makeBestPriceFromCalculatedPrice(),
		];
	}

	private function makeBestPriceFromCalculatedPrice(): ?array
	{
		$baseDiscountSegment = $this->calculatedPrice?->getBaseSegmentDiscount();
		if (!$baseDiscountSegment || $this->calculatedPrice->getPrice() >= $baseDiscountSegment->getDiscountPrice()) {
			return null;
		}

		return [
			'retailPrice' => $this->getBasePrice(),
			'firstDiscount' => $this->makeBestPriceRow(
				$baseDiscountSegment->getDiscountPrice(),
				$baseDiscountSegment->getDiscount(),
				$baseDiscountSegment->getDiscountAmountView(),
			),
			'currentDiscount' => $this->makeBestPriceRow(
				$this->calculatedPrice->getPrice(),
				$this->calculatedPrice->getDiscount(),
				$this->calculatedPrice->getDiscountAmountView(),
			),
			'actualDate' => date('d.m.Y'),
		];
	}

	private function makeBestPriceRow(
		int $discountPrice,
		int $discount,
		DiscountAmountView $discountAmountView
	): array {
		return [
			'amount' => $discount,
			'type' => $discountAmountView->value,
			'price' => $discountPrice,
		];
	}

	private function getPreparedBestPriceDiscountRow(int $discount): array
	{
		$calculatedPrice = $this->getBasePrice() - $discount;
		$discountAmount = $this->discountDisplay === DiscountAmountView::Percent
			?  Price::calcPercent($this->getBasePrice(), $discount)
			: $discount;

		return $this->makeBestPriceRow($calculatedPrice, $discountAmount, $this->discountDisplay);
	}

	private function makeCurrentDiscountDisplay(): ?int
	{
		$amount = $this->getDisplayType() === DiscountAmountView::Total
			? $this->calculateDiscount()
			: $this->getDiscountPercent();

		return $amount ?: null;
	}

	/**
	 * @return WholesalePrice[]
	 */
	public function makeWholesalePrices(): array
	{
		if ($this->isPriceHidden()) {
			return [];
		}

		return array_map(
			static function(WholesalePrice $item) {
				$item->hideOldPrice();
				return $item;
			},
			$this->priceBag->get(PriceTypes::WHOLESALE_PRICE_CODE_PREFIX) ?? []
		);
	}

	public function getWholesalePrices(): ?array
	{
		if (!$this->showWholesalePrices) {
			return null;
		}

		return $this->makeWholesalePrices();
	}

	public function getReviews(): string
	{
		$reviewCount = $this->getRating()['VOTERS_COUNT'] ?: 0;
		if (!$reviewCount) {
			return 'нет отзывов';
		}
		return $reviewCount . ' ' . TextHelper::pluralForm($reviewCount, 'отзыв', 'отзыва', 'отзывов');
	}

	public function getReviewsCount(): int
	{
		return (int)$this->getRating()['VOTERS_COUNT'] ?: 0;
	}

	public function getAccessorSort()
	{
		return $this->accessorSort;
	}

	public function isInstallment10Month(): bool
	{
		return !empty(
			array_intersect(
				Badge::INSTALLMENT_10_MONTH_CODES,
				$this->getAllBadgesCodes()
			)
		);
	}

	public function isInstallmentAllowed(): bool
	{
		return !empty(
			array_intersect(
				Badge::INSTALLMENT_BADGES_CODES,
				$this->getAllBadgesCodes()
			)
		);
	}

	public function isCreditAllowed(): bool
	{
		return !empty(
			array_intersect(
				Badge::CREDIT_BADGES_CODES,
				$this->getAllBadgesCodes()
			)
		);
	}

	public function isHit(): bool
	{
		return in_array(Badge::BADGE_HIT_CODE, $this->getAllBadgesCodes());
	}

	public function isSplitAllowed(): bool
	{
		return PaymentService::checkSplitAllowed($this->getBasePrice());
	}

	public function isPickupRestricted(): bool
	{
		// обратная совместимость для закешированных товаров
		if ($this->pickupRestrictionId === null) {
			return $this->isPickupRestricted;
		}

		return (bool)$this->pickupRestrictionId;
	}

	public function getPickupRestrictionId(): int
	{
		return (int)$this->pickupRestrictionId;
	}

	public function setPickupRestriction(PickupRestriction $pickupRestriction): void
	{
		$this->pickupRestriction = $pickupRestriction;
	}

	public function getPickupRestriction(): PickupRestriction
	{
		// обратная совместимость для закешированных товаров
		if ($this->pickupRestrictionId === null && $this->isPickupRestricted) {
			return PickupRestriction::NotMoscow;
		}

		if (!$this->pickupRestriction && $this->getPickupRestrictionId()) {
			return PickupRestriction::NotMoscow;
		}

		return $this->pickupRestriction ?? PickupRestriction::None;
	}

	public function isShowToWholesalers(): bool
	{
		return $this->isShowToWholesalers;
	}

	public function isAuthorizedDiscountActive(): bool
	{
		if ($calculatedPrice = $this->getCalculatedPrice()) {
			return !$this->authorizationHintVisible
			&& $calculatedPrice->getPreviewType() !== GuestDiscountView::None;
		}

		if ($this->getDiscountBag()) {
			return $this->getDiscountBag()->isForAuthorized();
		}

		return Price::isAuthorizedDiscountForCurrentUser()
			&& (
				($this->getPrice() === $this->getUserPrice() && $this->hasPersonalDiscount())
				|| ($this->getPrice() === $this->getCompanyPrice() && $this->hasCompanyDiscount())
			);
	}

	private function isPriceHidden(): bool
	{
		return $this->isPriceOnRequest || $this->getPrice() <= 0;
	}

	public function isArchive(): bool
	{
		return $this->isArchive;
	}

	public function hasGreenPrice(): bool
	{
		return $this->isAuthorizedDiscountActive() || $this->hasAuthorizationDiscountLabel();
	}

	public function getUniqueOfferText(): string
	{
		return $this->uniqueOffer ?: '';
	}

	public function getPriceBag(): PriceBagInterface
	{
		return $this->priceBag;
	}

	public function getAdditionalCharacteristics(): array
	{
		return [
			[
				'name' => 'Артикул',
				'value' => $this->getArticul(),
				'hint' => '',
			]
		];
	}

	private function getDisplayType(): DiscountAmountView
	{
		return $this->getCalculatedPrice()?->getDiscountAmountView() ?? $this->discountDisplay;
	}

	private function getPreviewType(): GuestDiscountView
	{
		return $this->getCalculatedPrice()?->getPreviewType() ?? $this->discountView;
	}

	public function getDiscountDisplay(): DiscountAmountView
	{
		return $this->discountDisplay;
	}

	public function getDiscountView(): GuestDiscountView
	{
		return $this->discountView;
	}

	public function hasPersonalDiscount(): bool
	{
		return $this->getUserPrice() !== null;
	}

	public function hasCompanyDiscount(): bool
	{
		return $this->getCompanyPrice() !== null;
	}

	public function needVatInReceipt(): bool
	{
		return $this->needVatInReceipt;
	}

	public function canDisplayDiscountForAuthBlock(): bool
	{
		if ($this->getCalculatedPrice()) {
			return $this->getPreviewType() === GuestDiscountView::Block;
		}

		return $this->discountView === GuestDiscountView::Block
			&& $this->authorizationHintVisible
			&& $this->hasAuthorizationDiscount();
	}

	public function isIndexingDisabled(): bool
	{
		return $this->indexingDisabled;
	}

	public function hasFirstDiscount(): bool
	{
		if ($calculatedPrice = $this->getCalculatedPrice()) {
			return $calculatedPrice->hasFirstDiscount();
		}

		return $this->discountView === GuestDiscountView::FirstBuy
			&& $this->hasAuthorizationDiscount()
			&& $this->getDiscountBag()?->isForDefaultSegment();
	}

	public function jsonSerialize(): array
	{
		return [
			'additional' => $this->hasVerificationCertificate() ? 'Поверка в комплекте' : '',
			'allowCompare' => $this->canCompare(),
			'allowAddToFavorite' => $this->canAddToFavorite(),
			'description' => CatalogDetailHelper::makeFormattedDescription($this->description, $this->getAdditionalCharacteristics()),
			'id' => $this->getId(),
			'images' => $this->makeImages(),
			'url' => $this->getUrl(),
			'mainButton' => $this->getMainButtonType(),
			'manager' => $this->getStocks(),
			'name' => $this->getName(),
			'offerId' => $this->getDefaultVariantId(),
			'price' => $this->makePrices($this->showDiscountPreview),
			'wholesalePrice' => $this->getWholesalePrices(),
			'productCode' => $this->getId(),
			'rating' => $this->getRating()['AVERAGE_RATE'] ?: 0,
			'reviews' => $this->getReviews(),
			'reviewsCount' => $this->getReviewsCount(),
			'shortName' => $this->getShortName(),
			'showStock' => $this->isShowStockLabel(),
			'stickers' => $this->getBadgesForSection(),
			'stock' => $this->inStock(),
			'stockStatus' => $this->getStockStatus(),
			'priceRequestTemplate' => CatalogDetailHelper::getPriceRequestTemplate($this->getTitle()),
			'sectionId' => (int)$this->sectionId,
			'managerPreview' => $this->isManagerPreview(),
			'uniqueOffer' => $this->getUniqueOfferText(),
		];
	}

	public function applyDiscountsWithoutStock(): bool
	{
		return $this->applyDiscountsWithoutStock;
	}
}
