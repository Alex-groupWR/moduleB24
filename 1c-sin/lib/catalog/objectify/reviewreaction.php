<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Objectify;

use Rusgeocom\Rusgeocom\Catalog\Tables\EO_ReviewReaction;
use Rusgeocom\Rusgeocom\Catalog\Tables\ReviewReactionTable;

class ReviewReaction extends EO_ReviewReaction
{
	public static function getActualEntry(int $userId, int $reviewId): static
	{
		$entityObject = ReviewReactionTable::getList([
			'filter' => [
				'USER_ID' => $userId,
				'REVIEW_ID' => $reviewId,
			],
			'limit' => 1,
		])->fetchObject();

		if ($entityObject instanceof ReviewReaction) {
			$entityObject->fill();
			return $entityObject;
		}

		return ReviewReactionTable::createObject()
			->setUserId($userId)
			->setReviewId($reviewId);
	}
}