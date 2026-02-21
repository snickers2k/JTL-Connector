<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Controller\DeleteInterface;
use Jtl\Connector\Core\Controller\StatisticsInterface;
use Jtl\Connector\Core\Model\Product;
use Jtl\Connector\Core\Model\ProductI18n;
use Jtl\Connector\Core\Model\ProductPrice;
use Jtl\Connector\Core\Model\ProductPriceItem;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Definition\IdentityType;
use PDO;

final class ProductController implements PullInterface, PushInterface, DeleteInterface, StatisticsInterface
{
    private PDO $pdo;
    private int $companyId;

    public function __construct(PDO $pdo, int $company_id)
    {
        $this->pdo = $pdo;
        $this->companyId = $company_id;
    }

    public function push(\Jtl\Connector\Core\Model\AbstractModel $model): \Jtl\Connector\Core\Model\AbstractModel
    {
        /** @var Product $model */
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');

        $endpointId = $model->getId()->getEndpoint();
        $productId = $endpointId !== '' ? (int)$endpointId : 0;

        $name = '';
        $desc = '';
        foreach ($model->getI18ns() as $i18n) {
            if ($i18n->getLanguageIso() === $lang) {
                $name = $i18n->getName();
                $desc = $i18n->getDescription();
                break;
            }
            if ($name === '' && $i18n->getName() !== '') {
                $name = $i18n->getName();
                $desc = $i18n->getDescription();
            }
        }

        $sku = $model->getSku();
        $stock = $model->getStockLevel();

        // Determine default net price from first ProductPrice
        $netPrice = null;
        foreach ($model->getPrices() as $price) {
            foreach ($price->getItems() as $item) {
                if ($item->getQuantity() <= 1) {
                    $netPrice = $item->getNetPrice();
                    break 2;
                }
            }
        }

        // Insert/update products
        if ($productId > 0) {
            $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'products SET product_code=?, amount=?, price=? WHERE product_id=? AND company_id=?');
            $stmt->execute([
                $sku,
                $stock,
                $netPrice ?? 0.0,
                $productId,
                $this->companyId,
            ]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO ' . $prefix . 'products (company_id, product_code, amount, price, status) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $this->companyId,
                $sku,
                $stock,
                $netPrice ?? 0.0,
                $model->getIsActive() ? 'A' : 'D',
            ]);
            $productId = (int)$this->pdo->lastInsertId();
            $model->getId()->setEndpoint((string)$productId);
        }

        // Upsert description
        $stmt = $this->pdo->prepare('REPLACE INTO ' . $prefix . 'product_descriptions (product_id, lang_code, product, full_description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$productId, $lang, $name, $desc]);

        return $model;
    }

    public function delete(\Jtl\Connector\Core\Model\AbstractModel $model): \Jtl\Connector\Core\Model\AbstractModel
    {
        /** @var Product $model */
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $productId = (int)$model->getId()->getEndpoint();
        if ($productId > 0) {
            // Soft-delete: disable product
            $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'products SET status = ? WHERE product_id=? AND company_id=?');
            $stmt->execute(['D', $productId, $this->companyId]);
        }
        return $model;
    }

    public function pull(QueryFilter $queryFilter): array
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');

        // Pull products that are not linked yet in mapping table for this company
        $stmt = $this->pdo->prepare(
            'SELECT p.product_id, p.product_code, p.amount, p.price, p.status, d.product, d.full_description
             FROM ' . $prefix . 'products p
             LEFT JOIN ' . $prefix . 'product_descriptions d ON d.product_id=p.product_id AND d.lang_code=?
             LEFT JOIN ' . $prefix . 'jtl_connector_mapping m ON m.company_id=? AND m.type=? AND m.endpoint=CAST(p.product_id AS CHAR)
             WHERE p.company_id=? AND (m.host IS NULL)
             ORDER BY p.product_id ASC
             LIMIT 1000'
        );
        $stmt->execute([$lang, $this->companyId, IdentityType::PRODUCT, $this->companyId]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $prod = (new Product())
                ->setId(new Identity((string)$r['product_id']))
                ->setSku((string)($r['product_code'] ?? ''))
                ->setStockLevel((float)($r['amount'] ?? 0))
                ->setIsActive(($r['status'] ?? 'D') === 'A');

            $i18n = (new ProductI18n())
                ->setLanguageIso($lang)
                ->setName((string)($r['product'] ?? ''))
                ->setDescription((string)($r['full_description'] ?? ''));

            $prod->addI18n($i18n);

            // Price (net)
            $price = (new ProductPrice())
                ->setProductId(new Identity((string)$r['product_id']))
                ->setCustomerGroupId(new Identity('')); // default group
            $price->addItem((new ProductPriceItem())->setQuantity(1)->setNetPrice((float)($r['price'] ?? 0.0)));
            $prod->addPrice($price);

            $out[] = $prod;
        }

        return $out;
    }

    public function statistic(QueryFilter $queryFilter): int
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) c
             FROM ' . $prefix . 'products p
             LEFT JOIN ' . $prefix . 'jtl_connector_mapping m ON m.company_id=? AND m.type=? AND m.endpoint=CAST(p.product_id AS CHAR)
             WHERE p.company_id=? AND m.host IS NULL'
        );
        $stmt->execute([$this->companyId, IdentityType::PRODUCT, $this->companyId]);
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }
}
