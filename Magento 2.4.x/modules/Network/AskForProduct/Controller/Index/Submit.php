<?php
namespace Network\AskForProduct\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Network\AskForProduct\Model\Mail;
use Magento\Framework\App\Config\ScopeConfigInterface;

class Submit extends Action
{
    /**
     * @var JsonFactory
     */
    protected $jsonFactory;
    /**
     * @var Mail
     */
    protected $mail;
    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Mail $mail
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        Mail $mail,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->jsonFactory = $jsonFactory;
        $this->mail = $mail;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    /**
     * @return ResponseInterface|Json|ResultInterface
     */
    public function execute()
    {
        $result = $this->jsonFactory->create();
        $params = $this->getRequest()->getParams();
        $status = 0;

        if ($this->getRequest()->isAjax() && !empty($params)) {

            $status = 1;
            if (!empty($params['isimportant'])) {
                $result->setData(['success' => false, 'status' => $status, 'message' => __('Invalid request.')]);
                return $result;
            }

            $status = 2;
            $recipient = $this->scopeConfig->getValue('askforproduct/general/email', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if (!$recipient) {
                $result->setData(['success' => false, 'status' => $status, 'message' => __('Email recipient is not configured.')]);
                return $result;
            }

            $errors = [];
            $status = 3;

            if (empty($params['name'])) {
                $errors[] = __('Name is required.');
            }

            if (empty($params['email']) || !filter_var($params['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('A valid email is required.');
            }

            if (empty($params['message'])) {
                $errors[] = __('Message is required.');
            }

            if (!empty($errors)) {
                $result->setData(['success' => false, 'status' => $status, 'message' => implode(' ', $errors)]);
                return $result;
            }
            $status = 4;
            $postData = [
                'name' => $params['name'],
                'email' => $params['email'],
                'sku' => $params['sku'],
                'product_link' => $params['product_link'],
                'message' => $params['message'],
            ];

            try {
                $this->mail->send($recipient, $postData);
                $result->setData(['success' => true, 'status' => $status, 'message' => __('Your inquiry has been sent successfully.')]);
            } catch (\Exception $e) {
                $status = 5;
                $result->setData(['success' => false, 'status' => $status, 'message' => __('An error occurred while sending your inquiry. Please try again later.')]);
            }
        } else {
            $result->setData(['success' => false, 'status' => $status, 'message' => __('Invalid request.')]);
        }

        return $result;
    }
}
