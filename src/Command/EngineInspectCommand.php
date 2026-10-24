<?php

declare(strict_types=1);

namespace App\Inspecting\Command;

use App\Inspecting\Inspector\EngineInspector;
use App\Inspecting\Writer\EngineReportWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'inspect', description: 'Inspect a PHP repository and emit a normalized JSON report.')]
/**
 * Provides the primary Inspecting CLI workflow for analyzer selection, repository inspection, normalized report persistence, and JSON output.
 */
final class EngineInspectCommand extends Command
{
    public function __construct(
        private readonly EngineInspector $inspector,
        private readonly EngineReportWriter $reportWriter = new EngineReportWriter(),
    ) {
        parent::__construct();
    }

    /**
 * Configures the command name, arguments, options, description, and help metadata exposed through Symfony Console.
 */
    protected function configure(): void
    {
        $this->addArgument('target', InputArgument::REQUIRED, 'Repository path to inspect.');
        $this->addOption(
            'analyzer',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Run only the named analyzer. Repeat the option to select multiple analyzers.',
        );
    }

    /**
 * Executes the configured console workflow and returns the Symfony command exit status produced by the inspection or reporting operation.
 */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $target = $input->getArgument('target');

        if (!is_string($target) || '' === trim($target)) {
            throw new \InvalidArgumentException('Inspection target must be a non-empty path.');
        }

        $selectedAnalyzers = $input->getOption('analyzer');
        if (!is_array($selectedAnalyzers) || !array_is_list($selectedAnalyzers)) {
            throw new \InvalidArgumentException('Analyzer selection must be a list.');
        }
        $selectedAnalyzers = array_values(array_filter(
            $selectedAnalyzers,
            static fn(mixed $value): bool => is_string($value) && '' !== trim($value),
        ));

        $report = $this->inspector->inspect($target, $selectedAnalyzers);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $reportPath = $this->reportWriter->write($report);

        $output->writeln($json);
        $output->writeln(sprintf('Report written: %s', $reportPath));

        if ([] === $report->analyzers) {
            $output->writeln('<error>Inspection incomplete: no analyzers executed successfully.</error>');

            return Command::FAILURE;
        }

        if ([] !== $report->analyzerFailures) {
            $output->writeln('<error>Inspection incomplete: one or more analyzers failed.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
