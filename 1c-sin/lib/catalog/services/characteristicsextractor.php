<?php

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\UserFieldLangTable;
use Bitrix\Main\UserFieldTable;
use Logema\Utils\DataAccess\HighloadblockHelper;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\Characteristic;
use Rusgeocom\Rusgeocom\Catalog\Filter;
use Rusgeocom\Rusgeocom\Ui\Localization;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;

class CharacteristicsExtractor
{
    /**
     * @var int
     */
    private int $productId;

    /**
     * @param int $productId
     */
    public function __construct(int $productId)
    {
        $this->productId = $productId;
    }

    /**
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     *
     * @return Characteristic[]
     */
    public function extract(): array
    {
        $resultCharacteristics = [];

        $productCompareGroupProperty = $this->getProductCompareGroupProperty();

        if (!$productCompareGroupProperty) {
            return [];
        }

        $compareGroupHlBlock = HlBlockHelperRegistry::getInstance()
            ->getByCode('CompareGroups')
            ->getElementByFilter(['UF_XML_ID' => $productCompareGroupProperty]);

        $characteristicsHlBlock = $this->getCharacteristicsHlBlock($compareGroupHlBlock['UF_HLBLOCK_NAME']);

        $characteristicValues = $characteristicsHlBlock
            ->getElementByFilter(['UF_XML_ID' => $this->productId]);

        if (!$characteristicValues) {
            return [];
        }

        $characteristicsMap = $this->getCharacteristicsMap(
            $characteristicsHlBlock->getEntityClass()::getHighloadBlock()['ID']
        );

        $id = $compareGroupHlBlock['ID'];
        $hint = Filter::getHintsForCompareGroups([$id])[$id];

        foreach ($characteristicsMap as $ufKey => $characteristicTitle) {
            $resultCharacteristics[] = new Characteristic(
                $characteristicTitle,
                $characteristicValues[$ufKey] ?: '',
                $hint[$ufKey] ?? null
            );
        }

        return $resultCharacteristics;
    }

    /**
     * Группа сравнения из свойства товара
     *
     * @return string
     */
    protected function getProductCompareGroupProperty(): string
    {
        $product = IblockHelper::forIblock(CATALOG_IBLOCK_ID)
            ->getElementById($this->productId, ['PROPERTY_COMPARE_GROUP']);

        return $product['PROPERTIES']['COMPARE_GROUP']['VALUE'] ?: '';
    }

    /**
     * @param string $hlBlockName
     *
     * @return HighloadblockHelper
     */
    protected function getCharacteristicsHlBlock(string $hlBlockName): HighloadblockHelper
    {
        return HlBlockHelperRegistry::getInstance()->getByCode($hlBlockName);
    }

    /**
     * @param int $hlBlockId
     *
     * @throws ArgumentException
     * @throws ObjectPropertyException
     * @throws SystemException
     * @return array
     */
    protected function getCharacteristicsMap(int $hlBlockId): array
    {
        $characteristicsMap = [];

        $dbCharacteristics = UserFieldTable::query()
            ->setSelect([
                'FIELD_NAME',
                'NAME' => 'LANG_FIELD.LIST_COLUMN_LABEL'
            ])
            ->setFilter([
                'ENTITY_ID' => 'HLBLOCK_' . $hlBlockId,
                'LANG_FIELD.LANGUAGE_ID' => Localization::getLangId()
            ])
            ->registerRuntimeField('LANG_FIELD', new ReferenceField(
                    'LANG_FIELD',
                    UserFieldLangTable::class,
                    ["=this.ID" => "ref.USER_FIELD_ID"],
                )
            )
            ->exec();

        while ($characteristics = $dbCharacteristics->fetch()) {
            $characteristicsMap[$characteristics['FIELD_NAME']] = $characteristics['NAME'];
        }

        return $characteristicsMap;
    }
}