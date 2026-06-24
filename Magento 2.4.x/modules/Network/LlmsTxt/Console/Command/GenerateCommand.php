<?php
declare(strict_types=1);

namespace Network\LlmsTxt\Console\Command;

use Network\LlmsTxt\Model\Generator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    /** @var Generator */
    private $generator;

    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('network:llmstxt:generate')
             ->setDescription('Generuje plik llms.txt dla każdego aktywnego store view');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Generowanie llms.txt...</info>');

        try {
            $messages = $this->generator->execute();

            foreach ($messages as $msg) {
                $isError = mb_strpos($msg, '✗') === 0;
                $line    = $isError
                    ? '<error>' . $msg . '</error>'
                    : '<comment>✓ </comment>' . $msg;
                $output->writeln($line);
            }

            $output->writeln('<info>Gotowe.</info>');
            return 0; 

        } catch (\Throwable $e) {
            $output->writeln('<error>Błąd: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }
}