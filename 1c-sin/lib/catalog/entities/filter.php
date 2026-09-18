<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Bitrix\Iblock\ElementPropertyTable;
use Bitrix\Iblock\Template\Entity\ElementProperty;
use Illuminate\Support\Str;
use Rusgeocom\Rusgeocom\Api\Helpers\CatalogSectionPageHelper;
use Illuminate\Support\Arr;
use Rusgeocom\Rusgeocom\Api\Helpers\Router;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\SectionTree;
use Rusgeocom\Rusgeocom\Catalog\Section;
use Rusgeocom\Rusgeocom\Stores\StoreService;
use Rusgeocom\Rusgeocom\Types\Uri;
use Rusgeocom\Rusgeocom\Utils\Url;

class Filter implements \JsonSerializable
{
	private $globalFilter;
	private $showManagerProps;
	private $delPropLinks;
	private $mainProps;
	private $managerProps;
	private $managerFilterUrl;
	private $clearUrl;

	private int $sectionId = 0;

	/** @var Uri */
	private $originalUri;

	/** @var Uri */
	private $uri;

	public function __construct(array $komboxResult, array $globalFilter, Uri $uri, bool $showManagerProps = false)
	{
		$this->globalFilter = $globalFilter;
		$this->uri = $uri;
		$this->sectionId = (int)$komboxResult['SECTION']['ID'];
		$this->originalUri = new Uri($komboxResult['FILTER_ORIGINAL_URL']);
		$this->showManagerProps = $showManagerProps;
		$this->managerFilterUrl = $komboxResult['FILTER_SEF_URL'];
		$this->delPropLinks = $this->makeDeletePropUrls($komboxResult);
		$this->mainProps = $this->makeMainProps($komboxResult);
		$this->managerProps = $this->makeManagerProps($komboxResult);
		$this->clearUrl = $komboxResult['DELETE_URL'] ?: '';
	}

	public function getGlobalFilter(): array
	{
		return $this->globalFilter;
	}

	private function getMinPropValue(array $bxProp, bool $ignoreCount = false): float
	{
		$minValue = PHP_INT_MAX;
		foreach ($bxProp['VALUES'] as $value) {
			if (!$ignoreCount && !$value['CNT']) {
				continue;
			}

			if ((float)$value['VALUE'] < $minValue) {
				$minValue = (float)$value['VALUE'];
			}
		}

		return $minValue;
	}

	private function getMaxPropValue(array $bxProp, bool $ignoreCount = false): float
	{
		$maxValue = 0;
		foreach ($bxProp['VALUES'] as $value) {
			if (!$ignoreCount && !$value['CNT']) {
				continue;
			}

			if ((float)$value['VALUE'] > $maxValue) {
				$maxValue = (float)$value['VALUE'];
			}
		}

		return $maxValue;
	}

	private function translatePropToJson(array $bxProp, array $komboxRequest): array
	{
		$isPrice = (bool)$bxProp['PRICE'];
		$isForceSlider = (int)$bxProp['SETTINGS']['TRANSLATE_TO_SLIDER'];
		$showInColumns = (bool)$bxProp['SETTINGS']['SHOW_IN_COLUMNS'];

		$minValue = 0;
		$maxValue = 0;
		$totalMin = 0;
		$totalMax = 0;
		$shownChecks = [];
		$hiddenChecks = [];
		$step = 1;

		if ($isPrice){
			$totalMin = (int)$bxProp['VALUES']['MIN']['RANGE_VALUE'] ?: 0;
			$minValue = $bxProp['VALUES']['MIN']['VALUE'] ?: 0;
			$totalMax = (int)$bxProp['VALUES']['MAX']['RANGE_VALUE'] ?: 0;
			$maxValue = $bxProp['VALUES']['MAX']['VALUE'] ?: 0;
			if ($totalMax) {
				$maxValue = min($maxValue, $totalMax);
			}
		} elseif ($isForceSlider) {
			$totalMin = $this->getMinPropValue($bxProp, true);
			$step = $this->getStepForProp($bxProp);
			$totalMax = $this->getMaxPropValue($bxProp, true);

			$maxValue = null;
			if (isset($komboxRequest[$bxProp['CODE_ALT'] . '_to'])) {
				$maxValue = (float)($komboxRequest[$bxProp['CODE_ALT'] . '_to']);
			}

			$minValue = null;
			if (isset($komboxRequest[$bxProp['CODE_ALT'] . '_from'])) {
				$minValue = (int)($komboxRequest[$bxProp['CODE_ALT'] . '_from']);
			}

			// берем значение из seo-фильтра по конкретному значению
			if (
				$komboxRequest[$bxProp['CODE_ALT']]
				&& is_array($komboxRequest[$bxProp['CODE_ALT']])
				&& count($komboxRequest[$bxProp['CODE_ALT']]) === 1
			) {
				$minValue = $maxValue = (float)array_values($komboxRequest[$bxProp['CODE_ALT']])[0];
			}

			if ($maxValue !== null && $totalMax) {
				$maxValue = min($maxValue, $totalMax);
			}
		} else{
			$counter = 0;
			foreach ($bxProp['VALUES'] as $kCheck => $bxCheck){
				if (!$bxCheck['VALUE']){
					continue;
				}

				if (
					isset($bxProp['SETTINGS']['REPLACE_SLIDER_WITH_CHECKBOX'])
					&& $bxCheck['CNT'] === 0
				) {
					continue;
				}

				$check = [
					'id' => (string)$bxCheck['VALUE_ID'] ?: (string)$bxCheck['HTML_VALUE_ALT'] ?: (string)$kCheck,
					'checked' => (bool)$bxCheck['CHECKED'],
					'disabled' => !$bxCheck['CNT'],
					'name' => html_entity_decode($bxCheck['VALUE']),
					'count' => $bxCheck['CNT'],
				];

				$showCount = $bxProp['SETTINGS']['VALUES_CNT'] ?: 8;

				if ($counter < $showCount){
					$shownChecks[] = $check;
				}
				else{
					$hiddenChecks[] = $check;
				}
				$counter++;
			}
		}

		$result = [
			'title' => html_entity_decode($bxProp['NAME']),
			'id' => $isPrice ? 'price' : $bxProp['ID'],
			'type' => $isPrice || $isForceSlider ? 'range' : 'checks',
			'inputName' => $bxProp['CODE_ALT'],
			'hint' => null,
			'boolean' => false, // Если да/нет
			'showInColumns' => false,
		];

		if ($result['type'] === 'checks' && count($shownChecks) === 1) {
			foreach ($shownChecks as $check) {
				if (mb_strtolower($check['name']) === 'да') {
					$result['boolean'] = true;
					break;
				}
			}
		}

		switch ($result['type']){
			case 'range':
				if ($result['id'] === 'price') {

					$disable = false;
					if (
						(!$totalMin && !empty($this->originalUri->getParams())) ||
						($totalMin == $totalMax && $totalMin != 0 && $totalMax != 0)
					) {
						$disable = true;
					}

					$result['range'] = [
						'minValue' => $minValue,
						'totalMinValue' => $totalMin ?: $minValue,
						'maxValue' => $maxValue,
						'totalMaxValue' => $totalMax ?: $maxValue,
						'step' => $step,
						'disable' => $disable,
					];
				} else {
					$result['range'] = [
						'totalMinValue' => $totalMin,
						'totalMaxValue' => $totalMax,
						'minValue' => $minValue,
						'maxValue' => $maxValue,
						'step' => $step,
					];
				}
				break;
			case 'checks':
				$result['checks'] = [
					'shown' => $shownChecks,
					'hidden' => $hiddenChecks,
				];
				$result['showInColumns'] = $showInColumns;
				break;
		}

		return $result;
	}

	private function getStepForProp(array $bxProp): float
	{
		foreach ($bxProp['VALUES'] as $value) {
			if ((float)$value['VALUE'] != (int)$value['VALUE']) {
				return 0.1;
			}
		}

		return 1;
	}

	private function makeDeletePropUrls(array $komboxResult): array
	{
		$result = [];

		foreach ($komboxResult['DELHREF'] as $propId => $prop){
			foreach ($prop as $checkId => $check){
				$check['href'] = $this->makeCorrectUrl($check['href']);

				$result[] = [
					'id' => strtolower($checkId),
					'propId' => (string)$propId,
					'name' => html_entity_decode($check['NAME']),
					'url' => $check['href'],
				];
			}
		}

		return $result;
	}

	public function addParamsToUrls(array $params): self
	{
		if (!$params) {
			return $this;
		}

		foreach ($this->delPropLinks as $key => $delPropLink) {
			$this->delPropLinks[$key]['url'] = (new Uri($delPropLink['url']))->addParams($params)->getUri();
		}

		$this->clearUrl = (new Uri($this->clearUrl))->addParams($params)->getUri();
		$this->managerFilterUrl = (new Uri($this->managerFilterUrl))->addParams($params)->getUri();

		return $this;
	}

	private function makeCorrectUrl($url): string
	{
		if (!explode('/f/', $url)[1]) {

			return explode('/f/', $url)[0];
		}

		return $url;
	}

	private function makeManagerProps(array $komboxResult): array
	{
		$props = [];

		if (!$this->showManagerProps){
			return $props;
		}

		// Склад
		$storeChecks = [];
		$requestStoreIds = $this->uri->getParam('stores', []);
		foreach (StoreService::getInstance()->getAll() as $store){
			$storeChecks[] = [
				'id' => $store->getId(),
				'checked' => in_array($store->getId(), $requestStoreIds),
				'disabled' => false,
				'hint' => null,
				'name' => $store->getNameForManager(),
				'count' => null, // Такая штука говорит фронту, что циферки выводить не надо
			];
		}
		$props[] = [
			'title' => 'Склад',
			'id' => 'stores',
			'type' => 'checks',
			'hint' => null,
			'checks' => [
				'shown' => $storeChecks,
				'hidden' => [],
			]
		];

		// Количество на складе
		$props[] = [
			'title' => 'Количество на складе',
			'id' => 'quantity',
			'type' => 'range',
			'hint' => null,
			'range' => [
				'minValue' => $this->uri->getParam('quantity_from', 0),
				'maxValue' => $this->uri->getParam('quantity_to', 0),
			]
		];

		// Количество резерв
		$props[] = [
			'title' => 'Количество резерв',
			'id' => 'REZERV',
			'type' => 'range',
			'hint' => null,
			'range' => [
				'minValue' => $this->uri->getParam('REZERV_MIN', 0),
				'maxValue' => $this->uri->getParam('REZERV_MAX', 0),
			]
		];

		return $props;
	}

	private function makeMainProps(array $komboxResult): array
	{
		$out = [];

		$propCodes = [];
		$mergedProperties = array_merge(
			$komboxResult['SHOWN_ITEMS'],
			$komboxResult['HIDDEN_ITEMS']
		);
		foreach ($mergedProperties as $bxProp){
			if ($bxProp['SETTINGS']['TRANSLATE_TO_SLIDER'] === "1") {
				$activeOptions = Arr::where(
					$bxProp['VALUES'],
					fn(array $item) => !isset($item['DISABLED'])
				);
				if (count($activeOptions) <= 1) {
					$bxProp['SETTINGS']['TRANSLATE_TO_SLIDER'] = "0";
					$bxProp['SETTINGS']['REPLACE_SLIDER_WITH_CHECKBOX'] = true;
				}
			}
			if (!$bxProp['SHOW_PROPERTY']){
				continue;
			}

			$propCodes[] = $bxProp['CODE'];
			$out[$bxProp['CODE']] = $this->translatePropToJson($bxProp, $komboxResult['REQUEST']);
		}

		$hints = \Rusgeocom\Rusgeocom\Catalog\Filter::getHintsByPropCodes($propCodes);
		foreach ($hints as $propCode => $hint){
			if ($out[$propCode]){
				$out[$propCode]['hint'] = $hint;
			}
		}

		// пересортируем элементы, чтобы первыми стояли активные
		foreach ($out as $key => $oneCheckboxType) {
			if ($oneCheckboxType['checks']) {
				$out[$key]['checks'] = $this->sortCheckboxes($oneCheckboxType['checks']);
			}
		}

		return array_values($out);
	}

	public function sortCheckboxes(array $oneCheckboxType): array
	{
		$enabled = [];
		$disabled = [];
		$numberOfShown = count($oneCheckboxType['shown']);

		foreach ($oneCheckboxType as $elements) {
			foreach ($elements as $element) {
				if (!$element['disabled']) {
					$enabled[] = $element;
				}
				else {
					$disabled[] = $element;
				}
			}
		}

		$allElements = array_merge($enabled, $disabled);

		$result = [
			'shown' => array_slice($allElements, 0, $numberOfShown),
			'hidden' => array_slice($allElements, $numberOfShown),
		];

		return $result;
	}

	private function makeSectionTree(): array
	{
		$sectionTree = SectionTree::getInstance();
		$navChain = $sectionTree->getNavPath($this->sectionId);
		$activeSectionIds = array_column($navChain, 'ID');
		$tree = $sectionTree->getTree();

		return $this->getSectionTreeRecursive($tree, $activeSectionIds);
	}

	private function getSectionTreeRecursive(array $sections, array $activeSectionIds): array
	{
		if (!$sections){
			return [];
		}

		$result = [];
		foreach ($sections as $section){
			$result[] = [
				'name' => $section['NAME'],
				'url' => $section['URL'],
				'isActive' => in_array($section['ID'], $activeSectionIds, true),
				'children' => $section['CHILDREN'] ? $this->getSectionTreeRecursive($section['CHILDREN'], $activeSectionIds): [],
			];
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return [
			'clearUrl' => $this->clearUrl,
			'delPropLinks' => $this->delPropLinks,
			'mainProps' => $this->mainProps,
			'managerProps' => empty($this->managerProps) ? null : $this->managerProps,
			'sections' => $this->makeSectionTree(),
		];
	}
}