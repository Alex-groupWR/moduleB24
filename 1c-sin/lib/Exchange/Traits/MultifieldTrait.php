<?php

namespace Rusgeocom\Rusgeocom\Exchange\Traits;

trait MultifieldTrait
{
    protected static function syncMultifields(string $entityType, int $entityId, array $fields): void
    {
        $entityMap = [
            'CONTACT' => \CCrmOwnerType::Contact,
            'COMPANY' => \CCrmOwnerType::Company,
        ];

        $typeId = $entityMap[$entityType] ?? null;
        if (!$typeId) {
            return;
        }

        $multiField = new \CCrmFieldMulti();

        // Удаляем старые
        $dbRes = \CCrmFieldMulti::GetList([], [
            'ENTITY_ID' => $entityType,
            'ELEMENT_ID' => $entityId
        ]);

        while ($row = $dbRes->Fetch()) {
            $multiField->Delete($row['ID']);
        }

        // Добавляем новые
        $typeMap = [
            'phones' => 'PHONE',
            'emails' => 'EMAIL',
        ];

        foreach ($typeMap as $requestKey => $fieldType) {
            if (empty($fields[$requestKey])) {
                continue;
            }

            $values = array_unique(array_filter((array)$fields[$requestKey]));
            foreach ($values as $value) {
                $multiField->Add([
                    'ENTITY_ID' => $entityType,
                    'ELEMENT_ID' => $entityId,
                    'TYPE_ID' => $fieldType,
                    'VALUE_TYPE' => 'WORK',
                    'VALUE' => trim((string)$value),
                ]);
            }
        }
    }
}