<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */
declare(strict_types=1);

namespace Zero\Staging\Model\Preview;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Url;

/**
 * The admin's link into a preview, and the cookie the storefront keeps it
 * in: the moment shown, the campaign it was opened from, until when it
 * works, a random nonce, signed with the installation's key. Whoever has
 * the link sees the store as of that moment for an hour; nothing else.
 */
class Link
{
    public const LIFETIME = 3600;

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Url $frontendUrl
    ) {
    }

    /**
     * @param string $at UTC "Y-m-d H:i:s"
     */
    public function url(int $storeId, string $at, int $campaignId, ?int $now = null): string
    {
        $payload = [
            'at' => strtotime($at . ' UTC'),
            'campaign' => $campaignId,
            'expires' => ($now ?? time()) + self::LIFETIME,
            'nonce' => bin2hex(random_bytes(8)),
        ];
        return $this->frontendUrl->setScope($storeId)->getUrl('zero_staging/preview/start', [
            '_nosid' => true,
            '_query' => ['p' => $this->encode($payload)],
        ]);
    }

    /**
     * The signed value (for the link's query and the cookie).
     *
     * @param array{at: int, campaign: int, expires: int, nonce: string} $payload
     */
    public function encode(array $payload): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
        return $body . '.' . $this->sign($body);
    }

    /**
     * @return array{at: int, campaign: int, expires: int, nonce: string}|null Null: forged, broken or expired
     */
    public function decode(string $value, ?int $now = null): ?array
    {
        [$body, $signature] = array_pad(explode('.', $value, 2), 2, '');
        if ($body === '' || !hash_equals($this->sign($body), $signature)) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        $now ??= time();
        if (!is_array($payload) || !isset($payload['at'], $payload['expires'], $payload['nonce'])
            || (int) $payload['expires'] < $now || (int) $payload['expires'] > $now + self::LIFETIME
        ) {
            return null;
        }
        return [
            'at' => (int) $payload['at'],
            'campaign' => (int) ($payload['campaign'] ?? 0),
            'expires' => (int) $payload['expires'],
            'nonce' => (string) $payload['nonce'],
        ];
    }

    private function sign(string $body): string
    {
        $key = (string) $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY);
        return hash_hmac('sha256', 'zero-staging|' . $body, 'zero-staging-preview|' . $key);
    }
}
