<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\Services\DealStageService;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;
use Rusgeocom\Rusgeocom\Exchange\Validate\OrderStageValidate;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class UpdateDealStageRequestHandler implements RequestHandlerInterface
{
    use ExchangeHelperTrait;

    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request): array
    {
        $this->logger->info('Получен запрос на смену стадии сделки из 1С', $request);

        if (!empty($error = OrderStageValidate::checkParams($request))) {
            return $error;
        }

        $guid = (string)$request['guid'];
        $dealId = !empty($request['b24_id'])? $request['b24_id']: DealStageService::findDealId($request);
        if (!$dealId) {
            return self::errorResult('NOT_FOUND', 'Сделка не найдена', $guid);
        }

        $direction = DealDirectionEnum::tryFrom((string)$request['category']);
        if (!$direction) {
            return self::errorResult('CATEGORY_ERROR', "Неизвестное направление: {$request['category']}", $guid);
        }

        $stageEnum = $direction->getStageFromLabel((string)$request['stage']);
        if (!$stageEnum) {
            return self::errorResult('STAGE_ERROR', "Неизвестная стадия '{$request['stage']}'", $guid);
        }

        return DealStageService::updateStage($dealId, $direction, $stageEnum, $guid);
    }
}