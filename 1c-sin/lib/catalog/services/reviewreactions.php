<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Illuminate\Support\Str;
use Rusgeocom\Rusgeocom\Catalog\Enums\Reaction;
use Rusgeocom\Rusgeocom\Catalog\Objectify\ReviewReaction;
use Rusgeocom\Rusgeocom\Catalog\Tables\ReviewReactionTable;
use Rusgeocom\Rusgeocom\Laravel\Exchange\ReviewUpdater;

final class ReviewReactions
{
	public static function like(int $reviewId, int $userId): void
	{
		self::setReaction($reviewId, $userId, Reaction::Liked);
	}

	public static function dislike(int $reviewId, int $userId): void
	{
		self::setReaction($reviewId, $userId, Reaction::Disliked);
	}

	public static function unlike(int $reviewId, int $userId): void
	{
		self::setReaction($reviewId, $userId, Reaction::Unliked);
	}

	private static function setReaction(int $reviewId, int $userId, Reaction $reaction): void
	{
		$review = Reviews::getById($reviewId);
		if (!$review) {
			throw new \Exception('Отзыв не найден');
		}

		$userReaction = ReviewReaction::getActualEntry($userId, $reviewId);
		if ($userReaction->getReaction() !== $reaction->value) {
			$userReaction->setReaction($reaction->value);
			$userReaction->save();
		}

		Reviews::clearCacheForProductId($review->getProductId());
		ReviewUpdater::triggerUpdate($reviewId);
	}

	public static function getReactionsForReview(int $reviewId, int $userId): array
	{
		if (!$reviewId) {
			return [];
		}

		$reviewTotals = self::getStateQuery($userId)
			->where('REVIEW_ID', $reviewId)
			->fetch() ?: [];

		return self::makeReactionsByReviewTotals($reviewTotals);
	}

	public static function getReactionsForReviews(array $reviewIds, int $userId): array
	{
		if (!$reviewIds) {
			return [];
		}

		$iterator = self::getStateQuery($userId)
			->whereIn('REVIEW_ID', $reviewIds)
			->exec();

		$reactions = [];
		while ($reviewTotals = $iterator->fetch()) {
			$reactions[$reviewTotals['REVIEW_ID']] = self::makeReactionsByReviewTotals($reviewTotals);
		}

		$withoutReactions = array_diff($reviewIds, array_keys($reactions));
		foreach ($withoutReactions as $reviewId) {
			$reactions[$reviewId] = self::makeReactionsByReviewTotals([]);
		}

		return $reactions;
	}

	private static function makeReactionsByReviewTotals(array $reviewTotals): array
	{
		$state = [];
		foreach(Reaction::getActiveList() as $reaction) {
			$reactionName = Str::upper($reaction->value);
			$state[$reaction->value] = [
				'count' => (int)$reviewTotals['TOTAL_' . $reactionName] ?? 0,
				'hasUserVote' => ((int)$reviewTotals['TOTAL_USER_' . $reactionName] ?? 0) !== 0,
			];
		}

		return $state;
	}

	private static function getStateQuery(int $userId = 0): Query
	{
		$query = ReviewReactionTable::query()
			->addSelect('REVIEW_ID');

		foreach(Reaction::getActiveList() as $reaction) {
			$reactionName = Str::upper($reaction->value);
			$query
				->registerRuntimeField(
					(new ExpressionField(
						'TOTAL_' . $reactionName,
						"SUM(CASE WHEN REACTION = '" . $reaction->value . "' THEN 1 ELSE 0 END)")
					)
				)
				->addSelect('TOTAL_' . $reactionName);

			if ($userId) {
				$query
					->registerRuntimeField(
						(new ExpressionField(
							'TOTAL_USER_' . $reactionName,
							"SUM(CASE WHEN REACTION = '" . $reaction->value . "' AND USER_ID = " . $userId . " THEN 1 ELSE 0 END)")
						)
					)
					->addSelect('TOTAL_USER_' . $reactionName);
			}
		}

		return $query;
	}

	/**
	 * @param int[] $reviewIds
	 * @return array<int, Reaction>
	 */
	public static function getAllReactionsForReviews(array $reviewIds): array
	{
		if (!$reviewIds) {
			return [];
		}

		$result = [];
		$iterator = ReviewReactionTable::query()
			->addSelect('REVIEW_ID')
			->addSelect('USER_ID')
			->addSelect('REACTION')
			->whereIn('REVIEW_ID', $reviewIds)
			->whereIn('REACTION', [Reaction::Liked->value, Reaction::Disliked->value])
			->whereNotNull('USER.ID') // Есть несуществующие юзеры
			->exec();
		while ($row = $iterator->fetch()) {
			$result[(int)$row['REVIEW_ID']][(int)$row['USER_ID']] = Reaction::from($row['REACTION']);
		}

		return $result;
	}

	public static function getBestReviewForProduct(int $productId): int
	{
		return (int)ReviewReactionTable::query()
			->addSelect('REVIEW_ID')
			->registerRuntimeField(
				(new ExpressionField(
					'LIKED',
					"SUM(CASE WHEN REACTION = '" . Reaction::Liked->value . "' THEN 1 ELSE 0 END)")
				)
			)
			->registerRuntimeField(
				(new ReferenceField(
					'ELEMENT',
					ElementTable::class,
					Join::on('this.REVIEW_ID', 'ref.ID')
				))->configureJoinType('inner')
			)
			->registerRuntimeField(
				new ReferenceField(
					'PRODUCT',
					ElementPropertyTable::class,
					Join::on('this.REVIEW_ID', 'ref.IBLOCK_ELEMENT_ID')
				)
			)
			->registerRuntimeField(
				new ReferenceField(
					'PRODUCT_PROPERTY',
					PropertyTable::class,
					Join::on('this.PRODUCT.IBLOCK_PROPERTY_ID', 'ref.ID')->where('ref.CODE', 'PRODUCT')
				)
			)
			->where('ELEMENT.ACTIVE', 'Y')
			->where('PRODUCT.VALUE', $productId)
			->having('LIKED', '>', 0)
			->addOrder('LIKED', 'DESC')
			->setLimit(1)
			->fetch()['REVIEW_ID'] ?? 0;
	}
}