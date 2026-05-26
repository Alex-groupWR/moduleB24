<?php

namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Security\Random;
use Bitrix\Main\UserTable;
use Bitrix\Main\Engine\CurrentUser as BitrixCurrentUser;
use CSite;
use CUser;
use DateTimeImmutable;
use Exception;
use Rusgeocom\Rusgeocom\Api\Exceptions\ApiException;
use Rusgeocom\Rusgeocom\Types\PhoneNumber;
use Rusgeocom\Rusgeocom\Personal\AuthorizeInfoSender;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\User\Entities\AbstractUser;
use Rusgeocom\Rusgeocom\User\Entities\UserName;

class User
{
	public const string AUTHORIZED_FOR_ORDER_CODE = 'not_logged_buyer';
	private const int PASSWORD_MIN_LENGTH = 8;
	private const string DEFAULT_USERNAME = 'Покупатель';

	/**
	 * @param AbstractUser $user
	 * @return string
	 */

	public static function generateLogin(AbstractUser $user): string
	{
		if ($user->getEmail()) {
			$newLogin = (new DateTimeImmutable())->getTimestamp() . '_' . $user->getEmail()->getValue();
		} elseif ($user->getPhone()) {
			$newLogin = (new DateTimeImmutable())->getTimestamp() . '_' . $user->getPhone()->getNumber();
		} else {
			$newLogin = (new DateTimeImmutable())->getTimestamp() . '_' . randString();
		}

		return $newLogin;
	}

	public static function generatePassword(): string
	{
		global $USER;
		$policy = $USER->GetGroupPolicy(static::getDefaultUserGroupIds());
		$passwordMinLength = intval($policy['PASSWORD_LENGTH']);

		if ($passwordMinLength < 0) {
			$passwordMinLength = static::PASSWORD_MIN_LENGTH;
		}

		$passwordChars = Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER | Random::ALPHABET_NUM;
		if ($policy['PASSWORD_PUNCTUATION'] === 'Y') {
			$passwordChars |= Random::ALPHABET_SPECIAL;
		}

		return Random::getStringByAlphabet($passwordMinLength, $passwordChars);
	}

	public static function getDefaultUserGroupIds(): array
	{
		$groupIds = [ALL_USERS_GROUP_ID];
		$defaultGroups = Option::get('main', 'new_user_registration_def_group', '');
		if ($defaultGroups != '') {
			$groupIds = explode(',', $defaultGroups);
		}

		return $groupIds;
	}
	public static function getDefaultCityForAdminPanel()
	{
		if (substr($_SERVER['SCRIPT_URL'], 0, 14) == '/bitrix/admin/') {

			global $USER;
			$cityGroups = User::getOrderCityGroups();
			$userCityGroups = array_intersect(array_keys($cityGroups), $USER->GetUserGroupArray());

			if ($userCityGroups) {
				return $cityGroups[array_pop($userCityGroups)]['DESCRIPTION'] ?: '';
			}
		}

		return '';
	}

	/**
	 * @return array
	 */
	public static function getOrderCityGroups()
	{
		$groups = [];
		$iterator = \Bitrix\Main\GroupTable::getList([
			'filter' => [
				'%=STRING_ID' => 'creating_orders%'
			],
		]);
		while ($group = $iterator->fetch()) {
			$groups[$group['ID']] = $group;
		}

		return $groups;
	}

	public static function getIdByLogin($login)
	{
		return CUser::GetList(
			$by = 'personal_country',
			$order = 'desc',
			['LOGIN' => $login]
		)->Fetch()['ID'];
	}

	public static function getFieldByEmailOrPhone(?PhoneNumber $phone, string $email, string $fieldName): string
	{
		$query = UserTable::query()
			->setSelect([$fieldName])
			->addOrder('ID', 'DESC')
			->where('ACTIVE', 'Y')
			->setLimit(1);

		$phone ? $query->where('PERSONAL_PHONE', $phone->getNumber()) : $query->where('EMAIL', $email);

		return $query
			->exec()
			->fetch()[$fieldName] ?? '';
	}

	public static function getFieldById(int $id, string $fieldName): string
	{
		$query = UserTable::query()
			->setSelect([$fieldName])
			->where('ID', $id)
			->where('ACTIVE', 'Y')
			->setLimit(1);

		return $query
			->exec()
			->fetch()[$fieldName] ?? '';
	}

	/**
	 * @return bool
	 */
	public static function isDirector(): bool
	{
		return CSite::InGroup([DIRECTOR_GROUP_ID]);
	}

	public static function isManager(): bool
	{
		return CSite::InGroup([MANAGER_GROUP_ID]);
	}

	public static function isContentEditor(): bool
	{
		return CSite::InGroup([CONTENT_EDITOR_GROUP_ID]);
	}

	public static function isShopAdmin(): bool
	{
		return CSite::InGroup([SHOP_ADMIN_GROUP_ID]);
	}

	public static function hasAccessToSeoComments(): bool
	{
		return CSite::InGroup([SEO_COMMENTS_GROUP_ID]);
	}

	public static function isDealer(): bool
	{
		return CSite::InGroup([DEALER_GROUP_ID]);
	}

	public static function isRusgeocomManager(): bool
	{
		return CSite::InGroup([MANAGER_RUSGEOCOM_GROUP_ID]);
	}

	public static function isProtectedRusgeocomManager(): bool
	{
		return static::isRusgeocomManager() && static::hasAllowedRemoteAddress();
	}

	private static function hasAllowedRemoteAddress(): bool
	{
		$request = Context::getCurrent()->getRequest();
		$ip = filter_var($request->getRemoteAddress(), FILTER_VALIDATE_IP, [FILTER_FLAG_NO_PRIV_RANGE]) ?: '';
		$forwardedFor = explode(',', $request->getHeader('x-rusgeocom-ip') ?? '');
		$headerIp = filter_var(trim($forwardedFor[0]), FILTER_VALIDATE_IP);

		return Settings::isIpAllowedForRusgeocomManager($ip)
			|| Settings::isIpAllowedForRusgeocomManager($headerIp ?: '');
	}

	public static function isWholesaler(): bool
	{
		return CSite::InGroup([OPT_GROUP_ID]);
	}

	public static function isMetrologyOpt(): bool
	{
		return CSite::InGroup([METROLOGY_OPT_GROUP_ID]);
	}

	public static function inGroup(int $groupId): bool
	{
		return CSite::InGroup([$groupId]);
	}

	/**
	 *
	 * @return bool
	 */
	public static function isDirect(): bool
	{
		return CSite::InGroup([DIRECT_GROUP_ID]);
	}

	public static function getId(): int
	{
		return BitrixCurrentUser::get()->getId() ?? 0;
	}

	public static function getPhone(): ?PhoneNumber
	{
		$user = UserTable::getByPrimary(User::getId())->fetchObject();
		if ($user && $user->getPersonalPhone()) {
			return new PhoneNumber($user->getPersonalPhone());
		}
		return null;
	}

	public static function getEmail(): string
	{
		$user = UserTable::getByPrimary(User::getId())->fetchObject();
		if ($user && $user->getEmail()) {
			return $user->getEmail();
		}
		return '';
	}

	public static function getLogin(): string
	{
		return BitrixCurrentUser::get()->getLogin() ?: '';
	}

	public static function isAuthorized(): bool
	{
		return (bool)BitrixCurrentUser::get()->getId();
	}

	public static function isAdmin(): bool
	{
		return BitrixCurrentUser::get()->isAdmin();
	}

	public static function hasManagerOnlyProductsAccess(): bool
	{
		return User::isAuthorized() && (
				User::isAdmin()
				|| User::isDirector()
				|| User::isRusgeocomManager()
				|| User::hasAccessToSeoComments()
				|| User::isContentEditor()
				|| User::isShopAdmin()
			);
	}

	public static function hasWholesalePricesAccess(): bool
	{
		return User::isAuthorized() && (
				User::isWholesaler()
				|| User::isRusgeocomManager()
			);
	}

	public static function hasDiscountPreviewAccess(): bool
	{
		return User::isAuthorized() && (
				User::isRusgeocomManager()
				|| User::isManager()
			);
	}

	public static function getAvailableName($id = 0)
	{
		if (!$id) {
			if (static::isAuthorized()) {
				$id = static::getId();
			}
		}

		if (!$id) {
			return '';
		}

		$user = CUser::GetByID($id)->Fetch();

		return ($user['NAME'] || $user['LAST_NAME']) ? trim($user['NAME'] . ' ' . $user['LAST_NAME']) : $user['EMAIL'];
	}

	public static function getPersonalName(int $userId = 0): string
	{
		if (!$userId) {
			return '';
		}

		$user = CUser::GetByID($userId)->Fetch();

		return UserName::fromDbUser($user)->getFullVariant() ?: static::DEFAULT_USERNAME;
	}

	public static function getLogoutUrl(): string
	{
		global $APPLICATION;
		return $APPLICATION->GetCurPageParam("logout=yes", [
			"login",
			"logout",
			"register",
			"forgot_password",
			"change_password"
		]);
	}

	/**
	 * Основной - у которого заполнен тип.
	 * Временный - типа нет, создаётся битриксом при оформлении заказа.
	 * Сопоставляются по почте
	 *
	 * @param int $userId
	 * @return bool
	 */
	public static function isMainUser(int $userId): bool
	{
		return (bool)UserTable::query()
			->addSelect('UF_CUSTOMER_TYPE')
			->where('ID', $userId)
			->exec()
			->fetch()['UF_CUSTOMER_TYPE'];
	}

	public static function update(int $id, array $fields): void
	{
		$user = new CUser();
		if (!$user->Update($id, $fields)) {
			LoggerFactory::get(static::class)->error('Ошибка обновления пользователя', [
				'id' => $id,
				'fields' => $fields,
				'error' => $user->LAST_ERROR
			]);
			throw new Exception($user->LAST_ERROR ?: 'Ошибка обновления пользователя');
		}
	}

	public static function loggedForCheckout(): bool
	{
		return Application::getInstance()->getSession()->has(static::AUTHORIZED_FOR_ORDER_CODE);
	}

	/**
	 * Если браузер забыл PHPSESSID, но куки BITRIX_SM_UIDL и BITRIX_SM_UIDH присутствуют, то воссоздаём PHPSESSID
	 */
	public static function tryLoginByCookies(): void
	{
		global $USER;
		if(!User::isAuthorized()) {
			$USER->LoginByCookies();
		}
	}
}
