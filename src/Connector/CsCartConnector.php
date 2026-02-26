<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Connector;

use Jtl\Connector\Core\Authentication\TokenValidatorInterface;
use Jtl\Connector\Core\Config\CoreConfigInterface;
use Jtl\Connector\Core\Config\ConfigSchema;
use Jtl\Connector\Core\Connector\ConnectorInterface;
use Jtl\Connector\Core\Mapper\PrimaryKeyMapperInterface;
use Noodlehaus\ConfigInterface;
use PDO;
use DI\Container;
use CsCartJtlConnector\Mapper\CsCartPrimaryKeyMapper;
use CsCartJtlConnector\Auth\VendorTokenValidator;

final class CsCartConnector implements ConnectorInterface
{
    private const INSTALLER_LOCK_FILE = 'installer.lock';

    private int $companyId;
    private ConfigInterface $config;
    private PDO $pdo;

    public function __construct(int $companyId, ConfigInterface $config)
    {
        $this->companyId = $companyId;
        $this->config = $config;
    }

    public function initialize(Container $container, CoreConfigInterface $config): void
    {
        $this->pdo = $this->createPdoInstance($config->get('db'));
        $connectorDir = (string)$config->get(ConfigSchema::CONNECTOR_DIR);

        $lockFile = sprintf('%s/%s', $connectorDir, self::INSTALLER_LOCK_FILE);
        if (!is_file($lockFile)) {
            // No schema install needed; CS-Cart SQL install created tables already.
            file_put_contents($lockFile, sprintf('Created at %s', (new \DateTimeImmutable())->format('c')));
        }

        $container->set(PDO::class, $this->pdo);
        $container->set('company_id', $this->companyId);
    }

    public function getPrimaryKeyMapper(): PrimaryKeyMapperInterface
    {
        return new CsCartPrimaryKeyMapper($this->pdo, $this->companyId);
    }

    public function getTokenValidator(): TokenValidatorInterface
    {
        return new VendorTokenValidator((string)$this->config->get('token'));
    }

    public function getControllerNamespace(): string
    {
        return 'CsCartJtlConnector\\Controller';
    }

    public function getEndpointVersion(): string
    {
        return '0.3.1';
    }

    public function getPlatformVersion(): string
    {
        return '';
    }

    public function getPlatformName(): string
    {
        return 'CS-Cart';
    }

    private function createPdoInstance(array $dbParams): PDO
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8', $dbParams['host'], $dbParams['name']);
        $pdo = new PDO($dsn, $dbParams['username'], $dbParams['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    }
}
