<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Logema\Utils\DataAccess\IblockPropertyValueExtractor;
use Logema\Utils\DateTime;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Ui\Enums\MediaType;
use Rusgeocom\Rusgeocom\Utils\DateFormatter;
use Rusgeocom\Rusgeocom\Utils\VideoLink;

class Review implements \JsonSerializable
{
	public const array SMALL_IMAGE_SIZE = [150];
	public const array MEDIUM_IMAGE_SIZE = [500];
	public const array BIG_IMAGE_SIZE = [500];

	private $productName = '';
	private $id = 0;
	private $createdBy = 0;
	private $userName = '';
	private $rating = 0;
	private $likeCount = 0;
	private $dislikeCount = 0;
	private $whereFromReview = '';
	private $imageIds = [];
	private array $videoLinks = [];
	private $positiveText = '';
	private $negativeText = '';
	private $commentText = '';
	private $productId = '';
	private $productPicture = '';
	private string $reviewUrl = '';
	private array $reactions = [];
	private bool $isBestReview = false;
	private bool $isRusgeocomBuyer = false;

	/** @var DateTime */
	private $date;

	public function __construct(
		array $ibElement,
		int $productId,
		string $productName,
		?string $productPicture = null,
		?string $reviewUrl = null
	) {
		$props = IblockPropertyValueExtractor::forElement($ibElement);
		$this->productName = $productName ?: '';
		$this->id = $ibElement['ID'] ?: 0;
		$this->createdBy = $ibElement['CREATED_BY'] ?: 0;
		$this->userName = $ibElement['NAME'] ?: '';
		$this->rating = $props['RATE'] ?: 0;
		$this->whereFromReview = $props['WHERE_FROM_REVIEW'];
		$this->date = $props["DATE"];
		$this->imageIds = $props['MORE_PHOTO'] ?: [];
		$this->videoLinks = $props['VIDEO_LINKS'] ?: [];

		$this->positiveText = $props['POSITIVE_TEXT'] ?: '';
		$this->negativeText = $props['NEGATIVE_TEXT'] ?: '';
		$this->commentText = $ibElement['~PREVIEW_TEXT'] ?: '';
		$this->likeCount = $ibElement['PROPERTIES']['PLUS']['VALUE'] ?: 0;
		$this->dislikeCount = $ibElement['PROPERTIES']['MINUS']['VALUE'] ?: 0;
		$this->productId = $productId;
		$this->productPicture = $productPicture ?? '';
		$this->reviewUrl = $reviewUrl ?? '';
		$this->isRusgeocomBuyer = (bool)$props['RUSGEOCOM_BUYER'];
	}

	public function getProductId(): int
	{
		return $this->productId;
	}

	public function getProductPicture(): string
	{
		return $this->productPicture;
	}

	public function getProductName(): string
	{
		return $this->productName;
	}


	public function getId(): int
	{
		return $this->id;
	}

	public function getUserName(): string
	{
		return $this->userName;
	}

	public function getRating(): int
	{
		return $this->rating;
	}

	public function whereFromReview(): string
	{
		return $this->whereFromReview;
	}

	public function getDate(): DateTime
	{
		return $this->date;
	}

	public function getImages(): array
	{
		$images = [];
		foreach ($this->imageIds as $imageId) {
			$images[] = $this->makeImage($imageId);
		}
		return $images;
	}

	public function getMedias(): array
	{
		return array_merge($this->getVideos(), $this->getImages());
	}

	public function getFirstMedia(): array
	{
		if ($videos = $this->getVideos()) {
			return current($videos);
		}

		if (!$this->imageIds) {
			return [];
		}

		return $this->makeImage((int)current($this->imageIds));
	}

	private function makeImage(int $imageId): array
	{
		return [
			'id' => $imageId,
			'reviewId' => (int)$this->id,
			'mediaType' => MediaType::Image,
			'image' => Image::fromIblockElement($imageId, $this->productName)
				->resize(
					smallSize: static::SMALL_IMAGE_SIZE,
					mediumSize: static::MEDIUM_IMAGE_SIZE,
					bigSize: static::BIG_IMAGE_SIZE
				),
		];
	}

	private function makeVideo(array $video): array
	{
		return [
			'reviewId' => $this->getId(),
			'mediaType' => MediaType::Video,
			'video' => $video,
		];
	}

	public function hasMedia(): bool
	{
		return !empty($this->videoLinks) || !empty($this->imageIds);
	}

	public function getImageIds(): array
	{
		return $this->imageIds;
	}

	public function getVideos(): array
	{
		$videos = [];
		foreach ($this->videoLinks as $videoLink) {
			$videos[] = $this->makeVideo(VideoLink::parse($videoLink));
		}
		return $videos;
	}

	public function getVideoLinks(): array
	{
		$videos = [];
		foreach ($this->videoLinks as $videoLink) {
			$videos[] = VideoLink::parse($videoLink);
		}
		return $videos;
	}

	public function getPositiveText(): string
	{
		return $this->positiveText;
	}

	public function getNegativeText(): string
	{
		return $this->negativeText;
	}

	public function getCommentText(): string
	{
		return $this->commentText;
	}

	public function incrementLike(): Review
	{
		$this->likeCount++;
		return $this;
	}

	public function incrementDislike(): Review
	{
		$this->dislikeCount++;
		return $this;
	}

	public function getReviewUrl(): string
	{
		return $this->reviewUrl;
	}

	public function setReactions(array $reactions): void
	{
		$this->reactions = $reactions;
	}

	public function getReactions(): array
	{
		return $this->reactions;
	}

	public function isBestReview(): bool
	{
		return $this->isBestReview;
	}

	public function setBestReview(): void
	{
		$this->isBestReview = true;
	}

	public function isRusgeocomBuyer(): bool
	{
		return $this->isRusgeocomBuyer;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'name' => $this->getUserName(),
			'rating' => $this->getRating(),
			'whereFromReview' => $this->whereFromReview(),
			'date' => $this->getDate()->format('d.m.Y'),
			'agoForHumans' => DateFormatter::formatAgoForHumans($this->getDate()->toBitrixDate()),
			'localizedDate' => DateFormatter::formatLocalized($this->getDate()->toBitrixDate()),
			'images' => $this->getImages(),
			'videos' => $this->getVideoLinks(),
			'medias' => $this->getMedias(),
			'positiveText' => $this->getPositiveText(),
			'negativeText' => $this->getNegativeText(),
			'commentText' => $this->getCommentText(),
			'productId' => $this->getProductId(),
			'productName' => $this->getProductName(),
			'productPicture' => $this->getProductPicture(),
			'reviewUrl' => $this->getReviewUrl(),
			'reactions' => $this->getReactions(),
			'isBestReview' => $this->isBestReview(),
			'isRusgeocomBuyer' => $this->isRusgeocomBuyer(),
		];
	}
}