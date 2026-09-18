<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Order as BitrixOrder;
use Bitrix\Sale\PropertyValue;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\Tables\ReviewSuggestionsTable;
use Rusgeocom\Rusgeocom\Order\Services\ReviewRequestMailSender;
use Rusgeocom\Rusgeocom\Utils\Settings;

class ReviewSuggestions
{
	private const MAX_DAYS_ALIVE = 14;

	public static function byProducts(int $userId, array $products): array
	{
		$wantReview = [];

		$reviewedAlready = Reviews::reviewedProductIds($userId);
		foreach ($products as $product) {
			if (in_array($product->getId(), $reviewedAlready)) {
				continue;
			}

			$wantReview[] = $product;
		}

		return $wantReview;
	}

	public static function fromCompleteOrders(int $userId): ProductCollection
	{
		$productIds = ReviewSuggestionsTable::query()
			->setDistinct()
			->addSelect('PRODUCT_ID')
			->where('USER_ID', $userId)
			->where('ACTIVE', true)
			->where('DATE_CREATE', '>', static::getEarliestTimeForRecommendation())
			->setOrder(['DATE_CREATE' => 'DESC'])
			->fetchAll();

		$catalogElementIds = array_column($productIds, 'PRODUCT_ID');
		$offers = Catalog::getOffersByIds($catalogElementIds);
		foreach ($offers as $offer){
			$catalogElementIds[] = $offer->getProductId();
		}

		return Catalog::getProductsByIds(array_unique($catalogElementIds));
	}

	public static function addFromOrder(BitrixOrder $order): void
	{
		$catalogElementIds = [];
		foreach ($order->getBasket()->getBasketItems() as $basketItem) {
			$catalogElementIds[] = (int)$basketItem->getField('PRODUCT_ID');
		}

		$userId = (int)$order->getUserId();
		$profileId = 0;
		/** @var PropertyValue $item */
		foreach ($order->getPropertyCollection() as $item) {
			if ($item->getField('CODE') === 'PROFILE_ID') {
				$profileId = (int)$item->getValue();
				break;
			}
		}

		$offers = Catalog::getOffersByIds($catalogElementIds);
		foreach ($offers as $offer){
			$catalogElementIds[] = $offer->getProductId();
		}

		$params = CatalogQueryParams::create()
			->setProductIds(array_unique($catalogElementIds));

		$productIds = Catalog::queryProductIds($params);

		$productsWithoutUserReview = array_diff(
			$productIds,
			Reviews::reviewedProductIds($userId, $productIds)
		);

		foreach ($productsWithoutUserReview as $productWithoutReview) {
			$filter = [
				'USER_ID' => $userId,
				'PROFILE_ID' => $profileId,
				'ORDER_ID' => $order->getId(),
				'PRODUCT_ID' => $productWithoutReview
			];
			if (static::isSuggestionExists($filter)) {
				continue;
			}

			ReviewSuggestionsTable::add($filter);
		}

		if ($productsWithoutUserReview) {
			ReviewRequestMailSender::sendMessage($order->getId());
		}
	}

	public static function deleteByCanceledOrder(BitrixOrder $order): void
	{
		if (!$order->isCanceled()) {
			return;
		}

		$suggestions = static::getSuggestionsByFilter(['ORDER_ID' => $order->getId()]);
		foreach ($suggestions as $suggestion) {
			ReviewSuggestionsTable::delete($suggestion['ID']);
		}
	}

	public static function isSuggestionExists(array $filter): bool
	{
		$suggestion = current(static::getSuggestionsByFilter($filter));
		return isset($suggestion['ACTIVE']) && $suggestion['ACTIVE'];
	}

	public static function hideProduct(int $userId, int $productId): void
	{
		$offers = Catalog::getOffersForProduct($productId);

		$productIds = [$productId];
		if ($offers) {
			$productIds = array_merge($productIds, array_keys($offers));
		}

		$suggestions = static::getSuggestionsByFilter([
			'USER_ID' => $userId,
			'PRODUCT_ID' => $productIds,
		]);
		foreach ($suggestions as $suggestion) {
			if (!$suggestion || !$suggestion['ACTIVE']) {
				continue;
			}

			ReviewSuggestionsTable::update($suggestion['ID'], ['ACTIVE' => false]);
		}
	}

	private static function getSuggestionsByFilter(array $filter): array
	{
		return ReviewSuggestionsTable::query()
			->addSelect('ID')
			->addSelect('ACTIVE')
			->setFilter($filter)
			->fetchAll() ?? [];
	}

	private static function getEarliestTimeForRecommendation(): DateTime
	{
		$limit = Settings::getMaxDaysReviewRecommendationAlive() ?: static::MAX_DAYS_ALIVE;
		return (new DateTime())->add('-' . $limit . ' days');
	}
}