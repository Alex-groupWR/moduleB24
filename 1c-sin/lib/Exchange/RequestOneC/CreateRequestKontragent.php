<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestOneC;

use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;

class CreateRequestKontragent implements RequestOneCInterface
{
	private LoggerInterface $logger;

	public function __construct()
	{
		$this->logger = LoggerFactory::get(static::class);
	}

	public function handle(array $request): array
	{
        $this->logger->info('Пришло событие от компании', $request);
        if (!$kontragent = KontragentBuilder::build($request)){
            $this->logger->info('Не удалось передать контрагент отсутствуют обязательные поля', $request);
        }

        return $kontragent;
	}
}