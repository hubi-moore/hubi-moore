<?php
namespace Network\AskForProductHyva\Model;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Mail
{
    protected $transportBuilder;
    protected $scopeConfig;
    protected $storeManager;

    public function __construct(
        TransportBuilder $transportBuilder,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->transportBuilder = $transportBuilder;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    public function send($recipient, $postData)
    {
        if (!$recipient) {
            throw new \InvalidArgumentException('Recipient email is required');
        }

        $store = $this->storeManager->getStore()->getId();
        $transport = $this->transportBuilder
            ->setTemplateIdentifier('askforproduct_email_template')
            ->setTemplateOptions(['area' => 'frontend', 'store' => $store])
            ->setTemplateVars($postData)
            ->setFromByScope('general', $store)
            ->addTo($recipient)
            ->getTransport();

        $transport->sendMessage();
    }
}
