<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Model;

use Magento\Integration\Api\AuthorizationServiceInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Order status and returns: integration "FAMMRO" with exactly the permissions needed
 *
 * View orders and add order comments, plus "All Stores" for the connection test. The access is reported signed to
 * FAMMRO, which stores it only after a signed test request. The merchant sees and revokes the integration under
 * System > Integrations.
 */
class ApiAccess
{
    public const NAME = 'FAMMRO';
    public const RESOURCES = ['Magento_Sales::actions_view', 'Magento_Sales::comment', 'Magento_Backend::store'];

    /**
     * @var IntegrationServiceInterface
     */
    private $integrations;

    /**
     * @var OauthServiceInterface
     */
    private $oauth;

    /**
     * @var AuthorizationServiceInterface
     */
    private $authorization;

    /**
     * @var StoreManagerInterface
     */
    private $stores;

    /**
     * @var Connect
     */
    private $connect;

    /**
     * @param IntegrationServiceInterface $integrations
     * @param OauthServiceInterface $oauth
     * @param AuthorizationServiceInterface $authorization
     * @param StoreManagerInterface $stores
     * @param Connect $connect
     */
    public function __construct(
        IntegrationServiceInterface $integrations,
        OauthServiceInterface $oauth,
        AuthorizationServiceInterface $authorization,
        StoreManagerInterface $stores,
        Connect $connect
    ) {
        $this->integrations = $integrations;
        $this->oauth = $oauth;
        $this->authorization = $authorization;
        $this->stores = $stores;
        $this->connect = $connect;
    }

    /**
     * Create or refresh the integration and report the access to FAMMRO
     *
     * @param string $installationId
     * @param string $secretHex
     * @return bool true when FAMMRO verified and stored the access
     */
    public function grantAndReport(string $installationId, string $secretHex): bool
    {
        $integration = $this->integrations->findByName(self::NAME);
        if (!$integration->getId()) {
            $integration = $this->integrations->create([
                'name' => self::NAME,
                'email' => '',
                'endpoint' => '',
                'identity_link_url' => '',
                'status' => Integration::STATUS_ACTIVE,
                'setup_type' => Integration::TYPE_MANUAL,
            ]);
        }
        $this->authorization->grantPermissions((int) $integration->getId(), self::RESOURCES);
        $consumerId = (int) $integration->getConsumerId();
        // Every click creates a new access; the previous one stops working
        $this->oauth->createAccessToken($consumerId, true);
        $token = $this->oauth->getAccessToken($consumerId);
        $consumer = $this->oauth->loadConsumer($consumerId);
        if (!$token || !$consumer->getId()) {
            return false;
        }
        $integration->setStatus(Integration::STATUS_ACTIVE)->save();
        $status = $this->connect->signedPost('/api/connect/v1/magento/credentials', [
            'baseUrl' => rtrim((string) $this->stores->getStore()->getBaseUrl(), '/'),
            'consumerKey' => (string) $consumer->getKey(),
            'consumerSecret' => (string) $consumer->getSecret(),
            'accessToken' => (string) $token->getToken(),
            'accessTokenSecret' => (string) $token->getSecret(),
        ], $installationId, $secretHex);
        return $status === 200;
    }

    /**
     * Remove the integration and with it the access (uninstall)
     *
     * @return void
     */
    public function remove(): void
    {
        $integration = $this->integrations->findByName(self::NAME);
        if ($integration->getId()) {
            $this->integrations->delete((int) $integration->getId());
        }
    }
}
