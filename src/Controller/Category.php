<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Controller\StatisticsInterface;
use Jtl\Connector\Core\Model\Category;
use Jtl\Connector\Core\Model\CategoryI18n;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use Jtl\Connector\Core\Definition\IdentityType;
use PDO;

final class CategoryController implements PullInterface, StatisticsInterface
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

    public function statistic(QueryFilter $queryFilter): int
    {
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        $stmt = $this->pdo->query('SELECT COUNT(*) as c FROM ' . $prefix . 'categories');
        $row = $stmt->fetch();
        return (int)($row['c'] ?? 0);
    }
}
