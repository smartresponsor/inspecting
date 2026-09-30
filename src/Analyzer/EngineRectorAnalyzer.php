<?php

declare(strict_types=1);

namespace App\Inspecting\Analyzer;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\ValueObject\EngineFinding;
use App\Inspecting\Writer\EngineRawReportWriter;
use Symfony\Component\Process\Process;

/**
 * Runs Rector in dry-run mode and converts proposed source transformations into normalized modernization findings and raw evidence.
 */
final readonly class EngineRectorAnalyzer implements EngineAnalyzerInterface
{
    public function __construct(private EngineRawReportWriter $rawReportWriter = new EngineRawReportWriter()) {}

    /**
 * Returns the stable analyzer identifier used for selection, metric namespacing, failure reporting, and normalized report output.
 */
    public function name(): string
    {
        return 'rector';
    }

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 */
    public function inspect(string $target): EngineAnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $binary = $target . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'rector';
        $config = $target . DIRECTORY_SEPARATOR . 'rector.php';

        if (!is_file($binary) || !is_file($config)) {
            throw new EngineAnalyzerUnavailable(sprintf('Rector is not configured in %s.', $target));
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

    private function parse(string $json, string $rawReport): EngineAnalyzerResult
    {
        $data = $this->decode($json);

        return new EngineAnalyzerResult(
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
     * @return list<EngineFinding>
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

    private function finding(string $file, string $rector): EngineFinding
    {
        return new EngineFinding(
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
