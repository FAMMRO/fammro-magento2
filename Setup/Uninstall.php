<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Setup;

use Fammro\Integration\Model\ApiAccess;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;

/**
 * Bin/magento module:uninstall Fammro_Integration: remove the FAMMRO access and every stored setting
 */
class Uninstall implements UninstallInterface
{
    /**
     * @var ApiAccess
     */
    private $apiAccess;

    /**
     * @param ApiAccess $apiAccess
     */
    public function __construct(ApiAccess $apiAccess)
    {
        $this->apiAccess = $apiAccess;
    }

    /**
     * Remove the integration and all fammro_integration/* configuration values (incl. the encrypted shop key)
     *
     * @param SchemaSetupInterface $setup
     * @param ModuleContextInterface $context
     * @return void
     */
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $this->apiAccess->remove();
        $connection = $setup->getConnection();
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'fammro_integration/%']);
    }
}
