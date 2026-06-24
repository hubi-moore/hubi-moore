<?php
declare(strict_types=1);

namespace Network\LlmsTxt\Controller\Router;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;

/**
 * Przechwytuje GET /llms.txt i GET /llms-full.txt
 * i kieruje do odpowiednich kontrolerów.
 */
class LlmsTxt implements RouterInterface
{
    private $actionFactory;

    public function __construct(ActionFactory $actionFactory)
    {
        $this->actionFactory = $actionFactory;
    }

    public function match(RequestInterface $request)
    {
        $path = trim($request->getPathInfo(), '/');

        if ($path === 'llms.txt') {
            $request->setModuleName('network_llmstxt')
                    ->setControllerName('llms')
                    ->setActionName('index');
            return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
        }

        if ($path === 'llms-full.txt') {
            $request->setModuleName('network_llmstxt')
                    ->setControllerName('llms')
                    ->setActionName('full');
            return $this->actionFactory->create(\Magento\Framework\App\Action\Forward::class);
        }

        return false;
    }
}