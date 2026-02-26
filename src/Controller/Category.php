<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Controller\DeleteInterface;
use Jtl\Connector\Core\Controller\StatisticsInterface;
use Jtl\Connector\Core\Model\Category;
use Jtl\Connector\Core\Model\CategoryI18n;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Definition\IdentityType;
use PDO;

final class CategoryController implements PullInterface, PushInterface, DeleteInterface, StatisticsInterface
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
        $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');

        // In CS-Cart, categories are global. Vendors need them to classify products.
        $stmt = $this->pdo->prepare('SELECT c.category_id, c.parent_id, c.status, d.category, d.description FROM ' . $prefix . 'categories c JOIN ' . $prefix . 'category_descriptions d ON d.category_id=c.category_id AND d.lang_code=? ORDER BY c.category_id ASC');
        $stmt->execute([$lang]);
        $rows = $stmt->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $cat = (new Category())
                ->setId(new Identity((string)$r['category_id']))
                ->setIsActive(($r['status'] ?? 'D') === 'A')
                ->setParentCategoryId(new Identity((string)($r['parent_id'] ?? '')));

            $i18n = (new CategoryI18n())
                ->setLanguageIso($lang)
                ->setName((string)($r['category'] ?? ''))
                ->setDescription((string)($r['description'] ?? ''));

            $cat->addI18n($i18n);
            $out[] = $cat;
        }

        return $out;
    }

    public function push(AbstractModel $model): AbstractModel
    {
        /** @var Category $model */
        $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');

        // Capture a structured payload sample for troubleshooting (best-effort; requires verbose_enabled).
        try {
            \fn_jtl_connector_capture_payload_sample($this->companyId, 'category', 'push', $model, null);
        } catch (\Throwable $e) {
            // ignore
        }

        $endpointId = $model->getId()->getEndpoint();
        $categoryId = $endpointId !== '' ? (int)$endpointId : 0;

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

        $parentId = (int)($model->getParentCategoryId()?->getEndpoint() ?? 0);
        $status = $model->getIsActive() ? 'A' : 'D';

        // Prefer CS-Cart core functions (they calculate id_path, level, etc.)
        if (function_exists('fn_update_category')) {
            $data = [
                'parent_id' => $parentId,
                'status' => $status,
                'category' => $name,
                'description' => $desc,
                'lang_code' => $lang,
            ];
            $newId = (int)\fn_update_category($data, $categoryId, $lang);
            if ($newId > 0) {
                $model->getId()->setEndpoint((string)$newId);
            }
            return $model;
        }

        // Fallback: minimal SQL upsert (may miss derived fields in some CS-Cart setups)
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';

        if ($categoryId > 0) {
            $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'categories SET parent_id=?, status=? WHERE category_id=?');
            $stmt->execute([$parentId, $status, $categoryId]);
        } else {
            $stmt = $this->pdo->prepare('INSERT INTO ' . $prefix . 'categories (parent_id, status) VALUES (?, ?)');
            $stmt->execute([$parentId, $status]);
            $categoryId = (int)$this->pdo->lastInsertId();
            $model->getId()->setEndpoint((string)$categoryId);
        }

        $stmt = $this->pdo->prepare('REPLACE INTO ' . $prefix . 'category_descriptions (category_id, lang_code, category, description) VALUES (?, ?, ?, ?)');
        $stmt->execute([$categoryId, $lang, $name, $desc]);

        return $model;
    }

    public function delete(AbstractModel $model): AbstractModel
    {
        /** @var Category $model */
        $categoryId = (int)$model->getId()->getEndpoint();
        if ($categoryId <= 0) {
            return $model;
        }

        if (function_exists('fn_update_category')) {
            $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');
            \fn_update_category(['status' => 'D'], $categoryId, $lang);
            return $model;
        }

        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $stmt = $this->pdo->prepare('UPDATE ' . $prefix . 'categories SET status=? WHERE category_id=?');
        $stmt->execute(['D', $categoryId]);
        return $model;
    }

    public function statistic(QueryFilter $queryFilter): int
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $stmt = $this->pdo->query('SELECT COUNT(*) as c FROM ' . $prefix . 'categories');
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }
}
