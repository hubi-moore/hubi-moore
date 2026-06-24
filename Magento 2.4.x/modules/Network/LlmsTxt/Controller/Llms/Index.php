<?php
declare(strict_types=1);

namespace Network\LlmsTxt\Controller\Llms;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RawFactory;
use Network\LlmsTxt\Model\Generator;

/**
 * Serwuje /llms.txt z nagłówkiem Content-Type: text/plain; charset=utf-8
 * — identyczne zachowanie jak Magento_Robots dla /robots.txt
 */
class Index extends Action
{
    /** @var RawFactory */
    private $resultRawFactory;

    /** @var Generator */
    private $generator;

    public function __construct(
        Context    $context,
        RawFactory $resultRawFactory,
        Generator  $generator
    ) {
        parent::__construct($context);
        $this->resultRawFactory = $resultRawFactory;
        $this->generator        = $generator;
    }

    public function execute()
    {
        $result = $this->resultRawFactory->create();
        $result->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $result->setContents($this->generator->getContent());
        return $result;
    }
}