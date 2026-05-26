<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

/**
 * Обрабатывает ответы на наши отложенные запросы в 1С
 */
interface ResponseHandlerInterface
{
	/**
	 * @param array $request Наш запрос в 1С (MessageTable.PAYLOAD)
	 * @param array $response Ответ 1С (MessageTable.RESPONSE)
	 * @return array Результат обработки сохранится в таблицу (MessageTable.RESULT)
	 */
	public function handle(array $request, array $response): array;
}