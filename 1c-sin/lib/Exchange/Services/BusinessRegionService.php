<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Rusgeocom\Rusgeocom\Exchange\Services\Base\BaseSmartProcessService;

class BusinessRegionService extends BaseSmartProcessService
{
    private const TYPE_ENTITY = 1032;

    protected function getEntityTypeId(): int { return self::TYPE_ENTITY; }
    protected function getGuidFieldName(): string { return 'XML_ID'; }
    protected function getFieldMap(): array { return ['markDelete' => 'UF_CRM_4_1773821856555']; }
    protected function getEnumFields(): array { return []; }
    protected function getBindingMap(): array { return []; }
}