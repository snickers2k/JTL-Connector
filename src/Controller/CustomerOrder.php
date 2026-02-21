<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Controller\StatisticsInterface;
use Jtl\Connector\Core\Model\CustomerOrder;
use Jtl\Connector\Core\Model\CustomerOrderItem;
use Jtl\Connector\Core\Model\CustomerOrderBillingAddress;
use Jtl\Connector\Core\Model\CustomerOrderShippingAddress;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Definition\IdentityType;
use PDO;

final class CustomerOrderController implements PullInterface, StatisticsInterface
{
    private PDO $pdo;
    private int $companyId;

    public function __construct(PDO $pdo, int $company_id)
    {
        $this->pdo = $pdo;
        $this->companyId = $company_id;
    }

    public function pull(QueryFilter $queryFilter): array
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $currencyIso = (string)\fn_jtl_connector_get_addon_setting('currency_iso', 'EUR');

        // Pull vendor orders not linked yet
        $stmt = $this->pdo->prepare(
            'SELECT o.order_id, o.timestamp, o.total, o.status, o.email, o.firstname, o.lastname, o.s_firstname, o.s_lastname, o.s_address, o.s_city, o.s_zipcode, o.s_country, o.b_address, o.b_city, o.b_zipcode, o.b_country
             FROM ' . $prefix . 'orders o
             LEFT JOIN ' . $prefix . 'jtl_connector_mapping m ON m.company_id=? AND m.type=? AND m.endpoint=CAST(o.order_id AS CHAR)
             WHERE o.company_id=? AND (m.host IS NULL)
             ORDER BY o.order_id ASC
             LIMIT 200'
        );
        $stmt->execute([$this->companyId, IdentityType::CUSTOMER_ORDER, $this->companyId]);
        $orders = $stmt->fetchAll();

        $out = [];
        foreach ($orders as $o) {
            $orderId = (int)$o['order_id'];
            $order = (new CustomerOrder())
                ->setId(new Identity((string)$orderId))
                ->setOrderNumber((string)$orderId)
                ->setCurrencyIso($currencyIso)
                ->setCreationDate((new \DateTimeImmutable())->setTimestamp((int)$o['timestamp']))
                ->setTotalSumGross((float)$o['total'])
                ->setTotalSum((float)$o['total']);

            // Billing address
            $ba = (new CustomerOrderBillingAddress())
                ->setFirstName((string)($o['firstname'] ?? ''))
                ->setLastName((string)($o['lastname'] ?? ''))
                ->setStreet((string)($o['b_address'] ?? ''))
                ->setCity((string)($o['b_city'] ?? ''))
                ->setZipCode((string)($o['b_zipcode'] ?? ''))
                ->setCountryIso(strtoupper((string)($o['b_country'] ?? 'DE')))
                ->setEMail((string)($o['email'] ?? ''));
            $order->setBillingAddress($ba);

            // Shipping address
            $sa = (new CustomerOrderShippingAddress())
                ->setFirstName((string)($o['s_firstname'] ?? $o['firstname'] ?? ''))
                ->setLastName((string)($o['s_lastname'] ?? $o['lastname'] ?? ''))
                ->setStreet((string)($o['s_address'] ?? ''))
                ->setCity((string)($o['s_city'] ?? ''))
                ->setZipCode((string)($o['s_zipcode'] ?? ''))
                ->setCountryIso(strtoupper((string)($o['s_country'] ?? 'DE')))
                ->setEMail((string)($o['email'] ?? ''));
            $order->setShippingAddress($sa);

            // Items
            $stmtItems = $this->pdo->prepare(
                'SELECT item_id, product_id, product_code, product, amount, price, subtotal
                 FROM ' . $prefix . 'order_details
                 WHERE order_id=?'
            );
            $stmtItems->execute([$orderId]);
            $items = $stmtItems->fetchAll();
            foreach ($items as $it) {
                $item = (new CustomerOrderItem())
                    ->setId(new Identity((string)$it['item_id']))
                    ->setType(CustomerOrderItem::TYPE_PRODUCT)
                    ->setProductId(new Identity((string)($it['product_id'] ?? '')))
                    ->setSku((string)($it['product_code'] ?? ''))
                    ->setName((string)($it['product'] ?? ''))
                    ->setQuantity((float)($it['amount'] ?? 0))
                    ->setPrice((float)($it['price'] ?? 0))
                    ->setPriceGross((float)($it['price'] ?? 0));

                $order->addItem($item);
            }

            $out[] = $order;
        }

        return $out;
    }

    public function statistic(QueryFilter $queryFilter): int
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) c
             FROM ' . $prefix . 'orders o
             LEFT JOIN ' . $prefix . 'jtl_connector_mapping m ON m.company_id=? AND m.type=? AND m.endpoint=CAST(o.order_id AS CHAR)
             WHERE o.company_id=? AND m.host IS NULL'
        );
        $stmt->execute([$this->companyId, IdentityType::CUSTOMER_ORDER, $this->companyId]);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }
}
