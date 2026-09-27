<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\Finding;
use App\Inspection\RawReportWriter;
use Symfony\Component\Process\Process;

final readonly class RectorAnalyzer implements AnalyzerInterface
{
    public function __construct(private RawReportWriter $rawReportWriter = new RawReportWriter()) {}

    public function name(): string
    {
        return 'rector';
    }

    public function inspect(string $target): AnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $binary = $target . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'rector';
        $config = $target . DIRECTORY_SEPARATOR . 'rector.php';

        if (!is_file($binary) || !is_file($config)) {
            throw new AnalyzerUnavailable(sprintf('Rector is not configured in %s.', $target));
        }

        $process = new Process([
            PHP_BINARY,
            $binary,
            'process',
            '--dry-run',
            '--output-format=json',
            '--no-progress-bar',
            '--config=' . $config,
        ], $target);
        $process->setTimeout(300.0);
        $process->run();

        if (!in_array($process->getExitCode(), [0, 2], true)) {
            throw new \RuntimeException(sprintf(
                'Rector failed with exit code %d: %s',
                $process->getExitCode(),
                trim($process->getErrorOutput() . PHP_EOL . $process->getOutput()),
            ));
        }

        $output = $process->getOutput();
        $rawReport = $this->rawReportWriter->write($target, $this->name(), $output);

        return $this->parse($output, $rawReport);
    }

    private function parse(string $json, string $rawReport): AnalyzerResult
    {
        $data = $this->decode($json);

        return new AnalyzerResult(
            analyzer: $this->name(),
            findings: $this->parseFindings($data),
            metrics: $this->parseTotals($data),
            rawReportReferences: [$rawReport],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \UnexpectedValueException('Rector JSON output must decode to an object.');
        }

        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Rector JSON root keys must be strings.');
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<Finding>
     */
    private function parseFindings(array $data): array
    {
        $fileDiffs = $data['file_diffs'] ?? [];
        if (!is_array($fileDiffs)) {
            return [];
        }

        $findings = [];

        foreach ($fileDiffs as $fileDiff) {
            if (!is_array($fileDiff)) {
                continue;
            }

            $file = $fileDiff['file'] ?? null;
            $rectors = $fileDiff['applied_rectors'] ?? [];
            if (!is_string($file) || !is_array($rectors)) {
                continue;
            }

            foreach ($rectors as $rector) {
                if (is_string($rector)) {
                    $findings[] = $this->finding($file, $rector);
                }
            }
        }

        return $findings;
    }

    private function finding(string $file, string $rector): Finding
    {
        return new Finding(
            source: $this->name(),
            rule: $rector,
            category: 'modernization',
            severity: 'medium',
            confidence: 1.0,
            message: sprintf('Rector would refactor %s using %s.', $file, $rector),
            file: $file,
            autofixable: true,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{changedFiles:int, errors:int}
     */
    private function parseTotals(array $data): array
    {
        $totals = $data['totals'] ?? [];

        return [
            'changedFiles' => is_array($totals) && is_int($totals['changed_files'] ?? null)
                ? $totals['changed_files']
                : 0,
            'errors' => is_array($totals) && is_int($totals['errors'] ?? null)
                ? $totals['errors']
                : 0,
        ];
    }
}
