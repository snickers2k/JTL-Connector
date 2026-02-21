<?php
declare(strict_types=1);

namespace CsCartJtlConnector;

use Jtl\Connector\Core\Application\Application;
use Jtl\Connector\Core\Config\ConfigParameter;
use Jtl\Connector\Core\Config\ConfigSchema;
use Noodlehaus\Config;
use Tygh\Registry;

final class Bootstrap
{
    public static function runEndpoint(int $companyId, string $token): void
    {
        // Connector working dir per vendor
        $varDir = rtrim(Registry::get('config.dir.var'), '/');
        $connectorDir = $varDir . '/jtl_connector/' . $companyId;

        if (!is_dir($connectorDir)) {
            @mkdir($connectorDir, 0775, true);
        }

        $db = \fn_jtl_connector_get_db_params();
        $featuresPath = __DIR__ . '/../lib/jtl_connector_runtime/features.json';

        $configSchema = (new ConfigSchema())
            ->setParameter(new ConfigParameter('token', 'string', true))
            ->setParameter(new ConfigParameter('db.host', 'string', true))
            ->setParameter(new ConfigParameter('db.name', 'string', true))
            ->setParameter(new ConfigParameter('db.username', 'string', true))
            ->setParameter(new ConfigParameter('db.password', 'string', true))
            ->setParameter(new ConfigParameter(ConfigSchema::FEATURES_PATH, 'string', true));

        $cfgArray = [
            'token' => $token,
            'db' => $db,
            ConfigSchema::FEATURES_PATH => $featuresPath,
        ];

        $config = new Config($cfgArray);

        $application = new Application($connectorDir, $config, $configSchema);
        $connector = new Connector\CsCartConnector($companyId, $config);
        $application->run($connector);
    }
}
