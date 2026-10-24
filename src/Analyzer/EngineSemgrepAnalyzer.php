<?php

declare(strict_types=1);

namespace App\Inspecting\Analyzer;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\Resolver\EngineSemgrepConfigResolver;
use App\Inspecting\ValueObject\EngineFinding;
use App\Inspecting\Writer\EngineRawReportWriter;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Process;

/**
 * Runs Semgrep with resolved repository or owned rules and normalizes its JSON diagnostics into Inspecting findings and evidence.
 */
final readonly class EngineSemgrepAnalyzer implements EngineAnalyzerInterface
{
    public function __construct(
        private string $binary = 'semgrep',
        private ?string $config = null,
        private EngineRawReportWriter $rawReportWriter = new EngineRawReportWriter(),
        private EngineSemgrepConfigResolver $configResolver = new EngineSemgrepConfigResolver(),
        private float $timeoutSeconds = 60.0,
    ) {}

    /**
 * Returns the stable analyzer identifier used for selection, metric namespacing, failure reporting, and normalized report output.
 */
    public function name(): string
    {
        return 'semgrep';
    }

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 */
    public function inspect(string $target): EngineAnalyzerResult
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
            throw new EngineAnalyzerUnavailable(sprintf('Semgrep is not configured in %s.', $target));
        }

        $command[] = '--config=' . $config;
        $source = $target . DIRECTORY_SEPARATOR . 'src';
        if (!is_dir($source)) {
            throw new EngineAnalyzerUnavailable(sprintf('No src directory exists in %s.', $target));
        }
        $command[] = $source;

        $process = new Process($command, $target);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessStartFailedException $exception) {
            throw new EngineAnalyzerUnavailable('Semgrep executable is not available.', $exception->getCode(), previous: $exception);
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

    private function parse(string $json, string $rawReport): EngineAnalyzerResult
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

                if ($finding instanceof EngineFinding) {
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
                    $findings[] = new EngineFinding(
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

        return new EngineAnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: [
                'findings' => count($findings),
                'engineErrors' => is_array($errors) ? count($errors) : 0,
            ],
            rawReportReferences: [$rawReport],
        );
    }

    private function parseResult(mixed $result): ?EngineFinding
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

        return new EngineFinding(
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
