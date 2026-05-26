<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Bitrix\Main\DI\ServiceLocator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RequestHandlerFactory
{
    public static function getByAction(string $action): RequestHandlerInterface|bool
    {
        $map = [
            'Ping' => PingRequestHandler::class,
            'Warehouse' => AddOrChangeWarehouseRequestHandler::class,
            'Agreement' => AddOrChangeAgreementRequestHandler::class,
            'BusinessRegion' => AddOrChangeBusinessRegionRequestHandler::class,
            'Delivery' => AddOrChangeDeliveryRequestHandler::class,
            'Product' => AddOrChangeProductRequestHandler::class,
            'Contact' => AddOrChangeContactRequestHandler::class,
            'Deal' => AddOrChangeDealRequestHandler::class,
            'Organisation' => AddOrChangeOrganisationRequestHandler::class,
            'Kontragent' => AddOrChangeCompanyRequestHandler::class,
            'GetUsers' => GetUserRequestHandler::class,
            'SyncUsers' => SyncUserRequestHandler::class,
            'GetAgreement'      => GetAgreementRequestHandler::class,
            'GetBusinessRegion' => GetBusinessRegionRequestHandler::class,
            'GetDelivery'       => GetDeliveryRequestHandler::class,
            'GetOrganisation'   => GetOrganisationRequestHandler::class,
            'GetContact'        => GetContactRequestHandler::class,
            'GetKontragent'     => GetKontragentRequestHandler::class,
            'GetProduct'        => GetProductRequestHandler::class,
            'GetWarehouse'      => GetWarehouseRequestHandler::class,
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
}