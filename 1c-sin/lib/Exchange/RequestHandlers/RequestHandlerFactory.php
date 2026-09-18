<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Bitrix\Main\DI\ServiceLocator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Local\Backoffice\ActivityService;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Bitrix\Main\Loader;

class RequestHandlerFactory
{

    private static array $url1cLogMap;

    private static function getUrl1cLogMap(): array
    {
        if (!Loader::includeModule('crm')) {
            // Если CRM не установлен, используем фолбэк или выбрасываем исключение
            throw new \RuntimeException('CRM module is not installed or cannot be loaded');
        }
        return self::$url1cLogMap ??= [
            'Deal'           => ['Заказ в 1С',            \CCrmOwnerType::Deal],
            'Order'          => ['Заказ в 1С',            \CCrmOwnerType::Deal],
            'Contact'        => ['Контакт из 1С',         \CCrmOwnerType::Contact],
            'Kontragent'     => ['Контрагент из 1С',      \CCrmOwnerType::Company],
            'BusinessRegion' => ['Бизнес-регион из 1С',   EntityType::SMART_PROCESS_BUSINESS_REGION->getFactoryId()],
            'Delivery'       => ['Способ доставки из 1С', EntityType::SMART_PROCESS_METHOD_DELIVERY->getFactoryId()],
            'Organisation'   => ['Организация из 1С',     EntityType::SMART_PROCESS_ORGANISATION->getFactoryId()],
        ];
    }


    public static function getByAction(string $action): RequestHandlerInterface|bool
    {
        $map = [
            'Ping' => PingRequestHandler::class,
            'Warehouse'         => AddOrChangeWarehouseRequestHandler::class,
            'Agreement'         => AddOrChangeAgreementRequestHandler::class,
            'BusinessRegion'    => AddOrChangeBusinessRegionRequestHandler::class,
            'Delivery'          => AddOrChangeDeliveryRequestHandler::class,
            'Product'           => AddOrChangeProductRequestHandler::class,
            'Contact'           => AddOrChangeContactRequestHandler::class,
            'Deal'              => AddOrChangeDealRequestHandler::class,
            'StatusOrder'       => UpdateDealStageRequestHandler::class,
            'Organisation'      => AddOrChangeOrganisationRequestHandler::class,
            'Kontragent'        => AddOrChangeCompanyRequestHandler::class,
            'Company'           => UpdateCompanyRequestHandler::class,
            'GetUsers'          => GetUserRequestHandler::class,
            'SyncUsers'         => SyncUserRequestHandler::class,
            'GetAgreement'      => GetAgreementRequestHandler::class,
            'GetBusinessRegion' => GetBusinessRegionRequestHandler::class,
            'GetDelivery'       => GetDeliveryRequestHandler::class,
            'GetOrganisation'   => GetOrganisationRequestHandler::class,
            'GetContact'        => GetContactRequestHandler::class,
            'GetKontragent'     => GetKontragentRequestHandler::class,
            'GetProduct'        => GetProductRequestHandler::class,
            'GetWarehouse'      => GetWarehouseRequestHandler::class,
            'Order'             => AddOrChangeOrderRequestHandler::class,
            'AttachFile'        => AttachFileActivityRequestHandler::class,
            'Stock'             => AddOrChangeStockRequestHandler::class,
        ];

        $class = Arr::first(
            $map,
            fn(string $className, string $methodName) => Str::lower($action) === Str::lower($methodName)
        );
        if (!$class) {
            return false;
        }

        return ServiceLocator::getInstance()->get($class);
    }

    public static function logActivityIfNeeded(string $action, array $request, array $result): void
    {
        $logMap = self::getUrl1cLogMap();

        $key = Arr::first(
            array_keys($logMap),
            fn(string $mapKey) => Str::lower($mapKey) === Str::lower($action)
        );

        if ($key === null) {
            return;
        }

        $url1c = (string)($request['URL1c'] ?? $request['URL1C'] ?? '');
        $entityId = Str::lower($key) === 'kontragent'
            ? (int)($result['company']['b24_id'] ?? 0)
            : (int)($result['b24_id'] ?? 0);

        if ($url1c === '' || $entityId <= 0) {
            return;
        }

        [$subject, $ownerTypeId] = $logMap[$key];

        try {
            ActivityService::create1CLog($entityId, $request['titleActivity']??$subject, $url1c, $ownerTypeId);
        } catch (Throwable $exc) {
            LoggerFactory::get(static::class)->exception($exc, 'Не удалось создать/обновить дело со ссылкой на 1С', [
                'action' => $action,
                'entityId' => $entityId,
                'url1c' => $url1c,
            ]);
        }
    }
}