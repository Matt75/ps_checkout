<?php
/**
 * 2007-2020 PrestaShop and Contributors
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2020 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

namespace PrestaShop\Module\PrestashopCheckout;

class OrderDispatcher implements Dispatcher
{
    const PS_CHECKOUT_PAYMENT_REVERSED = 'PaymentCaptureReversed';
    const PS_CHECKOUT_PAYMENT_REFUNED = 'PaymentCaptureRefunded';
    const PS_CHECKOUT_PAYMENT_AUTH_VOIDED = 'PaymentAuthorizationVoided';
    const PS_CHECKOUT_PAYMENT_PENDING = 'PaymentCapturePending';
    const PS_CHECKOUT_PAYMENT_COMPLETED = 'PaymentCaptureCompleted';
    const PS_CHECKOUT_PAYMENT_DENIED = 'PaymentCaptureDenied';

    /**
     * @var array
     */
    private $matriceEventAndOrderState;

    /**
     * OrderDispatcher constructor.
     */
    public function __construct()
    {
        $this->matriceEventAndOrderState = [
            self::PS_CHECKOUT_PAYMENT_AUTH_VOIDED => \Configuration::get('PS_OS_CANCELED'),
            self::PS_CHECKOUT_PAYMENT_PENDING => \Configuration::get('PS_CHECKOUT_STATE_WAITING_PAYPAL_PAYMENT'), // OS_PREPARATION should be used only before shipping !
            self::PS_CHECKOUT_PAYMENT_COMPLETED => \Configuration::get('PS_OS_PAYMENT'), // Payment accepted
            self::PS_CHECKOUT_PAYMENT_DENIED => \Configuration::get('PS_OS_ERROR'), // Payment error
        ];
    }

    /**
     * Dispatch the Event Type to manage the merchant status
     *
     * {@inheritdoc}
     */
    public function dispatchEventType($payload)
    {
        if (empty($payload['orderId'])) {
            throw new UnauthorizedException(\PrestaShop\Module\PrestashopCheckout\WebHookValidation::ORDER_ERROR);
        }

        $result = true;
        $orderIds = (new \OrderMatrice())->getPrestaShopOrdersByPayPalOrder($payload['orderId']);

        if (empty($orderIds)) {
            throw new UnprocessableException('order #' . $payload['orderId'] . ' does not exist');
        }

        foreach ($orderIds as $orderId) {
            if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_REFUNED
                || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_REVERSED) {
                $result = $result && $this->dispatchPaymentAction($payload['eventType'], $payload['resource'], $orderId);
            }

            if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_COMPLETED
                || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_DENIED
                || $payload['eventType'] === self::PS_CHECKOUT_PAYMENT_AUTH_VOIDED) {
                $result = $result && $this->dispatchPaymentStatus($payload['eventType'], $payload['resource'], $orderId);
            }
        }

        // For now, if pending, do not change anything
        if ($payload['eventType'] === self::PS_CHECKOUT_PAYMENT_PENDING) {
            return true;
        }

        return $result;
    }

    /**
     * Dispatch the Event Type to the payments action Refunded or Revesed
     *
     * @param string $eventType
     * @param array $resource
     * @param int $orderId
     *
     * @return bool
     */
    private function dispatchPaymentAction($eventType, $resource, $orderId)
    {
        $orderError = (new WebHookValidation())->validateRefundResourceValues($resource);

        if (!empty($orderError)) {
            throw new UnauthorizedException($orderError);
        }

        $initiateBy = 'Merchant';

        if ($eventType === self::PS_CHECKOUT_PAYMENT_REVERSED) {
            $initiateBy = 'Paypal';
        }

        return (new WebHookOrder($initiateBy, $resource, $orderId))->updateOrder();
    }

    /**
     * Dispatch the event Type the the payment status PENDING / COMPLETED / DENIED / AUTH_VOIDED
     *
     * @param string $eventType
     * @param array $resource
     * @param int $orderId
     *
     * @return bool
     */
    private function dispatchPaymentStatus($eventType, $resource, $orderId)
    {
        $orderError = (new WebHookValidation())->validateRefundOrderIdValue($orderId);

        if (!empty($orderError)) {
            throw new UnauthorizedException($orderError);
        }

        $order = new \Order($orderId);
        $lastOrderStateId = (int) $order->getCurrentState();
        $newOrderStateId = (int) $this->matriceEventAndOrderState[$eventType];
        $shouldAddOrderPayment = true;

        /** @var \OrderPayment[] $orderPayments */
        $orderPayments = $order->getOrderPaymentCollection();
        foreach ($orderPayments as $orderPayment) {
            if ($orderPayment->transaction_id === $resource['id']) {
                $shouldAddOrderPayment = false;
            }
        }

        if (true === $shouldAddOrderPayment) {
            $order->addOrderPayment(
                $resource['amount']['value'],
                $order->payment,
                $resource['id'],
                \Currency::getCurrencyInstance(\Currency::getIdByIsoCode($resource['amount']['currency_code'])),
                (new \DateTime($resource['create_time']))->format('Y-m-d H:i:s')
            );
        }

        // Prevent duplicate state entry
        if ($lastOrderStateId === $newOrderStateId
            || $order->hasBeenPaid()
            || $order->hasBeenShipped()
            || $order->hasBeenDelivered()
            || $order->isInPreparation()) {
            return false;
        }

        $orderHistory = new \OrderHistory();
        $orderHistory->id_order = $orderId;
        $orderHistory->changeIdOrderState(
            $this->matriceEventAndOrderState[$eventType],
            $orderId
        );

        if (true !== $orderHistory->addWithemail()) {
            throw new UnauthorizedException('unable to change the order state');
        }

        return true;
    }
}
