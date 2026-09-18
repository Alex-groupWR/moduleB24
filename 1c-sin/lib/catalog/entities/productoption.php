<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class ProductOption extends ProductOffer
{
	protected $description = '';
	protected $isVerificationCertificate = false;
	public $additionalWorkingDays;

	public function __construct(array $iblockElement)
	{
		parent::__construct($iblockElement);

		$this->description = $iblockElement['~PREVIEW_TEXT'] ?: '';
		$this->isVerificationCertificate = !!$iblockElement['PROPERTIES']['POVERKA']['VALUE'];
		$this->additionalWorkingDays = $iblockElement['PROPERTIES']['WORK_DAYS_ADD']['VALUE'] ?? '';
	}

	public function isVerificationCertificate(): bool
	{
		return $this->isVerificationCertificate;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function getDiscountPrice(): int
	{
		return $this->getBasePrice() - $this->discountValue;
	}

	public function jsonSerialize(): array
	{
		$result =  parent::jsonSerialize();
		$result['price'] = $this->getDiscountPrice();
		$result['oldPrice'] = $this->getBasePrice();
		$result['configurationText'] = $this->getDescription();

		return $result;
	}
}