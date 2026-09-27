<?php

declare(strict_types=1);

namespace App\Command;

use App\Inspection\Inspector;
use App\Inspection\ReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'inspect', description: 'Inspect a PHP repository and emit a normalized JSON report.')]
final class InspectCommand extends Command
{
    public function __construct(
        private readonly Inspector $inspector,
        private readonly ReportWriter $reportWriter = new ReportWriter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Repository path to inspect.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');

        if (!is_string($target) || '' === trim($target)) {
            throw new \InvalidArgumentException('Inspection target must be a non-empty path.');
        }

        $report = $this->inspector->inspect($target);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reportPath = $this->reportWriter->write($report);

        $output->writeln($json);
        $output->writeln(sprintf('Report written: %s', $reportPath));

        return Command::SUCCESS;
    }
}
