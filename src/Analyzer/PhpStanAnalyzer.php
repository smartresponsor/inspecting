<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\Finding;
use Symfony\Component\Process\Process;

final readonly class PhpStanAnalyzer implements AnalyzerInterface
{
    public function name(): string
    {
        return 'phpstan';
    }

    public function inspect(string $target): AnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $binary = $target . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpstan';

        if (!is_file($binary)) {
            throw new AnalyzerUnavailable(sprintf('PHPStan is not installed in %s.', $target));
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

        return $this->parse($process->getOutput());
    }

    private function parse(string $json): AnalyzerResult
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw new \UnexpectedValueException('PHPStan JSON output must decode to an object.');
        }

        $findings = [];
        $files = $data['files'] ?? [];

        if (is_array($files)) {
            foreach ($files as $file => $fileResult) {
                if (!is_string($file) || !is_array($fileResult)) {
                    continue;
                }

                $messages = $fileResult['messages'] ?? [];

                if (!is_array($messages)) {
                    continue;
                }

                foreach ($messages as $message) {
                    if (!is_array($message)) {
                        continue;
                    }

                    $identifier = $message['identifier'] ?? 'phpstan.issue';
                    $text = $message['message'] ?? 'PHPStan finding';
                    $line = $message['line'] ?? null;

                    $findings[] = new Finding(
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
            }
        }

        $errors = $data['errors'] ?? [];

        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (!is_string($error)) {
                    continue;
                }

                $findings[] = new Finding(
                    source: $this->name(),
                    rule: 'phpstan.global',
                    category: 'correctness',
                    severity: 'high',
                    confidence: 1.0,
                    message: $error,
                );
            }
        }

        $totals = $data['totals'] ?? [];
        $errorsTotal = is_array($totals) && is_int($totals['errors'] ?? null) ? $totals['errors'] : 0;
        $fileErrorsTotal = is_array($totals) && is_int($totals['file_errors'] ?? null)
            ? $totals['file_errors']
            : count($findings);

        return new AnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: [
                'errors' => $errorsTotal,
                'fileErrors' => $fileErrorsTotal,
            ],
        );
    }
}
