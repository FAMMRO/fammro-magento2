<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;

/**
 * Connection to FAMMRO: PKCE link, code exchange with a fresh Ed25519 key pair, signed reports.
 *
 * The private key never leaves the shop. Requests only go to the fixed FAMMRO hosts below.
 */
class Connect
{
    public const BACKEND = 'https://ormmaf.fammro.com';
    public const AUTHORIZE_URL = 'https://fammro.com/connect/authorize';
    public const VERSION = '1.2.0';

    /** DER prefix of an Ed25519 SubjectPublicKeyInfo */
    private const SPKI_PREFIX = '302a300506032b6570032100';

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var string
     */
    private $backend;

    /**
     * @var string
     */
    private $authorizeUrl;

    /**
     * Addresses are fixed; di.xml can point a local test shop to a local FAMMRO backend
     *
     * @param CurlFactory $curlFactory
     * @param LoggerInterface $logger
     * @param string $backend
     * @param string $authorizeUrl
     */
    public function __construct(
        CurlFactory $curlFactory,
        LoggerInterface $logger,
        string $backend = self::BACKEND,
        string $authorizeUrl = self::AUTHORIZE_URL
    ) {
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
        $this->backend = rtrim($backend, '/');
        $this->authorizeUrl = $authorizeUrl;
    }

    /**
     * Create a PKCE verifier
     *
     * @return string
     */
    public function createVerifier(): string
    {
        return $this->base64Url(random_bytes(32));
    }

    /**
     * Link to the FAMMRO approval page; empty when the shop or the return address is not https on the same host
     *
     * @param string $site
     * @param string $redirectUri
     * @param string $state
     * @param string $verifier
     * @return string
     */
    public function authorizeUrl(string $site, string $redirectUri, string $state, string $verifier): string
    {
        if (!$this->isHttps($site) || !$this->isHttps($redirectUri)) {
            return '';
        }
        if ($this->origin($site) !== $this->origin($redirectUri)) {
            return '';
        }
        return $this->authorizeUrl . '?' . http_build_query([
            'platform' => 'magento',
            'site' => $site,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $this->base64Url(hash('sha256', $verifier, true)),
            'state' => $state,
        ]);
    }

    /**
     * Exchange the one-time code for the installation; the shop keeps the private key
     *
     * @param string $code
     * @param string $verifier
     * @return array{widgetKey: string, installationId: string, secret: string}|null secret: Ed25519 key as hex
     */
    public function exchange(string $code, string $verifier): ?array
    {
        $pair = sodium_crypto_sign_keypair();
        $payload = json_encode([
            'code' => $code,
            'codeVerifier' => $verifier,
            'publicKey' => base64_encode(hex2bin(self::SPKI_PREFIX) . sodium_crypto_sign_publickey($pair)),
            'pluginVersion' => self::VERSION,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $raw] = $this->post('/api/connect/v1/token', (string) $payload, [], 15);
        $body = json_decode($raw, true);
        if ($status !== 200 || !is_array($body) || empty($body['widgetKey']) || empty($body['installationId'])) {
            return null;
        }
        return [
            'widgetKey' => (string) $body['widgetKey'],
            'installationId' => (string) $body['installationId'],
            'secret' => sodium_bin2hex(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Signed report over the installation channel (Ed25519)
     *
     * Canonical string as in the FAMMRO backend: v1, method, path, timestamp (ms), nonce, SHA-256 of the body.
     * The body is encoded like JSON.stringify (slashes and unicode unescaped).
     *
     * @param string $path
     * @param array $body
     * @param string $installationId
     * @param string $secretHex
     * @return int HTTP status, 0 when nothing was sent
     */
    public function signedPost(string $path, array $body, string $installationId, string $secretHex): int
    {
        if ($installationId === '' || !preg_match('/^[0-9a-f]{128}$/', $secretHex)) {
            return 0;
        }
        $secret = sodium_hex2bin($secretHex);
        $raw = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = sprintf('%.0f', microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $canonical = implode("\n", ['v1', 'POST', $path, $timestamp, $nonce, hash('sha256', $raw)]);
        $signature = base64_encode(sodium_crypto_sign_detached($canonical, $secret));
        [$status] = $this->post($path, $raw, [
            'X-FAMMRO-Installation' => $installationId,
            'X-FAMMRO-Timestamp' => $timestamp,
            'X-FAMMRO-Nonce' => $nonce,
            'X-FAMMRO-Signature' => $signature,
        ], 20);
        return $status;
    }

    /**
     * POST JSON to the FAMMRO backend
     *
     * @param string $path
     * @param string $raw
     * @param array $headers
     * @param int $timeout
     * @return array{0: int, 1: string}
     */
    private function post(string $path, string $raw, array $headers, int $timeout): array
    {
        $client = $this->curlFactory->create();
        $client->setTimeout($timeout);
        $client->setHeaders(['Content-Type' => 'application/json'] + $headers);
        try {
            $client->post($this->backend . $path, $raw);
            return [(int) $client->getStatus(), (string) $client->getBody()];
        } catch (\Exception $e) {
            $this->logger->warning('FAMMRO: request failed', ['path' => $path, 'error' => $e->getMessage()]);
            return [0, ''];
        }
    }

    /**
     * Base64 URL encoding without padding
     *
     * @param string $bytes
     * @return string
     */
    private function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Whether the address uses https
     *
     * @param string $url
     * @return bool
     */
    private function isHttps(string $url): bool
    {
        return strpos($url, 'https://') === 0;
    }

    /**
     * Scheme and host of an address (no port, no path)
     *
     * @param string $url
     * @return string
     */
    private function origin(string $url): string
    {
        $rest = substr($url, strlen('https://'));
        $host = strtolower((string) strtok($rest, '/?#'));
        return 'https://' . preg_replace('/:\d+$/', '', $host);
    }
}
