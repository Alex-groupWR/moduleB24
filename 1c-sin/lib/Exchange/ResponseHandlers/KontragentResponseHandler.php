<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\Loader;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class KontragentResponseHandler implements ResponseHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request, array $response): array
    {
        $this->logger->info('Обработка ответа 1С для контрагента',$response);
        $response = reset($response['items']);

        Loader::includeModule('crm');

        $errors = [];

        $companyData = $response['company'] ?? null;
        if ($companyData !== null) {
            $error = $this->syncCompanyOriginId($companyData);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        $requisiteB24Id = isset($response['b24_id']) ? (int)$response['b24_id'] : null;
        $requisiteGuid  = $response['guid'] ?? null;
        if ($requisiteB24Id > 0 && !empty($requisiteGuid)) {
            $error = $this->syncRequisiteXmlId($requisiteB24Id, $requisiteGuid);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('Ошибки при обновлении внешних ключей контрагента', $errors);
        }

        return array_merge($response, ['sync_errors' => $errors]);
    }


    private function syncCompanyOriginId(array $companyData): ?array
    {
        $b24Id = isset($companyData['b24_id']) ? (int)$companyData['b24_id'] : 0;
        $guid  = $companyData['guid'] ?? '';

        if ($b24Id <= 0 || empty($guid)) {
            return [
                'entity'  => 'company',
                'message' => 'Недостаточно данных: нужны b24_id и guid',
                'data'    => $companyData,
            ];
        }

        $existing = CompanyTable::getList([
            'select' => ['ID', 'ORIGIN_ID'],
            'filter' => ['=ID' => $b24Id],
            'limit'  => 1,
        ])->fetch();

        if (!$existing) {
            return [
                'entity'  => 'company',
                'message' => "Компания {$b24Id} не найдена",
                'data'    => $companyData,
            ];
        }

        if ($existing['ORIGIN_ID'] === $guid) {
            $this->logger->info("Компания {$b24Id}: ORIGIN_ID уже актуален, пропускаем");
            return null;
        }

        $result = CompanyTable::update($b24Id, ['ORIGIN_ID' => $guid]);

        if (!$result->isSuccess()) {
            return [
                'entity'  => 'company',
                'message' => 'Ошибка обновления ORIGIN_ID: ' . implode(', ', $result->getErrorMessages()),
                'data'    => $companyData,
            ];
        }

        $this->logger->info("Компания {$b24Id}: ORIGIN_ID обновлён → {$guid}");
        return null;
    }

    private function syncRequisiteXmlId(int $b24Id, string $guid): ?array
    {
        $requisiteObj = new EntityRequisite();

        $existing = $requisiteObj->getList([
            'select' => ['ID', 'XML_ID'],
            'filter' => ['=ID' => $b24Id],
            'limit'  => 1,
        ])->fetch();

        if (!$existing) {
            return [
                'entity'  => 'requisite',
                'message' => "Реквизит {$b24Id} не найден",
                'data'    => ['b24_id' => $b24Id, 'guid' => $guid],
            ];
        }

        if ($existing['XML_ID'] === $guid) {
            $this->logger->info("Реквизит {$b24Id}: XML_ID уже актуален, пропускаем");
            return null;
        }

        $result = $requisiteObj->update($b24Id, ['XML_ID' => $guid]);

        if (!$result->isSuccess()) {
            return [
                'entity'  => 'requisite',
                'message' => 'Ошибка обновления XML_ID: ' . implode(', ', $result->getErrorMessages()),
                'data'    => ['b24_id' => $b24Id, 'guid' => $guid],
            ];
        }

        $this->logger->info("Реквизит {$b24Id}: XML_ID обновлён → {$guid}");
        return null;
    }
}
