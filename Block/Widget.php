<?php
/**
 * Copyright © FAMMRO. All rights reserved.
 */
declare(strict_types=1);

namespace Fammro\Integration\Block;

use Fammro\Integration\Model\Connect;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\View\Element\Template;
use Magento\Store\Model\ScopeInterface;

/**
 * Chat window: local loader with the public widget key of this store view
 */
class Widget extends Template
{
    private const XML_ENABLED = 'fammro_integration/widget/enabled';
    private const XML_API_KEY = 'fammro_integration/widget/api_key';

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param Template\Context $context
     * @param EncryptorInterface $encryptor
     * @param array $data
     */
    public function __construct(
        Template\Context $context,
        EncryptorInterface $encryptor,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the chat window is switched on for this store view
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->_scopeConfig->isSetFlag(self::XML_ENABLED, ScopeInterface::SCOPE_STORE);
    }

    /**
     * Public widget key (fam_live_…); module 1.1.0 stored it encrypted
     *
     * @return string
     */
    public function getApiKey(): string
    {
        $value = trim((string) $this->_scopeConfig->getValue(self::XML_API_KEY, ScopeInterface::SCOPE_STORE));
        if (preg_match('/^\d+:\d+:/', $value)) {
            $value = trim((string) $this->encryptor->decrypt($value));
        }
        return preg_match('/^fam_live_[A-Za-z0-9_-]{8,200}$/', $value) ? $value : '';
    }

    /**
     * FAMMRO backend for the loader
     *
     * @return string
     */
    public function getBackendUrl(): string
    {
        return Connect::BACKEND;
    }
}
