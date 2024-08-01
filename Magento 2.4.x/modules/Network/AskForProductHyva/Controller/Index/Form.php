<?php
namespace Network\AskForProductHyva\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Form extends Action
{
    /**
     * @var PageFactory
     */
    protected $resultPageFactory;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param Context $context
     * @param PageFactory $resultPageFactory
     */
    public function __construct(Context $context, PageFactory $resultPageFactory, ScopeConfigInterface $scopeConfig)
    {
        $this->resultPageFactory = $resultPageFactory;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|ResultInterface|Page
     */
    public function execute()
    {
        if (!$this->isModuleEnabled()) {
            return $this->resultPageFactory->create();
        }
    }

    public function isModuleEnabled()
    {
        return $this->scopeConfig->getValue(
            'askforproduct/general/enabled',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
