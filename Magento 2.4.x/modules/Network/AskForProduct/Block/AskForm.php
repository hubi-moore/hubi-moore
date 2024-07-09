<?php
namespace Network\AskForProduct\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\Registry;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;

class AskForm extends Template
{
    /**
     * @var ProductRepository
     */
    protected $productRepository;
    /**
     * @var Registry
     */
    protected $registry;
    /**
     * @var CustomerSession
     */
    protected $customerSession;
    /**
     * @var ScopeConfigInterface
     */
    protected  $scopeConfig;
    /**
     * @var UrlInterface
     */
    protected $urlBuilder;

    /**
     * @param Context $context
     * @param ProductRepository $productRepository
     * @param Registry $registry
     * @param CustomerSession $customerSession
     * @param ScopeConfigInterface $scopeConfig
     * @param UrlInterface $urlBuilder
     * @param array $data
     */
    public function __construct(
        Context $context,
        ProductRepository $productRepository,
        Registry $registry,
        CustomerSession $customerSession,
        ScopeConfigInterface $scopeConfig,
        UrlInterface $urlBuilder,
        array $data = []
    ) {
        $this->productRepository = $productRepository;
        $this->registry = $registry;
        $this->customerSession = $customerSession;
        $this->scopeConfig = $scopeConfig;
        $this->urlBuilder = $urlBuilder;
        parent::__construct($context, $data);
    }

    /**
     * @return mixed|null
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }

    /**
     * @return string
     */
    public function getCustomerName()
    {
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            return $customer->getFirstname() . ' ' . $customer->getLastname();
        }
        return '';
    }

    /**
     * @return string
     */
    public function getCustomerEmail()
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->customerSession->getCustomer()->getEmail();
        }
        return '';
    }

    /**
     * @return string
     */
    public function getFormAction()
    {
        return $this->getUrl('askforproduct/index/submit', ['_secure' => true]);
    }

    /**
     * @return false|string
     */
    public function getLogoImage()
    {
        $logo = $this->scopeConfig->getValue('askforproduct/general/image', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        if ($logo) {
            return $this->urlBuilder->getBaseUrl(['_type' => UrlInterface::URL_TYPE_MEDIA]) . 'askforproduct/logo/' . $logo;
        }
        return false;
    }

    /**
     * @return mixed
     */
    public function getHeaderText()
    {
        return $this->scopeConfig->getValue('askforproduct/general/header_text', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getSubtext()
    {
        return $this->scopeConfig->getValue('askforproduct/general/subtext', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }

    /**
     * @return mixed
     */
    public function getRecaptchaSiteKey()
    {
        return $this->scopeConfig->getValue('google/recaptcha/frontend/type', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
    }
}
