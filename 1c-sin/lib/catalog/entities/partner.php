<?php

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Types\Image;

class Partner implements \JsonSerializable
{
	protected $imageId = 0;
	private $name = '';

	/** @var Image */
	protected $image;

	public function __construct(array $dbPartner)
	{
		$this->name = $dbPartner['UF_NAME'] ?? '';
		$this->imageId = (int)$dbPartner['UF_FILE'];
	}

	public function getName(): string
	{
		return $this->name;
	}

	private function getImage(): Image
	{
		return Image::fromId($this->imageId, $this->getName())->resize([224, 144]);
	}

    /**
     * @inheritDoc
     */
    public function jsonSerialize(): array
    {
		return [
			'name' => $this->name,
			'image' => $this->getImage(),
		];
    }
}