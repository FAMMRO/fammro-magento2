<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Controller\Adminhtml\Connect;

use Fammro\Integration\Model\ApiAccess;
use Fammro\Integration\Model\Connect;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * FAMMRO page in the admin: connect button, return from the FAMMRO approval page
 */
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'Fammro_Integration::config';

    private const PATH = 'fammro_integration/widget/';
    private const SESSION_STATE = 'fammro_state';
    private const SESSION_VERIFIER = 'fammro_pkce';

    /**
     * @var PageFactory
     */
    private $pageFactory;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var ReinitableConfigInterface
     */
    private $config;

    /**
     * @var TypeListInterface
     */
    private $cacheTypes;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var StoreManagerInterface
     */
    private $stores;

    /**
     * @var Connect
     */
    private $connect;

    /**
     * @var ApiAccess
     */
    private $apiAccess;

    /**
     * @param Context $context
     * @param PageFactory $pageFactory
     * @param WriterInterface $configWriter
     * @param ReinitableConfigInterface $config
     * @param TypeListInterface $cacheTypes
     * @param EncryptorInterface $encryptor
     * @param StoreManagerInterface $stores
     * @param Connect $connect
     * @param ApiAccess $apiAccess
     */
    public function __construct(
        Context $context,
        PageFactory $pageFactory,
        WriterInterface $configWriter,
        ReinitableConfigInterface $config,
        TypeListInterface $cacheTypes,
        EncryptorInterface $encryptor,
        StoreManagerInterface $stores,
        Connect $connect,
        ApiAccess $apiAccess
    ) {
        parent::__construct($context);
        $this->pageFactory = $pageFactory;
        $this->configWriter = $configWriter;
        $this->config = $config;
        $this->cacheTypes = $cacheTypes;
        $this->encryptor = $encryptor;
        $this->stores = $stores;
        $this->connect = $connect;
        $this->apiAccess = $apiAccess;
    }

    /**
     * Show the page; on return from FAMMRO finish the connection once and reload without the code
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $code = (string) $this->getRequest()->getParam('code');
        if ($code !== '') {
            $state = (string) $this->_session->getData(self::SESSION_STATE);
            $verifier = (string) $this->_session->getData(self::SESSION_VERIFIER);
            $returned = (string) $this->getRequest()->getParam('state');
            // One attempt per state: a reload or a second tab cannot reuse it
            $this->_session->unsetData(self::SESSION_STATE);
            $this->_session->unsetData(self::SESSION_VERIFIER);
            if ($state !== '' && $verifier !== '' && hash_equals($state, $returned)) {
                $this->finish($code, $verifier);
            } else {
                $this->messageManager->addErrorMessage(__('The link has expired. Please click Connect again.'));
            }
            return $this->resultRedirectFactory->create()->setPath('fammro/connect/index');
        }

        $verifier = $this->connect->createVerifier();
        $state = bin2hex(random_bytes(16));
        $this->_session->setData(self::SESSION_VERIFIER, $verifier);
        $this->_session->setData(self::SESSION_STATE, $state);
        $site = (string) $this->stores->getStore()->getBaseUrl();
        $href = $this->connect->authorizeUrl($site, $this->getUrl('fammro/connect/index'), $state, $verifier);

        $page = $this->pageFactory->create();
        $page->setActiveMenu('Fammro_Integration::connect');
        $page->getConfig()->getTitle()->prepend(__('FAMMRO'));
        $block = $page->getLayout()->getBlock('fammro.connect');
        if ($block) {
            $block->setData('connect_url', $href);
        }
        return $page;
    }

    /**
     * Exchange the code, store the installation, create the order access and refresh the config cache
     *
     * @param string $code
     * @param string $verifier
     * @return void
     */
    private function finish(string $code, string $verifier): void
    {
        $result = $this->connect->exchange($code, $verifier);
        if (!$result) {
            $this->messageManager->addErrorMessage(__('FAMMRO did not accept the connection. Please try again.'));
            return;
        }
        // The widget key is public (it is part of every shop page); the shop key is stored encrypted
        $this->configWriter->save(self::PATH . 'api_key', $result['widgetKey']);
        $this->configWriter->save(self::PATH . 'installation_id', $result['installationId']);
        $this->configWriter->save(self::PATH . 'secret', $this->encryptor->encrypt($result['secret']));
        $this->configWriter->save(self::PATH . 'enabled', '1');
        $orders = $this->apiAccess->grantAndReport($result['installationId'], $result['secret']);
        $this->configWriter->save(self::PATH . 'orders_connected', $orders ? '1' : '0');
        $this->config->reinit();
        // The chat window is part of every page: refresh config and page cache so it appears right away
        $this->cacheTypes->cleanType('config');
        $this->cacheTypes->cleanType('full_page');
        if ($orders) {
            $this->messageManager->addSuccessMessage(
                __('Connected. Chat window, order status and returns now run through FAMMRO.')
            );
        } else {
            $this->messageManager->addWarningMessage(
                __('Chat window connected. Order status is not connected yet, please click Connect again.')
            );
        }
    }
}
