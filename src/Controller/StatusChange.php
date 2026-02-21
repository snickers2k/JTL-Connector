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
        $orderId = (int)$model->getCustomerOrderId()?->getEndpoint();

        if ($orderId > 0) {
            $target = null;
            if ($model->getOrderStatus() === 'shipped') {
                $target = (string)\fn_jtl_connector_get_addon_setting('order_status_shipped', 'C');
            } elseif ($model->getOrderStatus() === 'cancelled') {
                $target = (string)\fn_jtl_connector_get_addon_setting('order_status_cancelled', 'I');
            }

            if ($target) {
                $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'orders SET status=? WHERE order_id=? AND company_id=?');
                $stmt->execute([$target, $orderId, $this->companyId]);
            }
        }

        return $model;
    }
}
