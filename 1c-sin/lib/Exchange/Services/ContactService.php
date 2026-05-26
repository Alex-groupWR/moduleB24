<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Item;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class ContactService
{
    use ExchangeHelperTrait;

    private const ENTITY_TYPE_ID = \CCrmOwnerType::Contact;
    private const GUID_FIELD = 'ORIGIN_ID';
    private const DEFAULT_ASSIGNED_ID = 1;

    private const FIELD_MAP = [
        'fio'        => Item::FIELD_NAME_NAME,
        'manage_id'  => Item::FIELD_NAME_ASSIGNED,
        'company_id' => Item::FIELD_NAME_COMPANY_ID,
        'guid'       => self::GUID_FIELD,
        'markDelete' => 'UF_CRM_1774278404725',
    ];

    public static function addContact(array $request): array
    {
        try {
            $factory = self::getFactory();
            $item = $factory->createItem();

            self::fillItem($item, $request);

            $item->setCreatedBy(self::DEFAULT_ASSIGNED_ID);
            $item->setUpdatedBy(self::DEFAULT_ASSIGNED_ID);
            $result = $item->save(false);

            if (!$result->isSuccess()) {
                return self::errorResult('CREATE ERROR', implode('; ', $result->getErrorMessages()), $request['guid']);
            }

            $contactId = $item->getId();
            self::updateMultifields($contactId, $request);

            return self::successResult($contactId, $request['guid'], 'created');
        } catch (\Exception $e) {
            return self::errorResult('EXCEPTION', $e->getMessage(), $request['guid']);
        }
    }

    public static function updateContact(int $id, array $request): array
    {
        try {
            $factory = self::getFactory();
            $item = $factory->getItem($id);

            if (!$item) {
                return self::errorResult('UPDATE ERROR', "Contact {$id} not found", $request['guid']);
            }

            self::fillItem($item, $request);

            $result = $item->save();


            if (!$result->isSuccess()) {
                return self::errorResult('UPDATE ERROR', implode('; ', $result->getErrorMessages()), $request['guid']);
            }

            self::updateMultifields($id, $request);


            return self::successResult($id, $request['guid'], 'updated');
        } catch (\Exception $e) {
            return self::errorResult('EXCEPTION', $e->getMessage(), $request['guid']);
        }
    }

    public static function getExistId(string $guid): int|false
    {
        $items = self::getFactory()->getItems([
            'select' => [Item::FIELD_NAME_ID],
            'filter' => ['=' . self::GUID_FIELD => $guid],
            'limit'  => 1,
        ]);

        return !empty($items) ? (int)reset($items)->getId() : false;
    }

    private static function fillItem(Item $item, array $request): void
    {
        foreach (self::FIELD_MAP as $source => $target) {
            if (isset($request[$source])) {
                $value = self::resolveFieldValue($source, $request[$source]);
                if ($value !== null) {
                    $item->set($target, $value);
                }
            }
        }
    }

    private static function updateMultifields(int $contactId, array $request): void
    {
        $multiField = new \CCrmFieldMulti();

        $dbRes = \CCrmFieldMulti::GetList(
            ['ID' => 'ASC'],
            [
                'ENTITY_ID'  => 'CONTACT',
                'ELEMENT_ID' => $contactId
            ]
        );

        while ($row = $dbRes->Fetch()) {
            $multiField->Delete($row['ID']);
        }

        if (!empty($request['phones'])) {
            foreach (array_unique(array_filter((array)$request['phones'])) as $phone) {
                $multiField->Add([
                    'ENTITY_ID'  => \CCrmOwnerType::ContactName,
                    'ELEMENT_ID' => $contactId,
                    'TYPE_ID'    => 'PHONE',
                    'VALUE_TYPE' => 'WORK',
                    'VALUE'      => trim((string)$phone),
                ]);
            }
        }

        if (!empty($request['emails'])) {
            foreach (array_unique(array_filter((array)$request['emails'])) as $email) {
                $multiField->Add([
                    'ENTITY_ID'  => \CCrmOwnerType::ContactName,
                    'ELEMENT_ID' => $contactId,
                    'TYPE_ID'    => 'EMAIL',
                    'VALUE_TYPE' => 'WORK',
                    'VALUE'      => trim((string)$email),
                ]);
            }
        }
    }

    private static function resolveFieldValue(string $fieldName, mixed $value): mixed
    {
        return match ($fieldName) {
            'manage_id' => !empty($value['b24_id'])
                ? (int)$value['b24_id']
                : (SearchEntityService::searchUser($value['guid']) ?? self::DEFAULT_ASSIGNED_ID),
            'company_id' => !empty($value['b24_id'])
                ? (int)$value['b24_id']
                : (SearchEntityService::searchCompany($value['guid']) ?? 0),
            default => $value,
        };
    }

    private static function getFactory(): Factory
    {
        Loader::includeModule('crm');
        $factory = Container::getInstance()->getFactory(self::ENTITY_TYPE_ID);
        if (!$factory) {
            throw new SystemException("Factory not found");
        }
        return $factory;
    }

    public static function getContact(array $request): array
    {
        try {
            $factory = self::getFactory();

            if (!empty($request['b24_id'])) {
                $item = $factory->getItem((int)$request['b24_id']);
            } elseif (!empty($request['guid'])) {
                $items = $factory->getItems([
                    'filter' => ['=' . self::GUID_FIELD => $request['guid']],
                    'limit'  => 1,
                ]);
                $item = reset($items) ?: null;
            } else {
                return self::errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
            }

            if (!$item) {
                return self::errorResult('NOT FOUND', 'Контакт не найден', (string)($request['guid'] ?? ''));
            }

            $contactId = $item->getId();
            $data = [];

            $reverseMap = array_flip(self::FIELD_MAP);
            foreach ($reverseMap as $b24Key => $reqKey) {
                $data[$reqKey] = $item->get($b24Key);
            }

            $data['manage_id']  = ['b24_id' => $item->get(Item::FIELD_NAME_ASSIGNED), 'guid' => null];
            $data['company_id'] = ['b24_id' => $item->get(Item::FIELD_NAME_COMPANY_ID), 'guid' => null];

            // Телефоны и имейлы из мультиполей
            $phones = [];
            $emails = [];
            $dbRes  = \CCrmFieldMulti::GetList(['ID' => 'ASC'], ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId]);
            while ($row = $dbRes->Fetch()) {
                if ($row['TYPE_ID'] === 'PHONE') {
                    $phones[] = $row['VALUE'];
                } elseif ($row['TYPE_ID'] === 'EMAIL') {
                    $emails[] = $row['VALUE'];
                }
            }

            $data['phones'] = $phones;
            $data['emails'] = $emails;
            $data['guid']   = $item->get(self::GUID_FIELD);
            $data['b24_id'] = $contactId;

            return [
                'status' => 'success',
                'b24_id' => $request['b24_id'],
                'guid'   => $request['guid'],
                'data'   => $data,
            ];
        } catch (\Exception $e) {
            return self::errorResult('EXCEPTION', $e->getMessage(), (string)($request['guid'] ?? ''));
        }
    }
}
