<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class AttachFileValidate extends BaseValidator
{
    // Указываем обязательные параметры для создания документа/дела
    private const REQUIRED_KEYS = [
        'entityId',     // ID сущности CRM (например, ID сделки)
        'entityTypeId', // ID типа сущности (2 - Сделка и т.д.)
        'title',        // Название документа/дела
        'pdfBase64',    // Строка base64 для PDF
        'docxBase64'    // Строка base64 для DOCX (шаблон/заглушка)
    ];

    public static function checkParams(array $data): array
    {
        return parent::validate($data, self::REQUIRED_KEYS);
    }
}