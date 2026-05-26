<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Rusgeocom\Rusgeocom\Exchange\Services\Base\BaseSmartProcessService;

class OrganisationService extends BaseSmartProcessService
{
    private const TYPE_ENTITY = 1118;

    protected function getEntityTypeId(): int { return self::TYPE_ENTITY; }
    protected function getGuidFieldName(): string { return 'XML_ID'; }

    protected function getFieldMap(): array
    {
        return [
            'name' => 'TITLE',
            'INN' => 'UF_CRM_22_1775477018752',
            'KPP' => 'UF_CRM_22_1775477023875',
            'typeCompany' => 'UF_CRM_22_1775477067927',
            'markDelete' => 'UF_CRM_22_1775477805776',
        ];
    }

    protected function getEnumFields(): array { return ['typeCompany' => true]; }
    protected function getBindingMap(): array { return []; }
}