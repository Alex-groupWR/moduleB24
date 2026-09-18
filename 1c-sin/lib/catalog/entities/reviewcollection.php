<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Types\Image;

class ReviewCollection
{
	private const SHOWN_COUNT = 5;

	/** @var Review[] */
	private $reviews = [];

	/**
	 * @param Review[] $reviews
	 */
	public function __construct(array $reviews)
	{
		$this->reviews = $reviews;
	}

	/**
	 * @return Review[]
	 */
	public function getReviews(): array
	{
		return $this->reviews;
	}

	/**
	 * @return Review[]
	 */
	public function getShownReviews(): array
	{
		return array_slice($this->reviews, 0, self::SHOWN_COUNT);
	}

	/**
	 * @return Review[]
	 */
	public function getHiddenReviews(): array
	{
		return array_slice($this->reviews, self::SHOWN_COUNT);
	}

	public function getIds(): array
	{
		return array_map(static fn(Review $review) => $review->getId(), $this->reviews);
	}

	public function setReactions(array $reactions): void
	{
		foreach ($this->reviews as $review) {
			$review->setReactions($reactions[$review->getId()] ?? []);
		}
	}

	public function getImages(): array
	{
		$images = [];
		foreach ($this->reviews as $review){
			foreach ($review->getImages() as $image){
				$images[] = $image;
			}
		}

		return $images;
	}

	public function getFirstMedias(): array
	{
		$medias = [];
		foreach ($this->reviews as $review) {
			$media = $review->getFirstMedia();
			if (!$media) {
				continue;
			}
			$medias[] = $media;
		}

		return $medias;
	}

	public function getCount(): int
	{
		return count($this->reviews);
	}

	public function getRating(): int
	{
		if (!$this->reviews){
			return 0;
		}

		$sumRating = 0;
		foreach ($this->reviews as $review){
			$sumRating += $review->getRating();
		}

		return round($sumRating / $this->getCount());
	}

	public function getProductIds(): array
	{
		$productIds = [];
		foreach ($this->reviews as $review) {
			if (!$productId = $review->getProductId()) {
				continue;
			}
			$productIds[] = $productId;
		}

		return $productIds;
	}
}