<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PullInterface;
use Jtl\Connector\Core\Model\GlobalData;
use Jtl\Connector\Core\Model\Language;
use Jtl\Connector\Core\Model\Currency;
use Jtl\Connector\Core\Model\CustomerGroup;
use Jtl\Connector\Core\Model\CustomerGroupI18n;
use Jtl\Connector\Core\Model\TaxRate;
use Jtl\Connector\Core\Model\ShippingMethod;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use PDO;
use Tygh\Registry;

final class GlobalDataController implements PullInterface
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
        $globalData = new GlobalData();

        $defaultLang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');

        // Languages (from CS-Cart languages table)
        try {
            $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
            $rows = $this->pdo->query('SELECT lang_code, name, status FROM ' . $prefix . 'languages')->fetchAll();
            foreach ($rows as $row) {
                $iso = (string)$row['lang_code'];
                if ($iso === '') continue;
                $globalData->addLanguage(
                    (new Language())
                        ->setId(new Identity(md5('lang:' . $iso)))
                        ->setLanguageIso($iso)
                        ->setIsDefault($iso === $defaultLang)
                        ->setNameGerman($row['name'] ?? $iso)
                        ->setNameEnglish($row['name'] ?? $iso)
                );
            }
        } catch (\Throwable $e) {
            // Fallback: en + de
            $globalData->addLanguage((new Language())->setId(new Identity(md5('lang:en')))->setLanguageIso('en')->setIsDefault($defaultLang==='en')->setNameGerman('Englisch')->setNameEnglish('English'));
            $globalData->addLanguage((new Language())->setId(new Identity(md5('lang:de')))->setLanguageIso('de')->setIsDefault($defaultLang==='de')->setNameGerman('Deutsch')->setNameEnglish('German'));
        }

        // Currency
        $currencyIso = (string)\fn_jtl_connector_get_addon_setting('currency_iso', 'EUR');
        $globalData->addCurrency(
            (new Currency())
                ->setId(new Identity(md5('cur:' . $currencyIso)))
                ->setIsDefault(true)
                ->setName($currencyIso)
                ->setIso($currencyIso)
                ->setNameHtml($currencyIso)
        );

        // Customer group (minimal; CS-Cart has usergroups but marketplace uses price list logic; keep simple)
        $globalData->addCustomerGroup(
            (new CustomerGroup())
                ->setId(new Identity(md5('cg:default')))
                ->setIsDefault(true)
                ->setApplyNetPrice(false)
                ->addI18n((new CustomerGroupI18n())->setName('Default'))
        );

        // Tax rate
        $tax = (float)\fn_jtl_connector_get_addon_setting('tax_rate_default', '19.0');
        $globalData->addTaxRate(
            (new TaxRate())
                ->setId(new Identity(md5('tax:' . $tax)))
                ->setRate($tax)
        );

        // Shipping methods (optional)
        $globalData->addShippingMethod(
            (new ShippingMethod())->setId(new Identity(md5('ship:default')))->setName('Default shipping')
        );

        return [$globalData];
    }
}
