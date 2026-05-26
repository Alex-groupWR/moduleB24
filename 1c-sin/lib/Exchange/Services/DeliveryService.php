<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Services\Base\BaseSmartProcessService;

class DeliveryService extends BaseSmartProcessService
{
    private const TYPE_ENTITY = 1122;

    protected function getEntityTypeId(): int { return self::TYPE_ENTITY; }
    protected function getGuidFieldName(): string { return 'XML_ID'; }
    protected function getFieldMap(): array {
        return [
            'markDelete' => 'UF_CRM_23_1775484847834',
            'carrier' => 'UF_CRM_23_CARRIER'
        ];
    }
    protected function getEnumFields(): array { return []; }
    protected function getBindingMap(): array {
        return [
            'carrier' => EntityType::COMPANY,
        ];
    }
}