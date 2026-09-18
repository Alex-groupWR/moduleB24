<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Objectify;

use Rusgeocom\Rusgeocom\Catalog\Enums\PriceSubscriptionStatus;
use Rusgeocom\Rusgeocom\Catalog\Tables\EO_PriceSubscription;
use Rusgeocom\Rusgeocom\Catalog\Tables\PriceSubscriptionTable;
use Rusgeocom\Rusgeocom\Sale\Entities\CustomerAccount;
use Rusgeocom\Rusgeocom\User\Entities\Email;

class PriceSubscription extends EO_PriceSubscription
{
	public static function getActualEntry(CustomerAccount|Email $identity, int $productId): static
	{
		$filter = [
			'PRODUCT_ID' => $productId,
			'STATUS' => PriceSubscriptionStatus::New->value
		];
		if ($identity instanceof Email) {
			$filter['EMAIL'] = $identity->getValue();
		} else {
			$filter['PROFILE_ID'] = $identity->getIdentity()->getProfileId();
		}

		$entityObject = PriceSubscriptionTable::getList([
			'filter' => $filter,
			'limit' => 1,
		])->fetchObject();

		if ($entityObject instanceof PriceSubscription) {
			$entityObject->fill();
			return $entityObject;
		}

		$newObject = PriceSubscriptionTable::createObject()
			->setProductId($productId);
		if ($identity instanceof Email) {
			$newObject->setEmail($identity->getValue());
		} else {
			$newObject->setProfileId($identity->getIdentity()->getProfileId());
		}

		return $newObject;
	}
}