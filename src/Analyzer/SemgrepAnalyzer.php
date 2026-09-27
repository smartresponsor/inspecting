<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\Finding;
use App\Inspection\RawReportWriter;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

final readonly class SemgrepAnalyzer implements AnalyzerInterface
{
    public function __construct(
        private string $binary = 'semgrep',
        private ?string $config = null,
        private RawReportWriter $rawReportWriter = new RawReportWriter(),
        private SemgrepConfigResolver $configResolver = new SemgrepConfigResolver(),
    ) {}

    public function name(): string
    {
        return 'semgrep';
    }

    public function inspect(string $target): AnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $command = [
            $this->binary,
            'scan',
            '--json',
            '--metrics=off',
        ];

        $config = $this->configResolver->resolve($target, $this->config);

        if (null === $config) {
            throw new AnalyzerUnavailable(sprintf('Semgrep is not configured in %s.', $target));
        }

        $command[] = '--config=' . $config;
        $command[] = $target . DIRECTORY_SEPARATOR . 'src';

        $process = new Process($command, $target);
        $process->setTimeout(300.0);

        try {
            $process->run();
        } catch (ProcessStartFailedException $exception) {
            throw new AnalyzerUnavailable('Semgrep executable is not available.', $exception->getCode(), previous: $exception);
        }

        if (!in_array($process->getExitCode(), [0, 1], true)) {
            throw new \RuntimeException(sprintf(
                'Semgrep failed with exit code %d: %s',
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
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \UnexpectedValueException('Semgrep JSON output must decode to an object.');
        }

        $findings = [];
        $results = $data['results'] ?? [];

        if (is_array($results)) {
            foreach ($results as $result) {
                $finding = $this->parseResult($result);

                if ($finding instanceof Finding) {
                    $findings[] = $finding;
                }
            }
        }

        $errors = $data['errors'] ?? [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (!is_array($error)) {
                    continue;
                }

                $message = $error['message'] ?? null;

                if (is_string($message)) {
                    $findings[] = new Finding(
                        source: $this->name(),
                        rule: 'semgrep.engine',
                        category: 'correctness',
                        severity: 'high',
                        confidence: 1.0,
                        message: $message,
                    );
                }
            }
        }

        return new AnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: [
                'findings' => count($findings),
                'engineErrors' => is_array($errors) ? count($errors) : 0,
            ],
            rawReportReferences: [$rawReport],
        );
    }

    private function parseResult(mixed $result): ?Finding
    {
        if (!is_array($result)) {
            return null;
        }

        $checkId = $result['check_id'] ?? null;
        $path = $result['path'] ?? null;
        $start = $result['start'] ?? null;
        $extra = $result['extra'] ?? null;

        if (!is_string($checkId) || !is_string($path) || !is_array($extra)) {
            return null;
        }

        $message = $extra['message'] ?? 'Semgrep finding';
        $severity = $extra['severity'] ?? 'WARNING';
        $metadata = $extra['metadata'] ?? [];
        $category = is_array($metadata) && is_string($metadata['category'] ?? null)
            ? $metadata['category']
            : 'pattern';
        $confidence = is_array($metadata) && is_numeric($metadata['confidence'] ?? null)
            ? max(0.0, min(1.0, (float) $metadata['confidence']))
            : 0.8;

        $line = is_array($start) && is_int($start['line'] ?? null)
            ? $start['line']
            : null;

        return new Finding(
            source: $this->name(),
            rule: $checkId,
            category: $category,
            severity: $this->normalizeSeverity(is_string($severity) ? $severity : 'WARNING'),
            confidence: $confidence,
            message: is_string($message) ? $message : 'Semgrep finding',
            file: str_replace('\\', '/', $path),
            line: $line,
        );
    }

    private function normalizeSeverity(string $severity): string
    {
        return match (strtoupper($severity)) {
            'ERROR' => 'high',
            'WARNING' => 'medium',
            'INFO' => 'low',
            default => 'medium',
        };
    }
}
