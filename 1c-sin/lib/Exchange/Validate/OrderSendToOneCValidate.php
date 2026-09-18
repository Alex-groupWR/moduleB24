<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Utils\Settings;

class OrderSendToOneCValidate
{
    private const UF_ORIGIN_ID = 'ORIGIN_ID';

    private const PARENT_AGREEMENT       = 'UF_CRM_1726999670';
    private const PARENT_AGREEMENT_INDIV = 'UF_CRM_1780489768';
    private const PARENT_ORGANISATION    = 'UF_CRM_1776192243';
    private const PARENT_DELIVERY        = 'UF_CRM_1776192264';
    private const PARENT_WAREHOUSE       = 'UF_CRM_1749582650';
    private const UF_DATA_DOCUMENT_1C = 'UF_CRM_1780488739348';


    private const SEGMENT_FIELD = 'UF_CRM_1774519329551';

    /**
     * @return string[] Список сообщений об ошибках. Пустой массив — валидация пройдена.
     */
    public static function check(int $dealId, int $userId = 0): array
    {
        Loader::includeModule('crm');

        if ($userId > 0 && !Settings::isUserAllowedToSendDealToOneC($userId)) {
            return ['У вас нет прав на ручную отправку сделок в 1С'];
        }

        $deal = self::fetchDeal($dealId);
        if (!$deal) {
            return ['Сделка не найдена'];
        }

        if (!empty($deal[self::UF_ORIGIN_ID])) {
            // Заказ уже связан с документом 1С (пришёл оттуда или уже был отправлен ранее)
            return [
                'Заказ уже синхронизирован с 1С (ORIGIN_ID: ' . $deal[self::UF_ORIGIN_ID] . '). '
                . 'Повторная отправка из Б24 запрещена.',
            ];
        }

        $errors = [];

        $requiredParentFields = [
            self::PARENT_ORGANISATION    => 'Наша организация',
            self::PARENT_DELIVERY        => 'Способ доставки',
            self::PARENT_WAREHOUSE       => 'Склад',
            self::UF_DATA_DOCUMENT_1C    => 'Дата документа 1с',
        ];

        foreach ($requiredParentFields as $field => $label) {

            if (empty($deal[$field]) || $deal[$field] == 'T408_' ) {
                $errors[] = "Не заполнено поле «{$label}»";
            }
        }

        if (empty($deal[self::PARENT_AGREEMENT]) && empty($deal[self::PARENT_AGREEMENT_INDIV])) {
            $errors[] = 'Не заполнено соглашение (типовое или индивидуальное)';
        }

        $companyId = (int)($deal['COMPANY_ID'] ?? 0);
        if ($companyId <= 0) {
            $errors[] = 'К сделке не привязана компания';
        } else {
            $errors = array_merge($errors, self::checkCompany($companyId));
        }

        return $errors;
    }

    private static function checkCompany(int $companyId): array
    {
        $errors = [];

        $company = CompanyTable::getList([
            'select' => ['ID', self::UF_ORIGIN_ID, self::SEGMENT_FIELD],
            'filter' => ['=ID' => $companyId],
            'limit'  => 1,
        ])->fetch();

        if (!$company) {
            return ["Компания {$companyId} не найдена"];
        }

        if (empty($company[self::SEGMENT_FIELD])) {
            $errors[] = '[Компания] Не заполнен сегмент рынка';
        }

        if (empty($company[self::UF_ORIGIN_ID])) {
            $errors[] = '[Компания] Компания ещё не синхронизирована с 1С (нет внешнего идентификатора)';
        }

        $requisite = (new EntityRequisite())->getList([
            'select' => ['ID', 'XML_ID'],
            'filter' => [
                '=ENTITY_TYPE_ID' => CCrmOwnerType::Company,
                '=ENTITY_ID'      => $companyId,
            ],
            'order'  => ['ID' => 'DESC'],
            'limit'  => 1,
        ])->fetch();

        if (!$requisite) {
            $errors[] = '[Компания] У компании нет реквизита — добавьте реквизит в карточке компании';
        } elseif (empty($requisite['XML_ID'])) {
            $errors[] = '[Компания] Реквизит компании ещё не синхронизирован с 1С (нет внешнего идентификатора)';
        }

        return $errors;
    }

    private static function fetchDeal(int $dealId): ?array
    {
        $row = DealTable::getList([
            'select' => [
                'ID',
                'COMPANY_ID',
                self::UF_DATA_DOCUMENT_1C,
                self::UF_ORIGIN_ID,
                self::PARENT_AGREEMENT,
                self::PARENT_AGREEMENT_INDIV,
                self::PARENT_ORGANISATION,
                self::PARENT_DELIVERY,
                self::PARENT_WAREHOUSE,
            ],
            'filter' => ['=ID' => $dealId],
            'limit'  => 1,
        ])->fetch();

        return $row ?: null;
    }
}