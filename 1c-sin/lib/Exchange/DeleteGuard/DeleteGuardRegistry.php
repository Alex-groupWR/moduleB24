<?php
// lib/Exchange/DeleteGuard/DeleteGuardRegistry.php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\DeleteGuard;

use CCrmOwnerType;

/**
 * Конфиг: какие CRM-сущности синхронизированы с 1С и как их "мягко" удалять.
 * guidField        — поле, по которому проверяем привязку к 1С
 * markDeleteField  — UF-поле, которое проставляем вместо физического удаления
 * label            — человекочитаемое имя для уведомления
 */
class DeleteGuardRegistry
{
    public static function getConfig(int $entityTypeId): ?array
    {
        return self::getMap()[$entityTypeId] ?? null;
    }

    private static function getMap(): array
    {
        return [
            CCrmOwnerType::Company => [
                'guidField' => 'ORIGIN_ID',
                'markDeleteField' => 'UF_CRM_1786381343342',
                'label' => 'Компания',
            ],
            CCrmOwnerType::Deal => [
                'guidField' => 'ORIGIN_ID',
                'markDeleteField' => 'UF_CRM_1786384001480',
                'label' => 'Сделка',
            ],
            // Смарт-процессы (XML_ID — стандартное поле GUID во всех Base*Service)
            1032 => [ // BusinessRegionService
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_4_1786380168449',
                'label' => 'Бизнес-регион',
            ],
            1064 => [ // AgreementService::TYPE_TYPICAL
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_12_1786380093987',
                'label' => 'Типовое соглашение',
            ],
            1068 => [ // AgreementService::TYPE_INDIVIDUAL
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_13_1786380008123',
                'label' => 'Индивидуальное соглашение',
            ],
            1130 => [ // OrganisationService
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_25_1786383053470',
                'label' => 'Наша организация',
            ],
            1126 => [ // DeliveryService
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_24_1775484847834',
                'label' => 'Способ доставки',
            ],
            1096 => [ // WarehouseServiceSP
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_17_1786383582750',
                'label' => 'Склад',
            ],
            1036 => [ // WarehouseServiceSP
                'guidField' => 'XML_ID',
                'markDeleteField' => 'UF_CRM_5_1786116177640',
                'label' => 'Склад',
            ],
        ];
    }
}