<?php

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class GeneralReview implements \JsonSerializable
{
	private $name = '';
	private $comment = '';
	private $placeOfWork = '';

	public function __construct(array $dbReview)
	{
		$this->name = $dbReview['UF_NAME'] ?? '';
		$this->comment = $dbReview['UF_COMMENT'] ?? '';
		$this->placeOfWork = $dbReview['UF_PLACE_OF_WORK'] ?? '';
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getComment(): string
	{
		return $this->comment;
	}

	public function getPlaceOfWork(): string
	{
		return $this->placeOfWork;
	}

	/**
	 * @inheritDoc
	 */
	public function jsonSerialize(): array
	{
		return [
			'name' => $this->name,
			'comment' => $this->comment,
			'placeOfWork' => $this->placeOfWork,
		];
	}
}