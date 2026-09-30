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
 * Runs PHPStan against a target repository and normalizes its JSON diagnostics into Inspecting findings, metrics, and raw evidence.
 */
final readonly class EnginePhpStanAnalyzer implements EngineAnalyzerInterface
{
    public function __construct(private EngineRawReportWriter $rawReportWriter = new EngineRawReportWriter()) {}

    /**
 * Returns the stable analyzer identifier used for selection, metric namespacing, failure reporting, and normalized report output.
 */
    public function name(): string
    {
        return 'phpstan';
    }

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 */
    public function inspect(string $target): EngineAnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $binary = $target . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpstan';

        if (!is_file($binary)) {
            throw new EngineAnalyzerUnavailable(sprintf('PHPStan is not installed in %s.', $target));
        }

        $paths = array_values(array_filter(
            [$target . DIRECTORY_SEPARATOR . 'src', $target . DIRECTORY_SEPARATOR . 'tests'],
            'is_dir',
        ));

        if ([] === $paths) {
            $paths = [$target];
        }

        $process = new Process([
            PHP_BINARY,
            $binary,
            'analyse',
            ...$paths,
            '--error-format=json',
            '--no-progress',
        ], $target);
        $process->setTimeout(300.0);
        $process->run();

        if (!in_array($process->getExitCode(), [0, 1], true)) {
            throw new \RuntimeException(sprintf(
                'PHPStan failed with exit code %d: %s',
                $process->getExitCode(),
                trim($process->getErrorOutput()),
            ));
        }

        $output = $process->getOutput();
        $rawReport = $this->rawReportWriter->write($target, $this->name(), $output);

        return $this->parse($output, $rawReport);
    }

    private function parse(string $json, string $rawReport): EngineAnalyzerResult
    {
        $data = $this->decode($json);
        $findings = [
            ...$this->parseFileFindings($data),
            ...$this->parseGlobalFindings($data),
        ];
        $totals = $this->totals($data, count($findings));

        return new EngineAnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: $totals,
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
            throw new \UnexpectedValueException('PHPStan JSON output must decode to an object.');
        }

        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('PHPStan JSON root keys must be strings.');
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
    private function parseFileFindings(array $data): array
    {
        $files = $data['files'] ?? [];

        if (!is_array($files)) {
            return [];
        }

        $findings = [];

        foreach ($files as $file => $fileResult) {
            if (!is_string($file) || !is_array($fileResult)) {
                continue;
            }

            $messages = $fileResult['messages'] ?? [];

            if (!is_array($messages)) {
                continue;
            }

            foreach ($messages as $message) {
                $finding = $this->parseMessage($file, $message);

                if ($finding instanceof \App\Inspecting\ValueObject\EngineFinding) {
                    $findings[] = $finding;
                }
            }
        }

        return $findings;
    }

    private function parseMessage(string $file, mixed $message): ?EngineFinding
    {
        if (!is_array($message)) {
            return null;
        }

        $identifier = $message['identifier'] ?? 'phpstan.issue';
        $text = $message['message'] ?? 'PHPStan finding';
        $line = $message['line'] ?? null;

        return new EngineFinding(
            source: $this->name(),
            rule: is_string($identifier) ? $identifier : 'phpstan.issue',
            category: 'correctness',
            severity: 'high',
            confidence: 1.0,
            message: is_string($text) ? $text : 'PHPStan finding',
            evidence: ['ignorable' => true === ($message['ignorable'] ?? false)],
            file: $file,
            line: is_int($line) ? $line : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<EngineFinding>
     */
    private function parseGlobalFindings(array $data): array
    {
        $errors = $data['errors'] ?? [];

        if (!is_array($errors)) {
            return [];
        }

        $findings = [];

        foreach ($errors as $error) {
            if (is_string($error)) {
                $findings[] = new EngineFinding(
                    source: $this->name(),
                    rule: 'phpstan.global',
                    category: 'correctness',
                    severity: 'high',
                    confidence: 1.0,
                    message: $error,
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{errors:int, fileErrors:int}
     */
    private function totals(array $data, int $fallbackFileErrors): array
    {
        $totals = $data['totals'] ?? [];

        return [
            'errors' => is_array($totals) && is_int($totals['errors'] ?? null) ? $totals['errors'] : 0,
            'fileErrors' => is_array($totals) && is_int($totals['file_errors'] ?? null)
                ? $totals['file_errors']
                : $fallbackFileErrors,
        ];
    }
}
