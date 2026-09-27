<?php

declare(strict_types=1);

namespace App\Inspection;

final readonly class ReportWriter
{
    public function write(InspectionReport $report, ?string $outputPath = null): string
    {
        $path = $outputPath ?? $this->defaultPath($report);
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create inspection report directory: %s', $directory));
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (false === file_put_contents($path, $json . PHP_EOL)) {
            throw new \RuntimeException(sprintf('Unable to write inspection report: %s', $path));
        }

        return $path;
    }

    public function defaultPath(InspectionReport $report): string
    {
        $target = realpath($report->target) ?: $report->target;
        $workspace = getcwd() ?: '.';
        $slug = $this->slug($target);
        $timestamp = $report->startedAt->format('Ymd-His');

        return $workspace . DIRECTORY_SEPARATOR . '.inspecting' . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $slug . '-' . $timestamp . '.json';
    }

    private function slug(string $target): string
    {
        $normalized = str_replace(['\\', '/', ':'], '-', $target);
        $normalized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $normalized) ?? 'target';
        $normalized = trim($normalized, '-');

        return '' === $normalized ? 'target' : substr($normalized, -80);
    }
}
