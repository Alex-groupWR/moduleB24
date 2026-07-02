<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Dto;

/**
 * Хранит все резолвнутые ID связанных сущностей для одного запроса sync().
 * Заменяет передачу сырого массива $resolved между методами.
 */
final class ResolvedRelationsDto
{
    public function __construct(
        public readonly ?int          $companyId,
        public readonly int           $managerId,
        public readonly ?int          $contactId,
        public readonly int           $warehouseId,
        public readonly int|null|false $warehouseSPId,
        public readonly int|null|false $organisationId,
        public readonly int|null|false $businessRegionId,
        public readonly int|null|false $methodDeliveryId,
        public readonly int|null|false $agreementId,
        public readonly int|null|false $typeAgreementId,
        public readonly int|null|false $indivAgreementId,
    ) {}
}