<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'report', description: 'Read a persisted inspection report and emit its compact summary.')]
final class ReportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('report', InputArgument::REQUIRED, 'Path to a persisted inspection JSON report.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('report');

        if (!is_string($path) || '' === trim($path)) {
            throw new \InvalidArgumentException('Report path must be a non-empty string.');
        }

        if (!is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Inspection report does not exist: %s', $path));
        }

        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new \RuntimeException(sprintf('Unable to read inspection report: %s', $path));
        }

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \UnexpectedValueException('Inspection report must decode to an object.');
        }

        $summary = $data['summary'] ?? null;

        if (!is_array($summary)) {
            throw new \UnexpectedValueException('Inspection report does not contain a summary object.');
        }

        $output->writeln(json_encode([
            'report' => $path,
            'target' => $data['target'] ?? null,
            'summary' => $summary,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
