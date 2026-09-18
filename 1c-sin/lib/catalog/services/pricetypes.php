<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Rusgeocom\Rusgeocom\Catalog\Entities\PriceType;
use Rusgeocom\Rusgeocom\Personal\Services\MarketSegmentRegistry;
use Rusgeocom\Rusgeocom\Personal\Services\PersonalService;
use Rusgeocom\Rusgeocom\Personal\Tables\PriceTypeTable;
use Rusgeocom\Rusgeocom\Personal\Tables\SegmentTypeTable;
use Rusgeocom\Rusgeocom\User\UserService;
use Rusgeocom\Rusgeocom\Utils\User;

class PriceTypes
{
	public const string USER_PRICE_CODE = 'USER';
	public const string COMPANY_PRICE_CODE = 'COMPANY';
	/** @deprecated Использовать базовую цену */
	public const string RRC_PRICE_CODE = 'RRC';
	public const string WHOLESALE_PRICE_CODE_PREFIX = 'WHOLESALE';
	public const int DEFAULT_WHOLESALE_LEVEL = 1;
	public const string OLD_PRICE_KEY = 'OLD_PRICE';
	public const string CURRENT_PRICE_KEY = 'CURRENT_PRICE';

	private static array $userPriceTypeIds = [];

	public static function createMissedPriceTypes(array $priceTypes): void
	{
		$existedPriceCodes = array_column(
			PriceTypeTable::query()
				->addSelect('CODE')
				->whereIn('CODE', array_keys($priceTypes))
				->exec()
				->fetchAll(),
			'CODE'
		);

		$remainingPriceTypes = Arr::except($priceTypes, $existedPriceCodes);

		if (!$remainingPriceTypes) {
			return;
		}

		foreach ($remainingPriceTypes as $priceCode => $priceName) {
			PriceTypeTable::add(
				[
					'CODE' => $priceCode,
					'NAME' => $priceName,
				]
			);
		}
	}

	public static function updateSegmentTypes(array $segmentTypes): void
	{
		$iterator = SegmentTypeTable::query()
			->addSelect('ID')
			->addSelect('NAME')
			->addSelect('CODE')
			->whereIn('CODE', array_keys($segmentTypes))
			->exec();

		$existedSegments = [];
		while ($segment = $iterator->fetch()) {
			$existedSegments[$segment['CODE']] = [
				'id' => $segment['ID'],
				'name' => $segment['NAME'],
			];
		}

		$newSegments = Arr::except($segmentTypes, array_keys($existedSegments));
		if ($newSegments) {
			foreach ($newSegments as $segmentCode => $segmentName) {
				SegmentTypeTable::add(
					[
						'CODE' => $segmentCode,
						'NAME' => $segmentName,
					]
				);
			}
		}

		$forUpdate = array_intersect_key($existedSegments, $segmentTypes);
		foreach ($forUpdate as $segmentCode => $segment) {
			$name = $segmentTypes[$segmentCode] ?? '';
			if ($name && $segment['name'] !== $name) {
				SegmentTypeTable::update(
					$segment['id'],
					[
						'NAME' => $name,
					]
				);
			}
		}
	}

	public static function getById(int $id): ?PriceType
	{
		return PriceTypeRegistry::getById($id);
	}

	public static function getByName(string $name): ?PriceType
	{
		return PriceTypeRegistry::getByName($name);
	}

	public static function getIdByName(string $name): int
	{
		$priceType = static::getByName($name);
		return $priceType ? $priceType->getId() : BASE_PRICE_ID;
	}

	/**
	 * Определяем тип цены для указанного пользователя
	 *
	 * @param int  $userId ID Пользователя для расчета
	 * @param bool $skipCheck Отлючает проверки и просто использует вычисленный тип цены по умолчанию
	 * @param int  $defaultTypeId ID типа цены по умолчанию. Если не указан - базовая цена
	 * @param bool $asCompany Для скидки авторизованного переключаем на скидку юр. лица вместо персональной
	 * @return PriceType|null
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getPriceTypeForUser(
		int $userId,
		bool $skipCheck = false,
		int $defaultTypeId = 0,
		bool $asCompany = false
	): ?PriceType {
		$wholesalerSegmentCodes = MarketSegmentRegistry::getInstance()->getCodesForWholesaler();
		if (!static::$userPriceTypeIds[$userId]) {
			$forCurrentUser = $userId === User::getId();
			$priceTypeId = $defaultTypeId ?: BASE_PRICE_ID;
			if (!$skipCheck && (!$forCurrentUser || User::isAuthorized())) {
				$hasWholesaleGroup = UserService::hasGroup($userId, OPT_GROUP_ID);
				$hasManagerGroup = UserService::hasGroup($userId, MANAGER_RUSGEOCOM_GROUP_ID);
				$activeCompany = PersonalService::getUserCompanyByProfileId(
					$forCurrentUser
						? PersonalService::getCurrentUserProfileId()
						: PersonalService::getIndividualCompanyId($userId)
				);
				$companyPriceCode = $activeCompany ? $activeCompany->getSegmentCode() : '';
				$wholesalePriceAvailable = $hasWholesaleGroup && $wholesalerSegmentCodes->contains($companyPriceCode);
				if (!$wholesalePriceAvailable && !$hasManagerGroup) {
					$isIndividual = !$activeCompany || !$activeCompany->isLegal();

					$priceTypeId = static::getIdByName(
						($isIndividual && !$asCompany) ? static::USER_PRICE_CODE : static::COMPANY_PRICE_CODE
					);
				} elseif ($wholesalePriceAvailable && !$hasManagerGroup) {
					$priceTypeId = static::getIdByName(
						static::getWholesalePriceNameForLevel(static::DEFAULT_WHOLESALE_LEVEL)
					);
				}
			}

			static::$userPriceTypeIds[$userId] = $priceTypeId;
		}

		return PriceTypeRegistry::getById(static::$userPriceTypeIds[$userId]);
	}

	public static function getPriceNamesForFilter(): array
	{
		$baseType = static::getBasePriceType();
		$types = [$baseType->getName()];
		$priceType = static::getPriceTypeForUser(User::getId(), User::loggedForCheckout());
		if ($priceType && !$priceType->isEqual($baseType)) {
			$types[] = $priceType->getName();
		}

		return $types;
	}

	/**
	 * @return PriceType[]
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getActiveTypes(): array
	{
		$activeTypes = [];
		foreach (static::getActivePriceTypeNames() as $priceTypeName) {
			if ($priceType = PriceTypeRegistry::getByName($priceTypeName)) {
				$activeTypes[] = $priceType;
			}
		}

		return $activeTypes;
	}

	public static function getPriceFields(): array
	{
		$priceFields = [];

		foreach (static::getActiveTypes() as $priceType) {
			$priceFields[] = $priceType->getPriceProperty();
			$priceFields[] = $priceType->getCurrencyProperty();
		}

		return $priceFields;
	}

	public static function getBasePriceType(): ?PriceType
	{
		return PriceTypeRegistry::getById(BASE_PRICE_ID);
	}

	public static function hasDiscountForAuthorized(PriceType $priceType): bool
	{
		return in_array($priceType->getName(), static::getAuthorizedDiscountPriceTypes());
	}

	private static function getAuthorizedDiscountPriceTypes(): array
	{
		return [
			static::USER_PRICE_CODE,
			static::COMPANY_PRICE_CODE,
		];
	}

	private static function getActivePriceTypeNames(): array
	{
		return array_merge(
			[BASE_PRICE_CODE],
			static::getAuthorizedDiscountPriceTypes(),
			[static::getWholesalePriceNameForLevel(1)],
		);
	}

	/**
	 * @return PriceType[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getReplaceableTypes(): array
	{
		return array_filter(
			PriceTypeRegistry::getAll(),
			fn(PriceType $priceType) => !$priceType->isBasePrice()
		);
	}

	public static function getIdForAuthorizedDiscountPriceTypes(): array
	{
		return array_map(
			fn(string $name) => static::getIdByName($name),
			static::getAuthorizedDiscountPriceTypes()
		);
	}

	public static function getWholesalePriceNameForLevel(int $level): string
	{
		return static::WHOLESALE_PRICE_CODE_PREFIX . $level;
	}

	public static function hasWholesalePricesAccess(): bool
	{
		if (!User::isAuthorized()) {
			return false;
		}

		return User::isRusgeocomManager()
			|| static::isWholesalePriceTypeForCurrentUser();
	}

	public static function isWholesalePriceTypeForCurrentUser(): bool
	{
		if (!User::isAuthorized()) {
			return false;
		}

		$userPriceType = static::getPriceTypeForUser(User::getId());
		return Str::contains($userPriceType->getName(), static::WHOLESALE_PRICE_CODE_PREFIX);
	}

	/**
	 * @return array<string,PriceType>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getPriceListsForExport(): array
	{
		return [
			'РРЦ' => PriceTypeRegistry::getByName(BASE_PRICE_CODE),
			'ОПТ1' => PriceTypeRegistry::getByName(static::getWholesalePriceNameForLevel(1)),
			'ОПТ2' => PriceTypeRegistry::getByName(static::getWholesalePriceNameForLevel(2)),
		];
	}

	/**
	 * @return array<string,PriceType[]>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getPriceListsForImport(): array
	{
		return [
			'РРЦ' => [
				PriceTypeRegistry::getByName(static::RRC_PRICE_CODE),
				PriceTypeRegistry::getByName(BASE_PRICE_CODE),
			],
			// 1С уже не должна присылать оптовые цены, только РРЦ
			'ОПТ1' => [PriceTypeRegistry::getByName(static::getWholesalePriceNameForLevel(1))],
			'ОПТ2' => [PriceTypeRegistry::getByName(static::getWholesalePriceNameForLevel(2))],
		];
	}
}