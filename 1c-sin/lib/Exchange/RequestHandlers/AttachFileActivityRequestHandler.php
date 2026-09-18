<?php

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Local\Backoffice\DocumentService;
use Rusgeocom\Rusgeocom\Exchange\Services\AttachFileService;
use Rusgeocom\Rusgeocom\Exchange\Validate\AttachFileValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Bitrix\Main\Loader;

class AttachFileActivityRequestHandler implements RequestHandlerInterface
{
    private AbstractLogger $logger;


    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);

    }

    public function handle(array $request): array
    {
        if (!Loader::includeModule('crm')) {
        }

        $this->logger->info('Получен новый запрос на прикрепление файла/создание дела', $request);

        if (!empty($error = AttachFileValidate::checkParams($request))) {
            $this->logger->warning('Ошибка валидации параметров прикрепления файла', $error);
            return $error;
        }


        $result = DocumentService::upload(
            $request['entityId'],
            $request['entityTypeId'],
            $request['title'],
            $request['number']  ?? 'СЧ-' . date('YmdHis') . '-' . $request['entityId'],
            $request['pdfBase64'],
            $request['docxBase64'],
            $request['pdfFileName'] ?? 'document.pdf',
            $request['docxFileName'] ?? 'stub.docx'
        );


        if (!$result) {
            $this->logger->error('Не удалось прикрепить файл или создать дело в CRM', $request);
            return [
                'status' => 'error',
                'message' => 'Failed to attach file or create activity'
            ];
        }
        $this->logger->info('Файл успешно прикреплен', ['result' => $result]);

        return [
            'status' => 'success',
        ];
    }
}