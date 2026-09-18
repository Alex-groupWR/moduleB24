<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class Showcase
{
	private $id;
	private $cityId;
	private $title;
	private $code;
	private $name1C;

	public function __construct(int $id, int $cityId, string $title, string $urlPart, string $name1C)
	{
		$this->id = $id;
		$this->cityId = $cityId;
		$this->title = $title;
		$this->code = $urlPart;
		$this->name1C = $name1C;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getCityId(): int
	{
		return $this->cityId;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getUrl(): string
	{
		return '/showcase/' . $this->getCode();
	}

	public function getName1C(): string
	{
		return $this->name1C;
	}
}