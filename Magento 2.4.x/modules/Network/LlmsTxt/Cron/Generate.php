<?php
declare(strict_types=1);

namespace Network\LlmsTxt\Cron;

use Network\LlmsTxt\Model\Generator;

class Generate
{
    /** @var Generator */
    private $generator;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    public function execute(): void
    {
        $this->generator->execute();
    }
}