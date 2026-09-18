<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

final readonly class SectionPregParams
{
	function __construct(
		private array $productIds,
		private int $sectionId,
	)
	{
	}

	public static function fromArray(array $params): self
	{
		return new self($params['productIds'], $params['sectionId']);
	}

	public static function parseText(string $text): ?self
	{
		$result = [
			'hasDescription' => false,
			'productIds' => [],
			'sectionId' => 0,
		];

		// Какие-то костыли из старого дизайна
		// В описании раздела псевдотеги, в них перечислены товары для показа
		if (preg_match_all('/\[bitrix(.*?)](.*?)\[\/bitrix]/s', strip_tags($text), $preg)) {
			$result['productIds'] = self::getElementIdsFromBitrixTag($preg[2]);
			$result['hasDescription'] = true;
		}

		if (preg_match_all('/\[bitrix_section](.*?)\[\/bitrix_section]/s', strip_tags($text),
			$preg)) {
			$result['hasDescription'] = true;
			$result['sectionId'] = (int)$preg[1][0];
		}

		return $result['hasDescription'] ? self::fromArray($result) : null;
	}

	public function fillCatalogSectionParams(CatalogQueryParams $params): void
	{
		if ($this->getProductIds()) {
			$params->addFilter('ID', $this->getProductIds());
			$params->addSort('ID', $this->getProductIds());
		}

		if ($this->getSectionId()) {
			$params->setSectionId($this->getSectionId());
		}
	}

	protected static function getAttributesFromBitrixTag(string $attributeString): array
	{
		$parameters = [];
		$attributes = explode(' ', trim($attributeString));
		foreach ($attributes as $attribute) {
			[$attributeKey, $attributeValue] = explode('=', $attribute);
			if (!$attributeValue || trim($attributeValue) === "") {
				continue;
			}
			$attributeValue = str_replace('"', '', $attributeValue);
			if (trim($attributeKey) === 'limit') {
				$limit = (int)$attributeValue;
				$parameters['limit'] = $limit > 0 ? $limit : 1000;
			}
		}

		return $parameters;
	}

	/**
	 * @return int[]
	 */
	protected static function getElementIdsFromBitrixTag(array $matches): array
	{
		$elementIds = [];

		$elements = [];
		// сортировка товаров псевдотэга
		foreach ($matches as $ids) {
			$elements = explode(",", $ids);
		}

		foreach ($elements as $value) {
			if (trim($value) === "") {
				continue;
			}
			if (trim($value) > 1) {
				$elementIds[] = (int)trim($value);
			}
		}

		return $elementIds;
	}

	public function getProductIds(): array
	{
		return $this->productIds;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	public function getSectionId(): int
	{
		return $this->sectionId;
	}

	public function toArray(): array
	{
		return [
			'productIds' => $this->productIds,
			'limit' => $this->limit,
			'sectionId' => $this->sectionId,
		];
	}
}
