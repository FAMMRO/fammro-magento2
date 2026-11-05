<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Block\Adminhtml;

use Magento\Backend\Block\Template;

/**
 * FAMMRO card in the admin: status and connect button
 */
class Connect extends Template
{
    private const PATH = 'fammro_integration/widget/';

    /**
     * Whether the shop is connected to FAMMRO
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return (string) $this->_scopeConfig->getValue(self::PATH . 'installation_id') !== '';
    }

    /**
     * Whether order status and returns are connected
     *
     * @return bool
     */
    public function isOrdersConnected(): bool
    {
        return (string) $this->_scopeConfig->getValue(self::PATH . 'orders_connected') === '1';
    }

    /**
     * Link to the FAMMRO approval page, empty when the shop is not on https
     *
     * @return string
     */
    public function getConnectUrl(): string
    {
        return (string) $this->getData('connect_url');
    }

    /**
     * Logo shipped with the module (no request to FAMMRO before connecting)
     *
     * @return string
     */
    public function getLogoUrl(): string
    {
        return $this->getViewFileUrl('Fammro_Integration::images/fammro-logo.png');
    }
}
