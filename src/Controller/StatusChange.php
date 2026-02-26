<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Model\StatusChange;
use Jtl\Connector\Core\Model\AbstractModel;
use PDO;

final class StatusChangeController implements PushInterface
{
    private PDO $pdo;
    private int $companyId;

    public function __construct(PDO $pdo, int $company_id)
    {
        $this->pdo = $pdo;
        $this->companyId = $company_id;
    }

    public function push(AbstractModel $model): AbstractModel
    {
        /** @var StatusChange $model */
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';

        // Capture a structured payload sample for troubleshooting (best-effort; requires verbose_enabled).
        try {
            \fn_jtl_connector_capture_payload_sample($this->companyId, 'status_change', 'push', $model, null);
        } catch (\Throwable $e) {
            // ignore
        }
        $orderId = (int)$model->getCustomerOrderId()?->getEndpoint();

        if ($orderId > 0) {
            $orderStatus = strtolower((string)$model->getOrderStatus());
            $paymentStatus = '';
            if (method_exists($model, 'getPaymentStatus')) {
                $paymentStatus = strtolower((string)$model->getPaymentStatus());
            }

            $map = [
                'shipped' => (string)\fn_jtl_connector_get_addon_setting('order_status_shipped', 'C'),
                'cancelled' => (string)\fn_jtl_connector_get_addon_setting('order_status_cancelled', 'I'),
                'delivered' => (string)\fn_jtl_connector_get_addon_setting('order_status_delivered', 'C'),
                'processing' => (string)\fn_jtl_connector_get_addon_setting('order_status_processing', 'O'),
            ];

            $target = $map[$orderStatus] ?? null;

            // If orderStatus doesn't map, try paymentStatus.
            if (($target === null || $target === '') && $paymentStatus === 'paid') {
                $target = (string)\fn_jtl_connector_get_addon_setting('order_status_paid', 'P');
            }

            if (is_string($target) && $target !== '') {
                $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'orders SET status=? WHERE order_id=? AND company_id=?');
                $stmt->execute([$target, $orderId, $this->companyId]);
            }
        }

        return $model;
    }
}
