<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Enums\PriceSubscriptionStatus;
use Rusgeocom\Rusgeocom\Catalog\Objectify\PriceSubscription;
use Rusgeocom\Rusgeocom\Catalog\Tables\PriceSubscriptionTable;
use Rusgeocom\Rusgeocom\Laravel\Exchange\PriceSubscriptionUpdater;
use Rusgeocom\Rusgeocom\Sale\Entities\CustomerAccount;
use Rusgeocom\Rusgeocom\User\Entities\Email;

final class PriceSubscriptionManager
{
	public static function hasSubscription(CustomerAccount|Email $identity, int $productId): bool
	{
		if (!self::isIdentityValid($identity)) {
			return false;
		}

		return (bool)self::getSubscriptionInfo($identity, $productId)->getId();
	}

	public static function subscribe(CustomerAccount|Email $identity, Product $product): void
	{
		if (!self::isIdentityValid($identity)) {
			return;
		}

		$subscription = self::getSubscriptionInfo($identity, $product->getId());
		if ($subscription->getId()) {
			return;
		}

		$subscription
			->setOldPrice($product->getCurrentPrice())
			->setStatus(PriceSubscriptionStatus::New->value)
			->save();
		PriceSubscriptionUpdater::sendCreated($subscription->getId());
	}

	public static function unsubscribe(CustomerAccount|Email $identity, Product $product): void
	{
		if (!self::isIdentityValid($identity)) {
			return;
		}

		$subscription = self::getSubscriptionInfo($identity, $product->getId());
		if (!$subscription->getId()) {
			return;
		}

		$subscription
			->setStatus(PriceSubscriptionStatus::Cancelled->value)
			->save();
		PriceSubscriptionUpdater::sendUpdated($subscription->getId());
	}

	public static function disableById(int $id): void
	{
		$subscription = PriceSubscriptionTable::getById($id)->fetchObject();
		if (!$subscription) {
			return;
		}

		$subscription->setStatus(PriceSubscriptionStatus::Disabled->value)->save();
		PriceSubscriptionUpdater::sendUpdated($subscription->getId());
	}

	public static function markSentById(int $id): void
	{
		$subscription = PriceSubscriptionTable::getById($id)->fetchObject();
		if (!$subscription) {
			return;
		}

		$subscription->setStatus(PriceSubscriptionStatus::Sent->value)->save();
		PriceSubscriptionUpdater::sendUpdated($subscription->getId());
	}

	public static function getActiveSubscriptions(int $limit, int $offset = 0): array
	{
		return PriceSubscriptionTable::query()
			->addSelect('*')
			->addSelect('PROFILE.USER_ID', 'USER_ID')
			->addSelect('ELEMENT.ACTIVE', 'PRODUCT_ACTIVE')
			->where('STATUS', PriceSubscriptionStatus::New->value)
			->addOrder('ID')
			->setLimit($limit)
			->setOffset($offset)
			->fetchAll();
	}

	private static function isIdentityValid(CustomerAccount|Email $identity): bool
	{
		if ($identity instanceof CustomerAccount) {
			return !$identity->getIdentity()->isAbstractCustomer();
		}

		return true;
	}

	private static function getSubscriptionInfo(CustomerAccount|Email $identity, int $productId): PriceSubscription
	{
		return PriceSubscription::getActualEntry($identity, $productId);
	}
}