<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services\Base;

use Bitrix\Crm\Service\Operation;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;
use Rusgeocom\Rusgeocom\Exchange\Services\SearchEntityService;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;

abstract class BaseSmartProcessService
{
    use ExchangeHelperTrait;

    private static array $instances = [];

    public static function getInstance(): static
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }
        return self::$instances[$class];
    }

    protected function __construct() {}

    abstract protected function getEntityTypeId(): int;
    abstract protected function getGuidFieldName(): string;
    abstract protected function getFieldMap(): array;

    protected function getBindingMap(): array
    {
        return [];
    }

    protected function getEnumFields(): array
    {
        return [];
    }

    protected function validateRequest(array $request): ?array
    {
        return null;
    }

    protected function prepareCustomFields(array $request, array $fields): array
    {
        return $fields;
    }

    public function add(array $request): array
    {
        $validationError = $this->validateRequest($request);
        if ($validationError) {
            return $validationError;
        }

        $factory = $this->getFactory();
        $item = $factory->createItem($this->prepareFields($request));

        return $this->launch($factory->getAddOperation($item), $request['guid'], 'created');
    }

    public function update(int $id, array $request): array
    {
        $validationError = $this->validateRequest($request);
        if ($validationError) {
            return $validationError;
        }

        $factory = $this->getFactory();
        $item = $factory->getItem($id);

        if (!$item) {
            return $this->errorResult('UPDATE ERROR', "Элемент $id не найден", $request['guid']);
        }

        foreach ($this->prepareFields($request) as $key => $val) {
            if ($item->hasField($key)) {
                $item->set($key, $val);
            }
        }

        return $this->launch($factory->getUpdateOperation($item), $request['guid'], 'updated');
    }

    public function getExistId(string $guid): int|false
    {
        if (empty($guid)) {
            return false;
        }

        $items = $this->getFactory()->getItems([
            'select' => ['ID'],
            'filter' => ['=' . $this->getGuidFieldName() => $guid],
            'limit' => 1,
        ]);

        return ($first = reset($items)) ? (int)$first->getId() : false;
    }

    protected function prepareFields(array $request): array
    {
        $managerId = $this->resolveManagerId($request);
        $bindingMap = $this->getBindingMap();

        $fields = [
            'TITLE' => $request['name'] ?? '',
            'ASSIGNED_BY_ID' => $managerId ?? 1,
            $this->getGuidFieldName() => $request['guid'],
        ];

        foreach ($this->getFieldMap() as $reqKey => $b24Key) {
            if (!isset($request[$reqKey])) {
                continue;
            }

            $val = $request[$reqKey];

            if (isset($this->getEnumFields()[$reqKey]) && !empty($val) && !is_numeric($val)) {
                $val = $this->getEnumValueId($b24Key, (string)$val) ?? $val;
            }

            if (isset($bindingMap[$reqKey]) && is_array($val)) {
                $val = $this->resolveBinding($val, $bindingMap[$reqKey]);
            }

            if (is_array($val) && isset($val['b24_id'])) {
                $val = $val['b24_id'];
            }

            if ($val !== null && $val !== '') {
                $fields[$b24Key] = $val;
            }
        }

        return $this->prepareCustomFields($request, $fields);
    }

    protected function resolveBinding(array $data, EntityType $type): ?int
    {
        if (!empty($data['b24_id']) && $data['b24_id'] > 0) {
            return (int)$data['b24_id'];
        }

        if (!empty($data['guid'])) {
            return SearchEntityService::searchByGuid($data['guid'], $type);
        }

        return null;
    }

    protected function resolveManagerId(array $request): ?int
    {
        if (empty($request['manager'])) {
            return null;
        }

        $manager = $request['manager'];

        if (!empty($manager['b24_id']) && $manager['b24_id'] > 0) {
            return (int)$manager['b24_id'];
        }

        if (!empty($manager['guid'])) {
            return SearchEntityService::searchUser($manager['guid']);
        }

        return null;
    }

    protected function getEnumValueId(string $fieldName, string $value): ?int
    {
        $res = \CUserFieldEnum::GetList([], [
            'USER_FIELD_NAME' => $fieldName,
            'VALUE' => $value
        ]);

        return ($enum = $res->Fetch()) ? (int)$enum['ID'] : null;
    }

    protected function launch(Operation $operation, string $guid, string $status): array
    {
        $result = $operation->disableCheckAccess()->launch();

        return $result->isSuccess()
            ? $this->successResult((int)$operation->getItem()->getId(), $guid, $status)
            : $this->errorResult('OPERATION ERROR', implode(', ', $result->getErrorMessages()), $guid);
    }

    private function getFactory(): Factory
    {
        if (!Loader::includeModule('crm')) {
            throw new SystemException('Модуль crm не установлен');
        }

        $factory = Container::getInstance()->getFactory($this->getEntityTypeId());

        if (!$factory) {
            throw new SystemException("Factory {$this->getEntityTypeId()} not found");
        }

        return $factory;
    }

    public function getItem(array $request): array
    {
        try {
            $factory = $this->getFactory();

            if (!empty($request['b24_id'])) {
                $item = $factory->getItem((int)$request['b24_id']);
            } elseif (!empty($request['guid'])) {
                $items = $factory->getItems([
                    'filter' => ['=' . $this->getGuidFieldName() => $request['guid']],
                    'limit'  => 1,
                ]);
                $item = reset($items) ?: null;
            } else {
                return $this->errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
            }

            if (!$item) {
                $identifier = (string)($request['b24_id'] ?? $request['guid'] ?? '');
                return $this->errorResult('NOT FOUND', "Элемент не найден: {$identifier}", (string)($request['guid'] ?? ''));
            }

            return [
                'status' => 'success',
                'b24_id' => $request['b24_id'],
                'guid'   => $request['guid'],
                'data'   => $this->mapItemToResponse($item),
            ];
        } catch (\Exception $e) {
            return $this->errorResult('EXCEPTION', $e->getMessage(), (string)($request['guid'] ?? ''));
        }
    }

    protected function mapItemToResponse(\Bitrix\Crm\Item $item): array
    {
        $result = [
            'b24_id' => $item->getId(),
            'guid'   => $item->get($this->getGuidFieldName()),
            'name'   => $item->get('TITLE'),
        ];

        $reverseMap = array_flip($this->getFieldMap());
        $enumFields = $this->getEnumFields();
        $bindingMap = $this->getBindingMap();

        foreach ($reverseMap as $b24Key => $reqKey) {
            $val = $item->get($b24Key);

            if (isset($enumFields[$reqKey])) {
                $result[$reqKey] = $this->resolveEnumValue($b24Key, (int)$val) ?? $val;
                continue;
            }

            if (isset($bindingMap[$reqKey])) {
                $b24Id = $val !== null ? (int)$val : null;
                $guid  = ($b24Id > 0)
                    ? SearchEntityService::getGuidById($b24Id, $bindingMap[$reqKey])
                    : null;

                $result[$reqKey] = [
                    'b24_id' => $b24Id ?: null,
                    'guid'   => $guid,
                ];
                continue;
            }

            $result[$reqKey] = $val;
        }

        return $this->mapCustomFields($item, $result);
    }

    protected function mapCustomFields(\Bitrix\Crm\Item $item, array $result): array
    {
        return $result;
    }

    protected function resolveEnumValue(string $fieldName, int $id): ?string
    {
        $res = \CUserFieldEnum::GetList([], ['USER_FIELD_NAME' => $fieldName, 'ID' => $id]);
        return ($enum = $res->Fetch()) ? (string)$enum['VALUE'] : null;
    }
}