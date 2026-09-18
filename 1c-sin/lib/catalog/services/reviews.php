<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\Elements\ElementCatalogTable;
use Bitrix\Iblock\Elements\ElementCommentsTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use CIBlockElement;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Entities\Review;
use Rusgeocom\Rusgeocom\Catalog\Entities\ReviewCollection;
use Rusgeocom\Rusgeocom\Catalog\SectionTree;
use Rusgeocom\Rusgeocom\Catalog\Entities\ReviewQueryResult;
use Rusgeocom\Rusgeocom\Order\services\OrderService;
use Rusgeocom\Rusgeocom\Personal\Entities\ReviewQueryParams;
use Rusgeocom\Rusgeocom\Sale\Entities\OrderStatus;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Types\Uri;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use Rusgeocom\Rusgeocom\Utils\Cache;
use Rusgeocom\Rusgeocom\Utils\MailService;
use Rusgeocom\Rusgeocom\Utils\Url;
use Rusgeocom\Rusgeocom\Utils\User;

class Reviews
{
	private const int MAX_REVIEWS_FOR_SECTION = 3;
	public const int REVIEWS_ON_PRODUCT_PAGE = 4;
	public const int MEDIA_IN_PREVIEW = 6;

	/**
	 * @param int $productId
	 * @return ReviewCollection
	 * @throws \Exception
	 */
	public static function getReviewsForProduct(int $productId): ReviewCollection
	{
		if ($productId <= 0) {
			throw new Exception('Не указан ID товара');
		}

		$callback = function() use ($productId) {

			$filter = [
				'PROPERTY_PRODUCT' => $productId,
				'ACTIVE' => 'Y',
			];
			$ibReviews = static::getIbElementsByFilter($filter);
			if (!$ibReviews) {
				return new ReviewCollection([]);
			}

			$reviews = [];
			foreach ($ibReviews as $ibReview) {
				$product = static::getProductById($productId);
				$reviews[] = new Review($ibReview, (int)$product['ID'], $product['NAME']);
			}

			return new ReviewCollection($reviews);
		};

		return Cache::create()
			->addTag('product_reviews_' . $productId)
			->addKey(__METHOD__)
			->addKey((string)$productId)
			->setCallback($callback)
			->getResult();
	}

	public static function getById(int $reviewId): ?Review
	{
		$filter = [
			'ID' => $reviewId,
			'ACTIVE' => 'Y',
			'!PROPERTY_PRODUCT' => false,
		];

		$ibReview = current(static::getIbElementsByFilter($filter));
		if (!$ibReview) {
			return null;
		}

		$product = static::getProductById((int)$ibReview['PROPERTIES']['PRODUCT']['VALUE']);

		$review = new Review($ibReview, (int)$product['ID'], $product['NAME']);
		$review->setReactions(ReviewReactions::getReactionsForReview($reviewId, User::getId()));

		return $review;
	}

	public static function forProduct(
		Product $product,
		int $page,
		Uri $uri,
		Localizer $localizer,
		int $perPage = 0,
	): array
	{
		$sort = static::makeSortFromUri($uri, $localizer);

		$withImages = (bool)$uri->getParam('needMedia', false);
		$params = static::makeQueryParams($product, $page, $withImages, $sort);
		if ($perPage) {
			$params->setShowPerPage($perPage);
		}
		$reviewQueryResult = static::queryReviewsForProduct($params);
		$reviews = $reviewQueryResult->getReviewCollection();
		$stats = static::getStatsByParams($params);
		$images = collect(static::getReviewImagesForProduct($product->getId()));

		return [
			'rating' => $stats['ratingTotal'], //для обратной совместимости
			'count' => $stats['countTotal'], //для обратной совместимости
			'countWithImages' => $images->pluck('reviewId')->unique()->count(),
			'countWithMedia' => static::getReviewCountWithMedia($product),
			'items' => $reviews->getReviews(),
			'images' => $reviews->getImages(),
			'stats' => $stats,
			'sort' => $sort,
			'perPage' => $reviewQueryResult->getPageSize(),
			'pagesCount' => $reviewQueryResult->getPageCount(),
			'pageNumber' => $reviewQueryResult->getPageNumber(),
		];
	}

	public static function getMediaPreview(Product $product): ?array
	{
		$params = ReviewQueryParams::create()
			->setProductId($product->getId())
			->setNeedReactions(false)
			->setShowWithoutMedia(false)
			->setSort(['date' => 'desc'])
			->setShowPerPage(self::MEDIA_IN_PREVIEW);

		$collection = Reviews::queryReviewsForProduct($params)?->getReviewCollection();
		if (!$collection) {
			return null;
		}

		return [
			'medias' => $collection->getFirstMedias(),
			'totalCount' => static::getReviewCountWithMedia($product),
		];
	}

	public static function getReviewCountWithMedia(Product $product): int
	{
		$mediaPropertyCodes = [
			'MORE_PHOTO',
			'VIDEO_LINKS',
		];

		return (int)ElementPropertyTable::query()
			->addSelect('CNT')
			->registerRuntimeField(
				'CNT',
				new ExpressionField(
					'CNT',
					'COUNT(DISTINCT %s)',
					['IBLOCK_ELEMENT_ID']
				)
			)
			->registerRuntimeField(
				'PROPERTY',
				(new ReferenceField(
					'PROPERTY',
					PropertyTable::class,
					Join::on('this.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.IBLOCK_ID', COMMENTS_IBLOCK_ID)
				))->configureJoinType('INNER')
			)
			->where('ELEMENT.IBLOCK_ID', COMMENTS_IBLOCK_ID)
			->where('ELEMENT.ACTIVE', 'Y')
			->whereIn('PROPERTY.CODE', $mediaPropertyCodes)
			->whereIn('IBLOCK_ELEMENT_ID', static::getReviewsByProductQuery($product))
			->exec()
			->fetch()['CNT'] ?: 0;
	}

	private static function getReviewsByProductQuery(Product $product): Query
	{
		return  ElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->registerRuntimeField(
				'PROPERTY',
				(new ReferenceField(
					'PROPERTY',
					PropertyTable::class,
					Join::on('this.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.IBLOCK_ID', COMMENTS_IBLOCK_ID)
				))->configureJoinType('INNER')
			)
			->where('PROPERTY.CODE', 'PRODUCT')
			->where('VALUE', (string)$product->getId());
	}

	public static function makeQueryParams(
		Product $product,
		int $page,
		bool $withImages,
		array $sort
	): ReviewQueryParams {
		$activeSort = Arr::first($sort, static fn(array $item) => $item['isActive']);

		return ReviewQueryParams::create()
			->setNeedReactions(true)
			->setProductId($product->getId())
			->setSort(self::prepareSort([static::mapSortBy($activeSort['by']) => $activeSort['order']]))
			->setShowPerPage(static::REVIEWS_ON_PRODUCT_PAGE)
			->setShowWithoutMedia(!$withImages)
			->setPageNumber($page);
	}

	/**
	 * @param array $sort
	 * @return array
	 */
	public static function prepareSort(array $sort): array
	{
		$preparedSort = $sort;
		if (isset($sort['PROPERTY_RATE'])) {
			$preparedSort['PROPERTY_DATE'] = 'desc';
		}
		return $preparedSort;
	}

	private static function makeSortFromUri(Uri $uri, Localizer $localizer): array
	{
		$variants = static::getSorts($localizer);

		$sortBy = $uri->getParam('SORT_BY', 'date');
		if (!in_array($sortBy, array_column($variants, 'by'))) {
			$sortBy = 'date';
		}
		$orderBy = Str::lower($uri->getParam('ORDER_BY', 'desc'));
		foreach ($variants as $index => $variant) {
			if ($variant['by'] === $sortBy) {
				$variants[$index]['order'] = in_array($orderBy, ['asc', 'desc']) ? $orderBy : 'desc';
				$variants[$index]['isActive'] = true;
				break;
			}
		}

		return $variants;
	}

	public static function makeSortByParam(string $sortBy, string $orderBy, Localizer $localizer): array
	{
		$variants = static::getSorts($localizer);

		if (!in_array($sortBy, array_column($variants, 'by'))) {
			$sortBy = 'date';
		}
		foreach ($variants as $index => $variant) {
			if ($variant['by'] === $sortBy) {
				$variants[$index]['order'] = in_array($orderBy, ['asc', 'desc']) ? $orderBy : 'desc';
				$variants[$index]['isActive'] = true;
				break;
			}
		}

		return $variants;
	}

	public static function queryReviewsForProduct(ReviewQueryParams $params): ?ReviewQueryResult
	{
		$workParams = clone $params;
		if (!$productId = $workParams->getProductId()) {
			return null;
		}

		$currentBestReview = $workParams->isReactionsNeeded() ? ReviewReactions::getBestReviewForProduct($productId) : null;
		if ($currentBestReview) {
			$workParams->setBestReviewId($currentBestReview);
			if ($reviewIds = static::getReviewIdsForPage($workParams)) {
				$workParams->disablePagination()
					->setFilter(['ID' => $reviewIds])
					->setSort(['ID' => $reviewIds]);
			}
		}

		$callback = function() use ($params, $workParams) {
			return static::query($workParams, $params);
		};

		/** @var ReviewQueryResult $result */
		$result = Cache::create()
			->addTag('product_reviews_' . $productId)
			->addKey($params->getHash())
			->setCallback($callback)
			->getResult();

		$collection = $result->getReviewCollection();
		if ($params->isReactionsNeeded() && $result->getTotalCount()) {
			$reactions = ReviewReactions::getReactionsForReviews($collection->getIds(), User::getId());
			$collection->setReactions($reactions);
			if ($currentBestReview) {
				/** @var Review|null $bestReview */
				$bestReview = Arr::first(
					$collection->getReviews(),
					static fn(Review $review) => $review->getId() === $currentBestReview
				);
				$bestReview?->setBestReview();
			}
		}

		return $result;
	}

	public static function getReviewImagesForProduct(int $productId, int $limit = 0, array $sort = []): array
	{
		if (!$sort) {
			$sort = [
				'PROPERTY_RATE' => 'DESC',
				'ID' => 'DESC',
			];
		}

		$product = static::getProductById($productId);
		$select = [
			'ID',
			'PROPERTY_MORE_PHOTO',
		];

		$filter = [
			'IBLOCK_ID' => COMMENTS_IBLOCK_ID,
			'!PROPERTY_MORE_PHOTO' => false,
			'PROPERTY_PRODUCT' => $productId,
			'ACTIVE' => 'Y',
		];

		$iterator = CIBlockElement::GetList($sort, $filter, false, false, $select);

		$images = [];
		while ($review = $iterator->Fetch()) {;
			$imageId = (int)$review['PROPERTY_MORE_PHOTO_VALUE'];
			$images[] = [
				'id' => $imageId,
				'reviewId' => (int)$review['ID'],
				'image' => Image::fromIblockElement($imageId, $product['NAME'])
					->resize(
						smallSize: Review::SMALL_IMAGE_SIZE,
						mediumSize: Review::MEDIUM_IMAGE_SIZE,
						bigSize: Review::BIG_IMAGE_SIZE
					),
			];
		}

		return $images;
	}

	public static function query(
		ReviewQueryParams $reviewQueryParams,
		?ReviewQueryParams $paramsForCount = null
	): ReviewQueryResult
	{
		$paramsForCount = $paramsForCount ?? $reviewQueryParams;
		$totalReviewsCount = static::getCountByFilter(static::makeFilterFromQueryParams($paramsForCount));
		if ($paramsForCount->isPaginationEnabled()) {
			$page = $paramsForCount->getPageNumber();
			$totalPageCount = ceil($totalReviewsCount / $paramsForCount->getShowPerPage());
			$page = $page > 0 && $page <= $totalPageCount ? $page : 1;
			$paramsForCount->setPageNumber($page);
		}

		$reviews = static::getReviews($reviewQueryParams);

		return new ReviewQueryResult(
			$reviews,
			$totalReviewsCount,
			$paramsForCount->isPaginationEnabled() ? $paramsForCount->getPageNumber() : 0,
			$paramsForCount->isPaginationEnabled() ? $paramsForCount->getShowPerPage() : 0,
		);
	}

	private static function makeFilterFromQueryParams(ReviewQueryParams $reviewQueryParams): array
	{
		$filter = $reviewQueryParams->getFilter();
		if (!isset($filter['ACTIVE'])) {
			$filter['ACTIVE'] = 'Y';
		}

		if ($productId = $reviewQueryParams->getProductId()) {
			$filter['PROPERTY_PRODUCT'] = $productId;
		}

		if (!$reviewQueryParams->isShowWithoutMedia()) {
			// Запрашиваем явно ID, чтобы дублей хелпером не создать
			$elementsWithoutImages =  static::getHelper()->getElementsByFilter(
				array_merge($filter, ['PROPERTY_MORE_PHOTO' => false, 'PROPERTY_VIDEO_LINKS' => false]),
				['ID']
			);
			$filter['!ID'] = array_column($elementsWithoutImages, 'ID');
		}

		return $filter;
	}

	public static function getPersonalPublicReviews(int $pageNumber): array
	{
		$reviewQueryParams = ReviewQueryParams::create()
			->setSelect([
				'ID',
				'NAME',
				'PREVIEW_TEXT',
				'PROPERTY_*',
			])
			->addFilter('CREATED_BY', User::getId())
			->addFilter('ACTIVE', 'Y');

		$totalReviewsCount = static::getCountByFilter($reviewQueryParams->getFilter());
		$totalPageCount = ceil($totalReviewsCount / $reviewQueryParams->getShowPerPage());

		$pageNumber = $pageNumber > 0 && $pageNumber <= $totalPageCount ? $pageNumber : 1;

		$reviewQueryParams->setPageNumber($pageNumber);

		return [
			'reviews' => static::getReviews($reviewQueryParams)->getReviews(),
			'totalReviewsCount' => $totalReviewsCount,
			'totalPageCount' => ceil($totalReviewsCount / $reviewQueryParams->getShowPerPage()),
			'pageNumber' => $pageNumber,
			'reviewsPerPage' => $reviewQueryParams->getShowPerPage()
		];
	}

	public static function canCreateReviewForOrderProduct(int $userId, int $orderId, int $productId): bool
	{
		$createdReviewsCount = Reviews::getCountByFilter([
			'CREATED_BY' => $userId,
			'PROPERTY_PRODUCT' => $productId
		]);

		$order = OrderService::getOrderById($orderId);

		if ($order) {
			$dateInsert = new \DateTime((string)$order->getField('DATE_STATUS'));
			$dateInterval = $dateInsert->diff(new \DateTime());
			return $dateInterval->m < 1 && $order->getField('STATUS_ID') === OrderStatus::DONE && $createdReviewsCount === 0;
		}

		return false;
	}

	public static function getReviewFromCurrentUser(array $productIds): array
	{
		$reviews = Reviews::getReviewsByFilter([
			'CREATED_BY' => User::getId(),
			'PROPERTY_PRODUCT' => current($productIds),
			'ACTIVE' => 'Y'
		], ['ID', 'NAME', 'PROPERTY_*'], 0, ['DATE_CREATE' => 'ACS'])->getReviews();

		$productReviewMap = [];
		foreach ($reviews as $review) {
			$productReviewMap[$review->getProductId()] = $review;
		}

		return $productReviewMap;
	}

	public static function getReviews(ReviewQueryParams $reviewQueryParams): ReviewCollection
	{
		if (!$reviewQueryParams->getSelect()) {
			$reviewQueryParams->setSelect([
				'ID',
				'NAME',
				'PREVIEW_TEXT',
				'PROPERTY_*',
			]);
		}

		$filter = static::makeFilterFromQueryParams($reviewQueryParams);

		$ibReviews = $reviewQueryParams->isPaginationEnabled() ? static::getHelper()->getElementsForPage(
			$filter,
			$reviewQueryParams->getShowPerPage(),
			$reviewQueryParams->getPageNumber(),
			$reviewQueryParams->getSelect(),
			$reviewQueryParams->getSort()
		) : static::getHelper()->getElementsByFilter(
			$filter,
			$reviewQueryParams->getSelect(),
			0,
			$reviewQueryParams->getSort()
		);

		if ($reviewQueryParams->needProductDetails()) {
			static::fillReviewProductDetail($ibReviews, ['ID', 'NAME', 'DETAIL_PICTURE']);
		}

		$reviews = [];
		foreach ($ibReviews as $ibReview) {
			$reviews[] = new Review(
				$ibReview,
				(int)$ibReview['PRODUCT_DETAIL']['ID'],
				$ibReview['PRODUCT_DETAIL']['NAME'],
				\CFile::getPath($ibReview['PRODUCT_DETAIL']['DETAIL_PICTURE']),
				$ibReview['PRODUCT_DETAIL']['URL']
					? Url::formatSlash(trim($ibReview['PRODUCT_DETAIL']['URL'], '/') . '/otzyvy')
					: ''
			);
		}

		return new ReviewCollection($reviews);
	}

	public static function getStatsByParams(ReviewQueryParams $params): array
	{
		$ratings = [];
		for($mark = 5; $mark > 0; $mark--) {
			$ratings[$mark] = [
				'rating' => $mark,
				'count' => 0,
			];
		}

		$query = ElementPropertyTable::query()
			->addSelect('RATE_VALUE.VALUE', 'RATING')
			->addSelect('CNT', 'REVIEWS_COUNT')
			->registerRuntimeField(
				'RATE_VALUE',
				new ReferenceField(
					'RATE_VALUE',
					ElementPropertyTable::class,
					Join::on('this.IBLOCK_ELEMENT_ID', 'ref.IBLOCK_ELEMENT_ID')
				)
			)
			->registerRuntimeField(
				'RATE_PROPERTY',
				new ReferenceField(
					'RATE_PROPERTY',
					PropertyTable::class,
					Join::on('this.RATE_VALUE.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.CODE', 'RATE')
				)
			)
			->registerRuntimeField(
				'PRODUCT_PROPERTY',
				(new ReferenceField(
					'PRODUCT_PROPERTY',
					PropertyTable::class,
					Join::on('this.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.CODE', 'PRODUCT')
				))->configureJoinType('INNER')
			)
			->registerRuntimeField(
				'CNT',
				new ExpressionField(
					'CNT',
					'COUNT(%s)',
					['IBLOCK_ELEMENT_ID']
				)
			)
			->where('ELEMENT.IBLOCK_ID', COMMENTS_IBLOCK_ID)
			->where('ELEMENT.ACTIVE', 'Y')
			->where('RATE_PROPERTY.CODE', 'RATE')
			->where('PRODUCT_PROPERTY.CODE', 'PRODUCT');

		if ($productId = $params->getProductId()) {
			$query->where('VALUE', $productId);
		}

		if (!$params->isShowWithoutMedia()) {
			$photoSubQuery = ElementPropertyTable::query()
				->setDistinct()
				->addSelect('IBLOCK_ELEMENT_ID')
				->registerRuntimeField(
					'PHOTO_PROPERTY',
					new ReferenceField(
						'PHOTO_PROPERTY',
						PropertyTable::class,
						Join::on('this.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.CODE', 'MORE_PHOTO')
					)
				)
				->where('PHOTO_PROPERTY.CODE', 'MORE_PHOTO');

			$query->whereIn('IBLOCK_ELEMENT_ID', $photoSubQuery);
		}

		$iterator = $query
			->addGroup('RATE_VALUE.VALUE')
			->addOrder('RATING', 'DESC')
			->exec();

		while ($ratingStats = $iterator->fetch()) {
			$ratings[$ratingStats['RATING']]['count'] = (int)$ratingStats['REVIEWS_COUNT'];
		}

		$totalCount = array_sum(array_column($ratings, 'count'));
		$ratingSum = array_sum(array_map(static fn(array $rating) => $rating['rating'] * $rating['count'], $ratings));
		return [
			'countTotal' => $totalCount,
			'ratingTotal' => $totalCount ? round($ratingSum/$totalCount) : 0,
			'ratings' => array_values($ratings),
		];
	}

	public static function reviewedProductIds(int $userId, array $productIds = []): array
	{
		$query = ElementTable::query()
			->addSelect('REVIEW_PROPERTY.VALUE', 'PRODUCT_ID')
			->registerRuntimeField(
				'REVIEW_PROPERTY',
				new ReferenceField(
					'REVIEW_PROPERTY',
					ElementPropertyTable::class,
					Join::on('this.ID', 'ref.IBLOCK_ELEMENT_ID')
				)
			)
			->registerRuntimeField(
				'PROPERTY',
				new ReferenceField(
					'PROPERTY',
					PropertyTable::class,
					Join::on('this.REVIEW_PROPERTY.IBLOCK_PROPERTY_ID', 'ref.ID')
				)
			)
			->where('IBLOCK_ID', COMMENTS_IBLOCK_ID)
			->where('CREATED_BY', $userId)
			->where('PROPERTY.CODE', 'PRODUCT');

		if ($productIds) {
			$query->whereNotIn('REVIEW_PROPERTY.VALUE', $productIds);
		}

		return array_column($query->fetchAll(), 'ID');
	}

	public static function deleteReviewById(int $id): void
	{
		static::getHelper()->deleteElement($id);
	}

	public static function getReviewsByFilter(
		array $filter,
		array $select = ['ID', 'NAME', 'PROPERTY_*'],
		int $limit = 0,
		array $sort = ['ID' => 'DESC']
	): ReviewCollection {
		if (!in_array('PROPERTY_*', $select) && !in_array('PROPERTY_PRODUCT', $select)) {
			array_push($select, 'PROPERTY_PRODUCT');
		}

		$ibReviews = static::getHelper()->getElementsByFilter($filter, $select, $limit, $sort);

		$reviews = [];
		foreach ($ibReviews as $ibReview) {
			$product = static::getProductById(
				(int)$ibReview['PROPERTIES']['PRODUCT']['VALUE'],
				['ID', 'NAME', 'DETAIL_PICTURE']
			);
			$reviews[] = new Review($ibReview, (int)$product['ID'], $product['NAME'],
				\CFile::getPath($product['DETAIL_PICTURE']));
		}

		return new ReviewCollection($reviews);
	}

	public static function getCountByFilter(array $filter): int
	{
		return static::getHelper()->getCount($filter);
	}

	public static function notifyAboutReview(int $reviewId): void
	{
		$select = ['PROPERTY_MORE_VIDEO'];
		$review = static::getHelper()->getElementById($reviewId, $select);
		$hasVideo = (bool)$review['PROPERTIES']['MORE_VIDEO']['VALUE'] ?? [];

		$subject = 'Отзыв на товар' . ($hasVideo ? '(СОДЕРЖИТ ВИДЕО)' : '') . ' - проверить и опубликовать';
		$key = $hasVideo
			? "Отзыв содержит ВИДЕО, если оно ок вместо активации нужно установить чекбокс 'Видео пользователя проверены'"
			: 'Отзыв';
		$fields = [
			$key => MailService::makeUrlField(
				'Ссылка на отзыв',
				Url::makeAbsoluteUrl(
					'/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=13&type=services&lang=ru&ID=' . $reviewId, 'www'
				)
			)
		];
		MailService::sendFormFields(REVIEW_MAIL, $subject, '', $fields);
	}

	public static function resetVideoModerationIfVideoProcessed(int $reviewId = 0): void
	{
		$filter = [
			'!PROPERTY_CAN_UPLOAD_VIDEO' => false,
			'PROPERTY_MORE_VIDEO' => false,
		];

		if ($reviewId) {
			$filter['ID'] = $reviewId;
		}

		$reviews = static::getHelper()->getElementsByFilter($filter, ['ID', 'ACTIVE']);
		$model = new CIBlockElement();
		foreach ($reviews as $review) {
			CIBlockElement::SetPropertyValuesEx(
				$review['ID'],
				COMMENTS_IBLOCK_ID,
				[
					'CAN_UPLOAD_VIDEO' => null,
				]
			);
			if ($review['ACTIVE'] !== 'Y') {
				$model->Update($review['ID'], ['ACTIVE' => 'Y']);
			}
		}
	}

	public static function getSorts(Localizer $localizer): array
	{
		return [
			[
				'name' => $localizer->localize('Оценке', 'Rating'),
				'by' => 'rate',
				'order' => 'desc',
				'isActive' => false,
			],
			[
				'name' => $localizer->localize('Дате', 'Date'),
				'by' => 'date',
				'order' => 'desc',
				'isActive' => false,
			],
		];
	}


	public static function mapSortBy(string $by): string
	{
		$sortBy = [
			'rate' => 'PROPERTY_RATE',
			'date' => 'PROPERTY_DATE',
		];

		return $sortBy[$by] ?? 'PROPERTY_RATE';
	}

	private static function getProductById(int $productId, array $select = ['ID', 'NAME']): array
	{
		$product = ElementTable::query()
			->setSelect($select)
			->addFilter('IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('ID', $productId)
			->exec()
			->fetch();

		if (!$product) {
			throw new Exception('Товар не найден');
		}

		return $product;
	}

	private static function fillReviewProductDetail(array &$rawReviews, array $productDetail = ['ID', 'NAME']): void
	{
		$productIds = [];
		foreach ($rawReviews as $rawReview) {
			$productIds[] = (int)trim($rawReview['PROPERTIES']['PRODUCT']['VALUE']);
		}

		if (!$productIds) {
			return;
		}

		$propertyIds = static::getPropertyIds();
		$products = ElementTable::query()
			->setSelect($productDetail)
			->addSelect('ALIAS.VALUE', 'URL')
			->registerRuntimeField(
				new ReferenceField(
					'ALIAS',
					ElementPropertyTable::class,
					Join::on('this.ID', 'ref.IBLOCK_ELEMENT_ID')
						->where('ref.IBLOCK_PROPERTY_ID', $propertyIds['ALIASE'])
				)
			)
			->where('IBLOCK_ID', CATALOG_IBLOCK_ID)
			->whereIn('ID', $productIds)
			->fetchAll();

		$products = array_combine(array_column($products, 'ID'), $products);

		foreach ($rawReviews as &$rawReview) {
			$productId = (int)trim($rawReview['PROPERTIES']['PRODUCT']['VALUE']);
			if (!$productId) {
				throw new Exception('Товар не найден');
			}
			$rawReview['PRODUCT_DETAIL'] = $products[$productId];
		}
		unset($rawReview);
	}

	private static function getIbElementsByFilter(array $filter): array
	{
		$select = [
			'NAME',
			'PREVIEW_TEXT',
			'PROPERTY_*',
		];
		$sort = ['ID' => 'DESC'];
		return static::getHelper()->getElementsByFilter($filter, $select, 0, $sort);
	}

	public static function getBestReviewsForSection(int $sectionId): ?ReviewCollection
	{
		$service = SectionTree::getInstance();
		$subsections = $service->getSubsectionsIds($sectionId);
		array_unshift($subsections, $sectionId);

		$reviewIds = array_column(
			ElementCommentsTable::query()
				->addSelect('REVIEW_HASH')
				->addSelect('MIN_ID')
				->registerRuntimeField(
					new ExpressionField(
						'MIN_ID',
						'MIN(%s)',
						['ID']
					)
				)
				->registerRuntimeField(
					new ExpressionField(
						'REVIEW_HASH',
						"MD5(CONCAT(%s, IFNULL(%s,''), IFNULL(%s,'')))",
						['PREVIEW_TEXT', 'POSITIVE_TEXT.VALUE', 'NEGATIVE_TEXT.VALUE']
					)
				)
				->registerRuntimeField(
					(new ReferenceField(
						'REVIEW_PRODUCT',
						ElementCatalogTable::class,
						Join::on('this.PRODUCT.VALUE', 'ref.ID')

					))->configureJoinType(Join::TYPE_INNER)
				)
				->where('RATE.VALUE', '>=', 4)
				->where('ACTIVE', 'Y')
				->whereNot('PREVIEW_TEXT', '')
				->where('REVIEW_PRODUCT.ACTIVE', 'Y')
				->whereIn('REVIEW_PRODUCT.IBLOCK_SECTION_ID', $subsections)
				->whereNull('REVIEW_PRODUCT.PRICE_ON_REQUEST.VALUE')
				->whereNull('REVIEW_PRODUCT.ARCHIVE.VALUE')
				->where('REVIEW_PRODUCT.AVAILABLE_IN_STOCK.VALUE', '1')
				->addGroup('REVIEW_HASH')
				->fetchAll(),
			'MIN_ID'
		);

		if (!$reviewIds) {
			return null;
		}

		$params = ReviewQueryParams::create()
			->addFilter('ID', Arr::random($reviewIds, min(count($reviewIds), static::MAX_REVIEWS_FOR_SECTION)))
			->disablePagination();
		return static::getReviews($params);
	}

	private static function getPropertyIds(): array
	{
		$callback = function() {
			$iterator = PropertyTable::query()
				->addSelect('ID')
				->addSelect('CODE')
				->where('ACTIVE', 'Y')
				->where(
					Query::filter()
						->logic('or')
						->where(
							Query::filter()
								->where('IBLOCK_ID', CATALOG_IBLOCK_ID)
								->whereIn('CODE', ['PRICE_ON_REQUEST', 'AVAILABLE_IN_STOCK', 'ARCHIVE', 'ALIASE'])
						)
						->where(
							Query::filter()
								->where('IBLOCK_ID', COMMENTS_IBLOCK_ID)
								->whereIn('CODE', ['PRODUCT', 'RATE', 'POSITIVE_TEXT', 'NEGATIVE_TEXT'])
						)
				)
				->exec();

			$propertyIds = [];
			while ($property = $iterator->fetch()) {
				$propertyIds[$property['CODE']] = $property['ID'];
			}

			return $propertyIds;
		};

		return Cache::create()
			->addTag('reviewProperties')
			->addKey(__METHOD__)
			->setCallback($callback)
			->getResult();
	}

	private static function getIbElementsByFilterForPage(array $filter, int $pageNumber, int $limit): array
	{
		$select = [
			'NAME',
			'PREVIEW_TEXT',
			'PROPERTY_*',
		];
		$sort = ['ID' => 'DESC'];
		return static::getHelper()->getElementsForPage($filter, $limit, $pageNumber, $select, $sort);
	}

	private static function getHelper(): IblockHelper
	{
		return IblockHelper::forIblock(COMMENTS_IBLOCK_ID);
	}

	private static function getReviewIdsForPage(ReviewQueryParams $params): array
	{
		$sort = [];
		foreach ($params->getSort() as $by => $direction) {
			$name = $by;
			if (Str::startsWith($name, 'PROPERTY_')) {
				$name = Str::replace('PROPERTY_', '', $name) . '.VALUE';
			}

			$sort[$name] = $direction;
		};

		$query = ElementCommentsTable::query()
			->addSelect('ID');
		if ($bestReviewId = $params->getBestReviewId()) {
			$query->registerRuntimeField(
				new ExpressionField(
					'IS_BEST_REVIEW',
					'CASE WHEN %s = ' .$bestReviewId. ' THEN 1 ELSE 0 END',
					['ID']
				)
			);

			$sort = array_merge(['IS_BEST_REVIEW' => 'DESC'], $sort);
		}

		if ($productId = $params->getProductId()) {
			$query->where('PRODUCT.VALUE', $productId);
		}

		if (!$params->isShowWithoutMedia()) {
			$query->where(
				Query::filter()
					->logic('or')
					->whereNotNull('MORE_PHOTO.VALUE')
					->whereNotNull('VIDEO_LINKS.VALUE')
			);
		}

		$limit = $params->getShowPerPage();
		$query
			->where('ACTIVE', 'Y')
			->setOrder($sort);

		if ($params->isPaginationEnabled()) {
			$query->setLimit($limit)->setOffset($limit * ($params->getPageNumber() - 1));
		}

		return array_column($query->fetchAll(), 'ID');
	}

	public static function clearCacheForProductId(int $productId): void
	{
		Cache::clearByTag('product_reviews_' . $productId);
	}
}