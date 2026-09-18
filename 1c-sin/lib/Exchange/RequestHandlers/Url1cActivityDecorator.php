<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers\Decorators;

use Local\Backoffice\ActivityService;
use Rusgeocom\Rusgeocom\Exchange\RequestHandlers\RequestHandlerInterface;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Throwable;

class Url1cActivityDecorator implements RequestHandlerInterface
{
    private RequestHandlerInterface $inner;
    private string $subject;
    private int $ownerTypeId;
    private AbstractLogger $logger;

    public function __construct(RequestHandlerInterface $inner, string $subject, int $ownerTypeId)
    {
        $this->inner = $inner;
        $this->subject = $subject;
        $this->ownerTypeId = $ownerTypeId;
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $result = $this->inner->handle($request);

        $this->logActivityIfNeeded($request, $result);

        return $result;
    }

    private function logActivityIfNeeded(array $request, array $result): void
    {
        $url1c = (string)($request['URL1C'] ?? '');
        $entityId = (int)($result['b24_id'] ?? 0);

        if ($url1c === '' || $entityId <= 0) {
            return;
        }

        try {
            ActivityService::create1CLog($entityId, $this->subject, $url1c, $this->ownerTypeId);
        } catch (Throwable $exc) {
            $this->logger->exception($exc, 'Не удалось создать/обновить дело со ссылкой на 1С', [
                'entityId' => $entityId,
                'url1c' => $url1c,
                'ownerTypeId' => $this->ownerTypeId,
            ]);
        }
    }
}