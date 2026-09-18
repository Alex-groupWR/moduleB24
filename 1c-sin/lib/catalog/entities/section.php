<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Bitrix\Iblock\SectionTable;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Api\Helpers\CatalogSectionPageHelper;
use Rusgeocom\Rusgeocom\Catalog\SectionTemplateHelper;
use Rusgeocom\Rusgeocom\Catalog\SectionTree;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Seo\PageMeta;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Types\NavChain;
use Rusgeocom\Rusgeocom\Types\PageCustomJsScripts;
use Rusgeocom\Rusgeocom\Types\PageSeoData;
use Rusgeocom\Rusgeocom\Types\Uri;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use Rusgeocom\Rusgeocom\Ui\Tools;
use Rusgeocom\Rusgeocom\Utils\IBlockHelperRegistry;
use Rusgeocom\Rusgeocom\Utils\Url;
use Rusgeocom\Rusgeocom\Utils\VideoLink;

class Section
{
	private $iblockSection = [];
	public const ARTICLES_SECTION_ID = 25;

	public function __construct(array $iblockSection)
	{
		$this->iblockSection = $iblockSection;
	}

	public function getId(): int
	{
		return $this->iblockSection['ID'];
	}

	public function isActive(): bool
	{
		return $this->iblockSection['ACTIVE'] === 'Y';
	}

	public function isShowForManagersOnly(): bool
	{
		return !!$this->iblockSection['UF_SHOW_FOR_MANAGERS_ONLY'];
	}

	public function showPopularProducts(): bool
	{
		return !($this->iblockSection['UF_HIDE_POPULAR_PRODUCTS'] ?? false);
	}

	public function isReviewsHidden(): bool
	{
		return (bool)$this->iblockSection['UF_HIDE_BEST_REVIEWS'];
	}

	public function getName(?Localizer $localizer = null): string
	{
		if ($localizer){
			return $localizer->localize($this->iblockSection['NAME'], $this->iblockSection['UF_NAME_EN']);
		}

		return $this->iblockSection['NAME'];
	}

	public function getTitle(?Localizer $localizer = null): string
	{
		$ruTitle = $this->iblockSection['UF_H1'] ?: $this->getName();
		if ($localizer) {
			return $localizer->localize($ruTitle, $this->getName($localizer));
		}

		return $ruTitle;
	}

	public function getUrl(): string
	{
		return $this->iblockSection['UF_ALIASE'] ?: '';
	}

	public function getDescription(): string
	{
		return $this->iblockSection['DESCRIPTION'] ?: '';
	}

	public function getAdditionalText(): string
	{
		return $this->iblockSection['UF_HTML_AFTER_DESCRIPTION'] ?: '';
	}

	public function getRegionDescription(): string
	{
		return $this->iblockSection['UF_REG_CONTENT'] ?: '';
	}

	public function getTemplateId(): int
	{
		return $this->iblockSection['UF_TMPL'] ?: 0;
	}

	public function needShowFilter(): bool
	{
		return $this->iblockSection['UF_SHOW_FILTER'] == SECTION_UF_SHOW_FILTER_YES;
	}

	public function useSaleStatisticsWithStoreAvailable(): int
	{
		return $this->iblockSection['UF_USE_SALE_STATISTICS_WITH_STORE_AVAILABLE'] ?: 0;
	}

	public function getDetailPicture(): ?Image
	{
		if (!$this->iblockSection['DETAIL_PICTURE']){
			return null;
		}

		return Image::fromIblockElement($this->iblockSection['DETAIL_PICTURE'], $this->getName());
	}

	public function getTemplateSettings(): array
	{
		return SectionTemplateHelper::getTemplateSettings($this->getTemplateId());
	}

	public function needShowAccessors(): bool
	{
		return !$this->iblockSection['UF_NOT_SHOW_ACESSORY'];
	}

	public function needShowComplects(): bool
	{
		return !!$this->iblockSection['UF_SHOW_COMPLECTS'];
	}

	public function isSortUseRests(): bool
	{
		return !!$this->iblockSection['UF_USE_RESTS'];
	}

	public function onlyArchiveProducts(): bool
	{
		return !!$this->iblockSection['UF_ARCHIVE_ITEMS_ONLY'];
	}

	public function getEndPageHtml(): string
	{
		return $this->iblockSection['UF_HTML_END_PAGE'] ?: '';
	}

	public function getSeoText(string $domain = ''): string
	{
		if (!$domain) {
			$domain = BranchCityService::getInstance()->getCurrentCity()->getDomain();
		}

		$text = $this->iblockSection['DESCRIPTION'] ?: '';
		$index = strpos($text, '[/bitrix]');
		if ($index !== false) {
			$text = substr($text, $index + strlen('[/bitrix]'));
		}

		if ($domain != 'www' && $this->iblockSection['UF_REG_CONTENT']) {
			$text = $this->iblockSection['UF_REG_CONTENT'];
			$text = Tools::contentForRegionByTemplate($text, $domain);
			Tools::replaceSEOPatternCity($text);
			Tools::replaceContactInfo($text);
		}

		$text = preg_replace('/\[bitrix(.*?)\](.*?)\[\/bitrix\]/s', '', $text);
		$text = preg_replace('/\[bitrix_group\](.*?)\[\/bitrix_group\]/s', '', $text);
		$text = preg_replace('/\[bitrix_section\](.*?)\[\/bitrix_section\]/s', '', $text);

		$text = Tools::removeHtmlScripts($text);
		$text = trim($text);

		return str_replace('src="assets', 'src="/assets', $text);
	}

	public function getDescriptionScripts(): PageCustomJsScripts
	{
		return PageCustomJsScripts::fromHtml($this->getDescription());
	}

	public function getRegionDescriptionScripts(): PageCustomJsScripts
	{
		return PageCustomJsScripts::fromHtml($this->getRegionDescription());
	}

	public function getNavChain(Localizer $localizer): NavChain
	{
		return \Rusgeocom\Rusgeocom\Catalog\Section::makeNavChain($localizer, $this->getId(), $this->onlyArchiveProducts());
	}

	public function getPropArray(): array
	{
		$props = [];
		foreach ($this->iblockSection as $key => $value){
			if (strpos($key, 'UF_') === 0) {
				$props[$key] = $value;
			}
		}

		return $props;
	}

	/**
	 * @return array{sections: array, popularSections: array}
	 */
	public function getSectionsForRelinks(): array
	{
		$templateSettings = $this->getTemplateSettings();
		if (!$templateSettings['SHOW_SECTION_LIST']){
			return [];
		}

		$sectionTreeItems = SectionTree::getInstance()->getChildren($this->getId());
		if (!$sectionTreeItems){
			return [];
		}

		foreach ($sectionTreeItems as $key=>$section) {
			if (!$section['ACTIVE']) {
				unset($sectionTreeItems[$key]);
			}
		}

		$select = [
			'ID',
			'PICTURE',
			'DESCRIPTION',
			'UF_POPULYRS',
			'UF_SHOW_PICTURES',
			'UF_PRODUCT_COUNT',
			'UF_HIDE_FROM_TABS',
			'UF_ARCHIVE_ITEMS_ONLY',
			'UF_FORCE_FILTER_URL',
			'UF_TAB_IMAGE',
		];
		$ibSections = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getSectionsByIds(array_keys($sectionTreeItems), $select);

		$popularSections = [];
		$showPictures = !!$this->iblockSection['UF_SHOW_PICTURES'];
		$sections = [];
		foreach ($sectionTreeItems as $section) {
			$ibSection = $ibSections[$section['ID']];
			$section['DESCRIPTION'] = $ibSection['DESCRIPTION'];
			$section['PRODUCT_COUNT'] = $ibSection['UF_PRODUCT_COUNT'] ?: 0;
			$section['ARCHIVE'] = !!$ibSection['UF_ARCHIVE_ITEMS_ONLY'];
			$section['PICTURE'] = $ibSection['PICTURE'] ?: 0;
			$section['TAB_IMAGE'] = $ibSection['UF_TAB_IMAGE'] ?: 0;
			$section['FORCE_FILTER_URL'] = !!$ibSection['UF_FORCE_FILTER_URL']
				&& CatalogSectionPageHelper::isBrandSection($ibSection['ID']);
			if ($ibSection['UF_HIDE_FROM_TABS']) {
				continue;
			}
			if ($ibSection["UF_POPULYRS"]) {
				$popularSections[] = $section;
			} else {
				if (
					!$section['PRODUCT_COUNT']
					&& !$section["MANAGER_PREVIEW"]
					&& ($section['ARCHIVE'] && !$this->onlyArchiveProducts())
				) {
					continue;
				}
				if (!$section['SHOW_ON_MENU'] && !$section['ARCHIVE']) {
					continue;
				}
				if ($showPictures && !$ibSection['PICTURE']){
					continue;
				}
				if ($section['FORCE_FILTER_URL']) {
					$section['URL'] = CatalogSectionPageHelper::getOriginalLinkFromHtaccess(new Uri($section['URL'])) ?: $section['URL'];
				}

				$sections[] = $section;
			}
		}

		// Массив id разделов для которых выводить доп ссылку
		$refSectionIds = [
			GPS_TOPCON_SECTION_ID,
			GPS_TRIMBLE_SECTION_ID,
			GPS_SOKKIA_SECTION_ID,
			GPS_LEICA_SECTION_ID,
			GPS_ASHTECH_SECTION_ID,
			GPS_SPECTRA_SECTION_ID,
		];
		if (in_array($this->getId(), $refSectionIds) && !$showPictures){
			$sections[] = [
				'NAME' => 'SmartNet',
				'URL' => '/set-referentsnykh-bazovykh-stantsiy',
				'PICTURE' => 0,
				'TAB_IMAGE' => 0,
				'COUNT' => 0,
			];
		}

		return [
			'sections' => $sections,
			'popularSections' => $popularSections,
			'showPictures' => $showPictures,
		];
	}

	public function getPageSeoData(string $domain, string $currentPageUrl): PageSeoData
	{
		$metaData['keywords'] = $this->iblockSection['UF_KEYWORDS'] ?: '';
		if ($domain === 'www'){
			$metaData['browserTitle'] = $this->iblockSection['UF_BROWSER_TITLE'] ?: $this->getName();
			$metaData['description'] = $this->iblockSection['UF_META_DESCRIPTION'] ?: $this->getName();
		}
		else{
			$metaData['browserTitle'] = $this->iblockSection['UF_REG_TITLE'] ?: $this->getName();
			$metaData['description'] = $this->iblockSection['UF_REG_DESCRIPTION'] ?: $this->getName();
		}

		$seo = PageSeoData::create($currentPageUrl, $metaData['browserTitle'])
			->setDescription($metaData['description'])
			->setKeywords($metaData['keywords']);

		$this->addOpenGraphMeta($seo, $metaData);

		return $seo;
	}

	protected function addOpenGraphMeta(PageSeoData &$seo, array $metaData)
	{
		$ogDescription = $this->iblockSection['UF_OG_DESCRIPTION'] ?? $metaData['description'];
		$ogTitle=$this->iblockSection['UF_OG_TITLE'] ?? $metaData['browserTitle'];

		if ($this->getDetailPicture()){
			$picture = $this->getDetailPicture();
		}
		if ($this->iblockSection['UF_OG_IMAGE']){
			$picture = Image::fromIblockElement($this->iblockSection['UF_OG_IMAGE'], $metaData['browserTitle']);
		}

		$seo->setOpenGraphTitle($ogTitle)
			->setOpenGraphDescription($ogDescription);

		if ($picture){
			$seo->setOpenGraphImage($picture);
		}
	}

	public function getPregParams(): ?SectionPregParams
	{
		return SectionPregParams::parseText($this->getDescription());
	}
}