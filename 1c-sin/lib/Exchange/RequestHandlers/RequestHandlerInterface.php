<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

/**
 * Обрабатывает запросы от 1С
 */
interface RequestHandlerInterface
{
	/**
	 * @param array $request Запрос от 1С
	 * @return array Наш ответ, может быть пустым массивом
	 */
	public function handle(array $request): array;
}