<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Services\Base\BaseSmartProcessService;

class WarehouseServiceSP extends BaseSmartProcessService
{
    private const TYPE_ENTITY = 1096;

    protected function getEntityTypeId(): int { return self::TYPE_ENTITY; }
    protected function getGuidFieldName(): string { return 'XML_ID'; }
    protected function getFieldMap(): array {
        return [
            'markDelete' => 'UF_CRM_17_1781521742976',
        ];
    }
    protected function getEnumFields(): array { return []; }
    protected function getBindingMap(): array { return []; }
}