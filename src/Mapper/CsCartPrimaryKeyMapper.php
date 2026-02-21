<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Mapper;

use Jtl\Connector\Core\Mapper\PrimaryKeyMapperInterface;
use PDO;

final class CsCartPrimaryKeyMapper implements PrimaryKeyMapperInterface
{
    private PDO $pdo;
    private int $companyId;

    public function __construct(PDO $pdo, int $companyId)
    {
        $this->pdo = $pdo;
        $this->companyId = $companyId;
    }

    public function getHostId(int $type, string $endpointId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT host FROM ' . $this->table() . ' WHERE company_id = ? AND type = ? AND endpoint = ? LIMIT 1');
        $stmt->execute([$this->companyId, $type, $endpointId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['host'] : null;
    }

    public function getEndpointId(int $type, int $hostId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT endpoint FROM ' . $this->table() . ' WHERE company_id = ? AND type = ? AND host = ? LIMIT 1');
        $stmt->execute([$this->companyId, $type, $hostId]);
        $row = $stmt->fetch();
        return $row ? (string)$row['endpoint'] : null;
    }

    public function save(int $type, string $endpointId, int $hostId): bool
    {
        $stmt = $this->pdo->prepare(
            'REPLACE INTO ' . $this->table() . ' (company_id, type, endpoint, host) VALUES (?, ?, ?, ?)'
        );
        return $stmt->execute([$this->companyId, $type, $endpointId, $hostId]);
    }

    public function delete(int $type, ?string $endpointId = null, ?int $hostId = null): bool
    {
        $where = ['company_id = ?', 'type = ?'];
        $params = [$this->companyId, $type];
        if ($endpointId !== null) {
            $where[] = 'endpoint = ?';
            $params[] = $endpointId;
        }
        if ($hostId !== null) {
            $where[] = 'host = ?';
            $params[] = $hostId;
        }
        $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table() . ' WHERE ' . implode(' AND ', $where));
        return $stmt->execute($params);
    }

    public function clear(?int $type = null): bool
    {
        if ($type === null) {
            $stmt = $this->pdo->prepare('DELETE FROM ' . $this->table() . ' WHERE company_id = ?');
            return $stmt->execute([$this->companyId]);
        }
        return $this->delete($type);
    }

    private function table(): string
    {
        // CS-Cart table prefix constant (usually "cscart_")
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';
        return $prefix . 'jtl_connector_mapping';
    }
}
