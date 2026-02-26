<?php
declare(strict_types=1);

namespace CsCartJtlConnector\Controller;

use Jtl\Connector\Core\Controller\PushInterface;
use Jtl\Connector\Core\Controller\StatisticsInterface;
use Jtl\Connector\Core\Model\AbstractModel;
use Jtl\Connector\Core\Model\Identity;
use Jtl\Connector\Core\Model\QueryFilter;
use PDO;

/**
 * Image sync (JTL-Wawi -> CS-Cart).
 *
 * Notes:
 * - JTL uses a dedicated Image entity/controller.
 * - CS-Cart stores images as "image pairs" linked to objects (product/category).
 * - We intentionally keep this implementation pragmatic: attach/update images by URL
 *   when possible; fall back to base64/binary if present.
 */
final class ImageController implements PushInterface, StatisticsInterface
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
        $lang = (string)\fn_jtl_connector_get_addon_setting('default_language', 'en');
        $prefix = defined('TABLE_PREFIX') ? TABLE_PREFIX : 'cscart_';

        // Resolve related object (product/category)
        [$objectType, $objectId] = $this->resolveRelation($model);
        if ($objectType === null || $objectId <= 0) {
            return $model;
        }

        // CS-Cart does not scope images by vendor company_id. The objectId already
        // points to vendor-owned products in Multi-Vendor.

        $pairType = $this->isMain($model) ? 'M' : 'A';
        $position = $this->getPosition($model);
        $alt = $this->getAlt($model);

        // Determine image source
        $source = $this->extractSource($model);
        if ($source['image_url'] === null && $source['absolute_path'] === null) {
            return $model;
        }

        // We need to return a stable endpoint id for the image, so Wawi can link it.
        $endpointId = method_exists($model, 'getId') ? $model->getId()->getEndpoint() : '';
        $pairId = $endpointId !== '' ? (int)$endpointId : 0;

        $beforeMax = 0;
        if ($pairId === 0) {
            $stmt = $this->pdo->prepare('SELECT IFNULL(MAX(pair_id),0) m FROM ' . $prefix . 'images_links WHERE object_id=? AND object_type=?');
            $stmt->execute([$objectId, $objectType]);
            $beforeMax = (int)($stmt->fetch()['m'] ?? 0);
        }

        if (function_exists('fn_update_image_pairs')) {
            $pairsData = [
                0 => [
                    'pair_id' => $pairId,
                    'type' => $pairType,
                    'object_id' => $objectId,
                    'object_type' => $objectType,
                    'position' => $position,
                    'image_alt' => $alt,
                ],
            ];

            $detailed = [
                0 => [
                    'object_type' => $objectType,
                    'type' => $pairType,
                    'alt' => $alt,
                ],
            ];

            if ($source['image_url'] !== null) {
                $detailed[0]['image_url'] = $source['image_url'];
            }
            if ($source['absolute_path'] !== null) {
                $detailed[0]['absolute_path'] = $source['absolute_path'];
            }

            // icons array is unused when we provide only detailed
            \fn_update_image_pairs([], $detailed, $pairsData, $objectId, $objectType, [], true, $lang);
        } else {
            // No CS-Cart image subsystem available (shouldn't happen on real installs).
            return $model;
        }

        if ($pairId === 0) {
            $stmt = $this->pdo->prepare('SELECT IFNULL(MAX(pair_id),0) m FROM ' . $prefix . 'images_links WHERE object_id=? AND object_type=?');
            $stmt->execute([$objectId, $objectType]);
            $afterMax = (int)($stmt->fetch()['m'] ?? 0);
            if ($afterMax > $beforeMax) {
                $pairId = $afterMax;
            } elseif ($afterMax > 0) {
                $pairId = $afterMax;
            }
        }

        if ($pairId > 0 && method_exists($model, 'getId')) {
            $model->getId()->setEndpoint((string)$pairId);
        }

        return $model;
    }

    public function statistic(QueryFilter $queryFilter): int
    {
        // We don't support image.pull (only push). Statistic is irrelevant in that case.
        return 0;
    }

    /**
     * @return array{0:?string,1:int} [objectType, objectId]
     */
    private function resolveRelation(AbstractModel $model): array
    {
        // Preferred (seen in logs from other connectors): getRelationType/getRelationId
        $relationType = null;
        if (method_exists($model, 'getRelationType')) {
            $relationType = (string)$model->getRelationType();
        }

        $relationId = null;
        if (method_exists($model, 'getRelationId')) {
            $rid = $model->getRelationId();
            if ($rid instanceof Identity) {
                $relationId = (int)$rid->getEndpoint();
            }
        }

        // Fallbacks
        if ($relationId === null && method_exists($model, 'getProductId')) {
            $pid = $model->getProductId();
            if ($pid instanceof Identity) {
                $relationId = (int)$pid->getEndpoint();
                $relationType = $relationType ?: 'Product';
            }
        }
        if ($relationId === null && method_exists($model, 'getCategoryId')) {
            $cid = $model->getCategoryId();
            if ($cid instanceof Identity) {
                $relationId = (int)$cid->getEndpoint();
                $relationType = $relationType ?: 'Category';
            }
        }

        if ($relationId === null) {
            $relationId = 0;
        }

        $relationType = $relationType ?? '';
        $relationTypeLower = strtolower($relationType);

        if (str_contains($relationTypeLower, 'product')) {
            return ['product', (int)$relationId];
        }
        if (str_contains($relationTypeLower, 'category')) {
            return ['category', (int)$relationId];
        }

        return [null, 0];
    }

    private function isMain(AbstractModel $model): bool
    {
        // Many connectors treat the first image (position 0/1) as main.
        $pos = $this->getPosition($model);
        if ($pos <= 0) {
            return true;
        }
        if (method_exists($model, 'getType')) {
            $t = (string)$model->getType();
            return strtoupper($t) === 'M';
        }
        return false;
    }

    private function getPosition(AbstractModel $model): int
    {
        foreach (['getSort', 'getSortOrder', 'getPosition', 'getRank'] as $m) {
            if (method_exists($model, $m)) {
                $v = $model->{$m}();
                if (is_numeric($v)) {
                    return (int)$v;
                }
            }
        }
        return 0;
    }

    private function getAlt(AbstractModel $model): string
    {
        foreach (['getAlt', 'getAltText', 'getName', 'getFilename'] as $m) {
            if (method_exists($model, $m)) {
                $v = $model->{$m}();
                if (is_string($v) && $v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /**
     * @return array{image_url:?string, absolute_path:?string}
     */
    private function extractSource(AbstractModel $model): array
    {
        // 1) Remote URL
        foreach (['getUrl', 'getRemoteUrl', 'getImageUrl', 'getHttpUrl', 'getHttpsUrl', 'getSourceUrl'] as $m) {
            if (method_exists($model, $m)) {
                $v = $model->{$m}();
                if (is_string($v) && preg_match('~^https?://~i', $v)) {
                    return ['image_url' => $v, 'absolute_path' => null];
                }
            }
        }

        // 2) Base64 / binary payload
        $data = null;
        foreach (['getData', 'getBinary', 'getContent', 'getFileContent', 'getBase64'] as $m) {
            if (method_exists($model, $m)) {
                $v = $model->{$m}();
                if (is_string($v) && $v !== '') {
                    $data = $v;
                    break;
                }
            }
        }

        if ($data === null) {
            return ['image_url' => null, 'absolute_path' => null];
        }

        // If it's base64, decode.
        $bin = $data;
        if (preg_match('~^[A-Za-z0-9+/=\r\n]+$~', $data) && strlen($data) > 128) {
            $decoded = base64_decode($data, true);
            if ($decoded !== false) {
                $bin = $decoded;
            }
        }

        $tmp = sys_get_temp_dir() . '/jtl_img_' . bin2hex(random_bytes(8));
        // Try to infer extension
        $ext = 'jpg';
        if (str_starts_with($bin, "\x89PNG")) {
            $ext = 'png';
        } elseif (str_starts_with($bin, "GIF8")) {
            $ext = 'gif';
        } elseif (str_contains(substr($bin, 0, 64), 'WEBP')) {
            $ext = 'webp';
        }
        $path = $tmp . '.' . $ext;
        @file_put_contents($path, $bin);

        if (is_file($path)) {
            return ['image_url' => null, 'absolute_path' => $path];
        }

        return ['image_url' => null, 'absolute_path' => null];
    }
}
