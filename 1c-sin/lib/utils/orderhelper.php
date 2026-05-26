<?php
namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Entity\Query;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Sale\Internals\BasketTable;
use Bitrix\Sale\Internals\OrderCouponsTable;
use Bitrix\Sale\Internals\OrderPropsTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\OrderTable;
use Bitrix\Sale\PropertyValue;
use Rusgeocom\Rusgeocom\Delivery\Handlers\CdekPickupHandler;
use Rusgeocom\Rusgeocom\payment\YooKassaService;
use Rusgeocom\Rusgeocom\Personal\Entities\PersonalOrderStatus;
use Rusgeocom\Rusgeocom\Sale\Entities\Order as RgkOrder;
use Rusgeocom\Rusgeocom\Sale\Services\DocumentService;
use Rusgeocom\Rusgeocom\Sale\Services\PaymentSystemService;
use Rusgeocom\Rusgeocom\Sale\OrderStatusHelper;
use Rusgeocom\Rusgeocom\Sale\Tables\DocumentTable;

class OrderHelper
{
	public const CANCEL_TYPE_NOT_ALLOWED = 'not_allowed';
	public const CANCEL_TYPE_STANDARD = 'standard';
	public const CANCEL_TYPE_MONEY_BACK = 'money_back';
	public const SYNC_STATUS_WAITING = 'waiting';
	public const SYNC_STATUS_COMPLETED = 'completed';
	public const SYNC_STATUS_ERROR = 'error';

	/**
	 * @var Order
	 */
	protected $order;
	protected $props = [];
	protected static $propertyMap;

	public static function forOrder(Order $order)
	{
		return new static($order);
	}

	protected function __construct(Order $order)
	{
		$this->order = $order;

		/** @var PropertyValue $prop */
		foreach ($order->getPropertyCollection() as $prop){
			$this->props[$prop->getPersonTypeId()][$prop->getField('CODE')] = $prop;
		}
	}

	public function getOrder() : Order
	{
		return $this->order;
	}

	public function getPropValue(string $code, int $personTypeId = 0)
	{
		/** @var PropertyValue $prop */
		$prop = $this->getPropByCode($code, $personTypeId);
		return $prop ? $prop->getValue() : '';
	}

	public function setPropValue(string $code, string $value): void
	{
		/** @var PropertyValue $prop */
		$prop = $this->getPropByCode($code);
		if (!$prop) {
			throw new Exception('Свойство ' . $code . ' не найдено');
		}

		$prop->setValue($value);
	}

	public static function getPropertyMap()
	{
		if (!static::$propertyMap){
			static::fillPropMap();
		}

		return static::$propertyMap;
	}

	public static function getPropertyIdByCode(string $code, int $personTypeId): int
	{
		foreach (static::getPropertyMap() as $prop){
			if ($prop['CODE'] == $code && $prop['PERSON_TYPE_ID'] == $personTypeId){
				return (int)$prop['ID'];
			}
		}

		return 0;
	}

	protected static function fillPropMap()
	{
		$iterator = OrderPropsTable::query()
			->addSelect('ID')
			->addSelect('CODE')
			->addSelect('PERSON_TYPE_ID')
			->addFilter('ACTIVE', 'Y')
			->exec();
		while ($row = $iterator->fetch()){
			if ($row['CODE']){
				static::$propertyMap[$row['ID']] = $row;
			}
		}
	}

	/**
	 * @param string $code
	 * @param int    $personTypeId
	 * @return PropertyValue|null
	 */
	public function getPropByCode(string $code, int $personTypeId = 0)
	{
		if (!$personTypeId){
			$personTypeId = $this->order->getPersonTypeId();
		}

		return $this->props[$personTypeId][$code];
	}

	public static function getOrderUrl(int $orderId): string
	{
		return "/spasibo_za_zakaz.html?ORDER_ID={$orderId}&SITE_TEMPLATE=basket";
	}

	public static function getPaymentNameByPaymentSystemId(int $paymentSystemId): string
	{
		switch ($paymentSystemId) {
			case PaymentSystemService::getIdByCode(PaymentSystemService::BILL):
				return 'На расчетный счет';
			case PaymentSystemService::getIdByCode(PaymentSystemService::CASH):
				return 'Наличными или банковской картой при получении';
			case PaymentSystemService::getIdByCode(PaymentSystemService::TINKOFF_CREDIT):
				return 'В кредит';
			case PaymentSystemService::getIdByCode(PaymentSystemService::TINKOFF_INSTALLMENT):
				return 'В рассрочку';
			case PaymentSystemService::getIdByCode(PaymentSystemService::SPLIT):
				return 'Частями';
			default:
				return 'Банковской картой';
		}
	}

	public static function getDocumentsUrl(int $orderId): string
	{
		$documentUrls = DocumentService::getAllActiveForOrder($orderId);

		if (!$documentUrls) {
			return '';
		}

		return count($documentUrls) > 1
			? '/api/order/getdocuments?orderId=' . $orderId
			: current($documentUrls)['PATH'];
	}

	public static function getInvoiceUrl(int $orderId): string
	{
		return DocumentService::getLastInvoiceUrl($orderId);
	}

	public static function getDocuments(int $orderId): array
	{
		$documents = [];

		foreach (DocumentService::getAllActiveForOrder($orderId) as $document) {
			$documents[] = [
				'name' => $document['NAME'],
				'url' => $document['PATH'],
				'ext' => pathinfo($document['PATH'], PATHINFO_EXTENSION),
			];
		}

		if (count($documents) > 1) {
			$documents[] = [
				'name' => 'Все документы',
				'url' => '/api/order/getdocuments?orderId=' . $orderId,
				'ext' => 'zip'
			];
		}

		return $documents;
	}

	public static function getOrderIdsByUserId(int $userId, string $query): array
	{
		$result = BasketTable::query()
			->setSelect(['ORDER_ID', 'PRODUCT.NAME', 'STRING_ORDER_ID'])
			->addOrder('DATE_INSERT', 'DESC')
			->where('ORDER.USER_ID', $userId)
			->where(Query::filter()
				->logic('OR')
				->whereLike("STRING_ORDER_ID", '%'.preg_replace("/[^\d]/", "", $query).'%')
				->whereLike("PRODUCT.NAME", $query)
			)->registerRuntimeField(
				'STRING_ORDER_ID',
				new ExpressionField(
					'STRING_ORDER_ID',
					'CONVERT(ORDER_ID,char)'
				)
			)->exec();

		if (!$result->getSelectedRowsCount()) {
			return [];
		}

		return array_unique(
			array_column(
				$result->fetchAll(),
				'ORDER_ID'
			)
		);
	}

	public static function makePublicAddress(RgkOrder $order): string
	{
		// Получим адрес склада, если был выбран самовывоз из него
		if ($order->getPickUpPointCode()) {
			$pickUpPointCode = $order->getPickUpPointCode();
		}else {
			$pickUpPointCode = $order->getReceiverCity() ? $order->getReceiverCity()->getFias() : '';
		}

		$delivery = $order->getDelivery();

		$address = '';
		if ($pickUpPointCode) {
			if (!$delivery && $order->getDeliveryId() === CDEK_PICKUP_DELIVERY_CODE) {
				$delivery = (new CdekPickupHandler([]));
			}

			$address = $delivery ? $delivery->getAddressByPickupPointCode($pickUpPointCode) : '';
		}

		return $address ?:  $order->getAddress();
	}


	public static function makeOrdersCancellableMap(array $orderIds, array $ordersStatus): array
	{
		$iterator = OrderTable::query()
			->addSelect('PAYED')
			->addSelect('CANCELED')
			->addSelect('ID')
			->whereIn('ID', $orderIds)
			->exec();

		$orderIdToCancelFlagMap = array_fill_keys($orderIds, true);
		while ($order = $iterator->fetch()) {
			$orderId = (int)$order['ID'];
			$status = isset($ordersStatus[$orderId]['status'])
				? $ordersStatus[$orderId]
				: [
					'status' => $ordersStatus[$orderId],
					'paymentStatus' => $ordersStatus[$orderId]
				];

			if (
				$order['CANCELED'] === 'Y'
				|| in_array(
					$status['status'],
					[
						PersonalOrderStatus::STATUS_CANCELLED,
						PersonalOrderStatus::STATUS_RECEIVED,
					],
					true
				)
				|| $status['requestedReturn'] ?? false
			) {
				$orderIdToCancelFlagMap[$orderId] = false;
			}
		}

		return $orderIdToCancelFlagMap;
	}

	public static function isOrderCancellable(int $orderId): bool
	{
		$orderCanBeCancelled = true;

		$status = OrderStatusHelper::getStatusByOrderId($orderId);
		$order = OrderTable::query()
			->addSelect('PAYED')
			->addSelect('CANCELED')
			->where('ID', $orderId)
			->setLimit(1)
			->exec()
			->fetch();

		if (
			$order['CANCELED'] === 'Y'
			|| $order['PAYED'] === 'Y'
			|| in_array(
				$status['status'] ?? '',
				[
					PersonalOrderStatus::STATUS_PAID,
					PersonalOrderStatus::STATUS_PARTIALLY_PAID,
					PersonalOrderStatus::STATUS_CANCELLED,
					PersonalOrderStatus::STATUS_RECEIVED,
				],
				true
			)
		) {
			$orderCanBeCancelled = false;
		}

		return $orderCanBeCancelled;
	}

	public static function getCancelTypeForOrder(int $orderId): string
	{
		$status = OrderStatusHelper::getStatusByOrderId($orderId);
		$order = OrderTable::query()
			->addSelect('PAYED')
			->addSelect('CANCELED')
			->where('ID', $orderId)
			->setLimit(1)
			->exec()
			->fetch();

		if (
			$order['CANCELED'] === 'Y'
			|| in_array(
				$status['status'] ?? '',
				[
					PersonalOrderStatus::STATUS_CANCELLED,
					PersonalOrderStatus::STATUS_RECEIVED,
				],
				true
			)
			|| $status['requestedReturn'] ?? false
		) {
			return static::CANCEL_TYPE_NOT_ALLOWED;
		}

		if (
			$order['PAYED'] === 'Y'
			|| ($status['payedAmount'] ?? 0) > 0
		) {
			return static::CANCEL_TYPE_MONEY_BACK;
		}

		return static::CANCEL_TYPE_STANDARD;
	}

	public static function requestReturnForOrder(\Bitrix\Sale\Order $order): void
	{
		$statusObject = OrderStatusHelper::getOrCreateStatusObject($order);
		$statusObject->setUfPaymentReturn(true);

		$statusObject->save();
	}

	public static function getCoupon(int $orderId): string
	{
		return OrderCouponsTable::query()
			->setSelect(['COUPON'])
			->where('ORDER_ID', $orderId)
			->exec()
			->fetch()['COUPON'] ?: '';
	}


	public static function fillYookassaPayment(\Bitrix\Sale\Order $order, bool $force = false): void
	{
		if (!Environment::getBool('YOOKASSA_ENABLED')) {
			return;
		}

		/** @var \Bitrix\Sale\Payment $payment */
		foreach ($order->getPaymentCollection() as $payment) {
			if (PaymentSystemService::isYookassa($payment->getPaymentSystemId())) {

				$payment = [];
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_PAYMENT_LINK') {
						if ($force || !$property->getValue()) {
							$payment = YooKassaService::createPayment($order);
							$property->setValue($payment['url']);
						}
						break;
					}
				}
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_PAYMENT_ID') {
						if ($force || ($payment && !$property->getValue())) {
							$property->setValue($payment['id']);
						}
						break;
					}
				}
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_SHOP_ID') {
						if ($force || ($payment && !$property->getValue())) {
							$property->setValue($payment['shopId'] ?? '');
							$order->save();
						}
						break;
					}
				}

				break;
			}
		}
	}

	public static function clearYookassaPayment(\Bitrix\Sale\Order $order): void
	{
		if (!Environment::getBool('YOOKASSA_ENABLED')) {
			return;
		}

		/** @var \Bitrix\Sale\Payment $payment */
		foreach ($order->getPaymentCollection() as $payment) {
			if (PaymentSystemService::isYookassa($payment->getPaymentSystemId())) {
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_SHOP_ID') {
						if ($property->getValue()) {
							$property->setValue('');
						}
						break;
					}
				}
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_PAYMENT_LINK') {
						if ($property->getValue()) {
							$property->setValue('');
						}
						break;
					}
				}
				foreach ($order->getPropertyCollection() as $property) {
					if ($property->getField('CODE') === 'YOOKASSA_PAYMENT_ID') {
						if ($property->getValue()) {
							$property->setValue('');
							$order->save();
						}
						break;
					}
				}

				break;
			}
		}
	}

	public static function getLastOrder(int $userId): ?RgkOrder
	{
		$lastOrderId = OrderTable::query()
			->setSelect(['ID'])
			->setOrder(['ID' => 'DESC'])
			->setLimit(1)
			->where('USER_ID', $userId)
			->exec()
			->fetch()['ID'];

		if (!$lastOrderId) {
			return null;
		}
		$bxOrder = Order::load($lastOrderId);

		return RgkOrder::fromBitrixOrder($bxOrder);
	}

	public static function isDiscountEnabledForOrder(Order $order): bool
	{
		$disabledFor = [
			PaymentSystemService::getIdByCode(PaymentSystemService::SPLIT),
			PaymentSystemService::getIdByCode(PaymentSystemService::TINKOFF_CREDIT),
			PaymentSystemService::getIdByCode(PaymentSystemService::TINKOFF_INSTALLMENT),
		];

		/** @var \Bitrix\Sale\Payment $payment */
		foreach ($order->getPaymentCollection() as $payment) {
			if (in_array($payment->getPaymentSystemId(), $disabledFor)) {
				return false;
			}
		}

		return true;
	}
}