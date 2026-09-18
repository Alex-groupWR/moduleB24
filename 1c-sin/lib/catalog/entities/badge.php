<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Types\Image;

class Badge implements \JsonSerializable
{
	public const string BADGES_HIGHBLOCK_CODE = 'Badges';
	public const string BADGES_SECTION_PLACE_CODE = 'section'; // Код показа бэйджей в разделе
	public const string BADGES_DETAIL_PLACE_CODE = 'detail'; // Код показа бэйджей на деталке
	public const string BADGES_ALL_PLACE_CODE = 'all'; // Код показа бэйджей везде
	public const string BADGES_ABOVE_TITLE_CODE = 'above_title'; // Код отображения бэйджей над зголовоком
	public const string BADGES_ABOVE_PHOTO_CODE = 'above_photo'; // Код отображения бэйджей над фото
	public const string BADGE_GOSREESTR_CODE = 'gost'; // Код для бэйджа госреестра
	public const string BADGE_HIT_CODE = 'hit'; // Код для бэйджа Хит
	public const string BADGE_ACTION_CODE = 'action'; // Код для бэйджа Акция
	public const string NEW_BADGE_CODE = 'news'; // Код для бэйджа Новинка
	public const string SPLIT_BADGE_CODE = 'split'; // Код для бэйджа Сплит
	public const string DISCOUNT_BADGE_CODE = 'discount'; // Код для бэйджа Сплит
	public const string FIRST_BUY_DISCOUNT_BADGE_CODE = 'first_buy_discount'; // Код для бэйджа Первой скидки

	public const CREDIT_BADGES_CODES = ['creditHidden', 'credit'];
	public const INSTALLMENT_BADGES_CODES = ['installment', 'rassrochka10', 'rassrochka6', 'rassrochka10Hidden', 'rassrochkaHidden', 'rassrochka-0-0-10', 'rassrochka-0-0-6'];
	public const INVISIBLE_BADGES_CODES = ['creditHidden', 'rassrochka10Hidden', 'rassrochkaHidden'];
	public const INSTALLMENT_10_MONTH_CODES = ['rassrochka10', 'rassrochka10Hidden', 'rassrochka-0-0-10'];

	// Бейдж госреестра особенный, он не тянется из БД
	public const BADGE_GOSREESTR_COLOR = '#FFF7';
	public const BADGE_GOSREESTR_DETAIL_COLOR = '#4F4F4F';
	public const BADGE_GOSREESTR_BORDER_COLOR = '#D5DEE3';
	public const BADGE_GOSREESTR_TEXT_COLOR = '#333333';
	public const BADGE_GOSREESTR_TEXT = 'Госреестр';

	//Бейдж скидки
	public const string BADGE_DISCOUNT_COLOR = '#ff0033';
	public const string BADGE_DISCOUNT_TEXT_COLOR = '#ffffff';
	//Бейдж первой покупки
	public const string BADGE_FIRST_BUY_DISCOUNT_COLOR = '#009966';
	public const string BADGE_FIRST_BUY_DISCOUNT_TEXT_COLOR = '#ffffff';
	public const string BADGE_FIRST_BUY_DISCOUNT_SUFFIX = ' После регистрации';

	protected $badge;

	public function __construct(array $productBadge)
	{
		$this->badge = $productBadge;
	}

	public function getShowPlace(): string
	{
		return $this->badge['UF_SHOW_PLACE'] ?: '';
	}

	public function getCode(): string
	{
		return $this->badge['UF_CODE'] ?:'';
	}

	private function getName(): string
	{
		return $this->badge['UF_NAME'] ?:'';
	}

	private function getBackgroundColor(): string
	{
		return $this->badge['UF_BACKGROUND_COLOR'] ?: '';
	}

	private function getTextColor(): string
	{
		return $this->badge['UF_TEXT_COLOR'] ?: '';
	}

	private function getBorderColor(): string
	{
		return $this->badge['UF_BORDER_COLOR'] ?: '';
	}

	private function getPicture(): ?Image
	{
		return $this->badge['UF_PICTURE']
			? Image::fromIblockElement($this->badge['UF_PICTURE'], $this->getName())
				->resize([48, 32])
			: null;
	}
	public function jsonSerialize()
	{
		return [
			'code' => $this->getCode(),
			'name' => $this->getName(),
			'backgroundColor' => $this->getBackgroundColor(),
			'textColor' => $this->getTextColor(),
			'borderColor' => $this->getBorderColor(),
			'mainTag' => ($this->badge['UF_DISPLAY_LOCATION'] == self::BADGES_ABOVE_PHOTO_CODE),
			'image' => $this->getPicture(),
		];
	}
}