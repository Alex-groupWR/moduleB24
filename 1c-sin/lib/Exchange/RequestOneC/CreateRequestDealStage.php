<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestOneC;

use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;

class CreateRequestDealStage implements RequestOneCInterface
{
    private LoggerInterface $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $direction = DealDirectionEnum::fromB24EnumId((int)($request['CATEGORY_ID'] ?? 0));

        if (!$direction) {
            $this->logger->warning('Не удалось определить направление сделки', $request);
            return [];
        }

        $stageLabel = $direction->getLabelFromStage((string)($request['STAGE_ID'] ?? ''));
        if ($stageLabel === null) {
            $this->logger->warning('Не удалось определить лейбл стадии', $request);
            return [];
        }

        return [
            'b24_id' => (int)($request['ID'] ?? 0),
            'guid' => (string)($request['ORIGIN_ID'] ?? ''),
            'stage' => $stageLabel,
            'category' => $direction->value,
        ];
    }
}