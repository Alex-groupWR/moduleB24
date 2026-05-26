<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\Loader;
use Bitrix\Iblock\SectionTable;
use Bitrix\Iblock\ElementTable;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class ProductService
{
    use ExchangeHelperTrait;

    private const IBLOCK_ID = 14;
    private const GUID_FIELD = 'XML_ID';

    private const PRODUCT_PROPS_MAP = [
        "article" => 123,
        "codeSite" => 124,
        "codeUT" => 1000,
        "brand" => 1003,
        "typeProduct" => 1001,
        "vidProduct" => 1002,
        "markDelete" => 1004,
    ];

    public static function processItems(array $item): array
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        $isGroup = (bool)($item['isGroup'] ?? false);
        $guid = (string)$item['guid'];

        $parentId = self::resolveSectionId($item['section']);
        if ($parentId === null && $item['section']['guid'] !== '00000000-0000-0000-0000-000000000000') {
            return self::errorResult('SECTION_NOT_FOUND', "Родительский раздел не найден", $guid);
        }

        return $isGroup
            ? self::saveSection($item, $parentId)
            : self::saveProduct($item, $parentId);
    }

    private static function saveSection(array $request, ?int $parentId): array
    {
        $bs = new \CIBlockSection;
        $guid = $request['guid'];
        $fields = [
            "IBLOCK_ID" => self::IBLOCK_ID,
            "IBLOCK_SECTION_ID" => $parentId,
            "NAME" => $request['name'],
            self::GUID_FIELD => $guid,
        ];

        $id = self::getExistId($guid, true);

        if ($id)
        {
            $launch = $bs->Update($id, $fields);
           if ($launch) return self::successResult($id,$guid,'updated');
        }else{
            $id = $bs->Add($fields);
            if ($id) return self::successResult($id,$guid,'created');
        }

        return  self::errorResult('SECTION_SAVE_ERROR', $bs->LAST_ERROR, $guid);
    }

    private static function saveProduct(array $request, ?int $sectionId): array
    {
        $el = new \CIBlockElement;
        $guid = $request['guid'];

        $existingId = self::getExistId($guid, false);
        $existingElement = null;

        if ($existingId) {
            $existingElement = ElementTable::getRow([
                'select' => ['ID', 'IBLOCK_SECTION_ID'],
                'filter' => ['ID' => $existingId],
            ]);
        }

        $needToMove = false;
        if ($existingElement ) {
            $currentSectionId = (int)$existingElement['IBLOCK_SECTION_ID'];
            $needToMove = $currentSectionId !== $sectionId;
        }

        $fields = [
            "IBLOCK_ID" => self::IBLOCK_ID,
            "NAME" => $request['name'],
            "DETAIL_TEXT" => $request['description'] ?? '',
            self::GUID_FIELD => $guid,
            "IBLOCK_SECTION_ID"=>$sectionId
        ];

        if ($existingId) {
            $updateResult = $el->Update($existingId, $fields);

            if (!$updateResult) {
                return self::errorResult('PRODUCT_UPDATE_ERROR', $el->LAST_ERROR, $guid);
            }

            if ($needToMove ) {
                \CIBlockElement::SetElementSection($existingId, [$sectionId]);
            }

            self::updateProperties($existingId, $request);

            return self::successResult($existingId,$guid,'updated');
        }

        $fields['PROPERTY_VALUES'] = self::mapProperties($request);

        if (!isset($fields["IBLOCK_SECTION_ID"])) {
            $fields["IBLOCK_SECTION_ID"] = false;
        }

        $newId = $el->Add($fields);

        if ($newId) {
            \CCatalogProduct::Add(['ID' => $newId, 'TYPE' => \CCatalogProduct::TYPE_PRODUCT]);
            return self::successResult($newId,$guid,'created');
        }

        return self::errorResult('PRODUCT_SAVE_ERROR', $el->LAST_ERROR, $guid);
    }

    private static function mapProperties(array $request): array
    {
        $props = [];
        foreach (self::PRODUCT_PROPS_MAP as $jsonKey => $propCode) {
            if (isset($request[$jsonKey])) {
                $props[$propCode] = $request[$jsonKey];
            }
        }
        return $props;
    }

    private static function updateProperties(int $id, array $request): void
    {
        $props = self::mapProperties($request);

        if (!empty($props)) {
            \CIBlockElement::SetPropertyValuesEx($id, self::IBLOCK_ID, $props);
        }
    }

    private static function resolveSectionId(?array $section): ?int
    {
        if (!empty($section['b24_id'])) {
            $sectionId = (int)$section['b24_id'];
            $exists = SectionTable::getRow([
                'select' => ['ID'],
                'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, 'ID' => $sectionId],
            ]);
            if ($exists) {
                return $sectionId;
            }
        }

        if (!empty($section['guid'])) {
            $id = self::getExistId($section['guid'], true);
            if ($id) {
                return $id;
            }
        }

        return null;
    }

    private static function getExistId(string $guid, bool $isSection): int|false
    {
        if (!$guid) return false;

        $table = $isSection ? SectionTable::class : ElementTable::class;
        $row = $table::getRow([
            'select' => ['ID'],
            'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, '=' . self::GUID_FIELD => $guid],
        ]);

        return $row ? (int)$row['ID'] : false;
    }

    public static function getProduct(array $request): array
    {
        Loader::includeModule('iblock');

        $guid  = (string)($request['guid'] ?? '');
        $b24Id = (int)($request['b24_id'] ?? 0);

        if ($b24Id > 0) {
            $row = ElementTable::getRow([
                'select' => ['ID', 'NAME', 'XML_ID', 'IBLOCK_SECTION_ID', 'DETAIL_TEXT'],
                'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, 'ID' => $b24Id],
            ]);
        } elseif ($guid !== '') {
            $row = ElementTable::getRow([
                'select' => ['ID', 'NAME', 'XML_ID', 'IBLOCK_SECTION_ID', 'DETAIL_TEXT'],
                'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, '=' . self::GUID_FIELD => $guid],
            ]);
        } else {
            return self::errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
        }

        if (!$row) {
            return self::errorResult('NOT FOUND', 'Товар не найден', $guid);
        }

        $elementId = (int)$row['ID'];

        $reversePropMap = array_flip(self::PRODUCT_PROPS_MAP);
        $props = [];
        $propRes = \CIBlockElement::GetProperty(self::IBLOCK_ID, $elementId, [], []);
        while ($prop = $propRes->Fetch()) {
            $reqKey = $reversePropMap[$prop['ID']] ?? null;
            if ($reqKey !== null) {
                if ($prop['ID']==1004){
                    $props[$reqKey] = (bool)$prop['VALUE'];
                    continue;
                }
                $props[$reqKey] = $prop['VALUE'];
            }
        }

        // Секция
        $sectionId = (int)$row['IBLOCK_SECTION_ID'];
        $sectionGuid = null;
        if ($sectionId > 0) {
            $sectionRow = SectionTable::getRow([
                'select' => ['XML_ID'],
                'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, 'ID' => $sectionId],
            ]);
            $sectionGuid = $sectionRow['XML_ID'] ?? null;
        }

        $data = array_merge([
            'guid'        => $row['XML_ID'],
            'b24_id'      => $elementId,
            'name'        => $row['NAME'],
            'description' => $row['DETAIL_TEXT'],
            'isGroup'     => false,
            'section'     => ['b24_id' => $sectionId ?: null, 'guid' => $sectionGuid],
        ], $props);

        return [
            'status' => 'success',
            'b24_id' => $b24Id,
            'guid'   => $guid,
            'data'   => $data,
        ];
    }
}