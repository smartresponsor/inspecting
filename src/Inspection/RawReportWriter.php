<?php

declare(strict_types=1);

namespace App\Inspection;

final readonly class RawReportWriter
{
    public function write(
        string $target,
        string $analyzer,
        string $contents,
        ?string $outputPath = null,
        ?\DateTimeImmutable $capturedAt = null,
    ): string {
        $path = $outputPath ?? $this->defaultPath($target, $analyzer, $capturedAt ?? new \DateTimeImmutable());
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create raw inspection report directory: %s', $directory));
        }

        if (false === file_put_contents($path, $contents)) {
            throw new \RuntimeException(sprintf('Unable to write raw inspection report: %s', $path));
        }

        return $path;
    }

    public function defaultPath(string $target, string $analyzer, \DateTimeImmutable $capturedAt): string
    {
        $target = realpath($target) ?: $target;
        $workspace = getcwd() ?: '.';
        $slug = $this->slug($target);
        $analyzer = $this->slug($analyzer);
        $timestamp = $capturedAt->format('Ymd-His-u');

        return $workspace
            . DIRECTORY_SEPARATOR . '.inspecting'
            . DIRECTORY_SEPARATOR . 'raw'
            . DIRECTORY_SEPARATOR . $slug
            . DIRECTORY_SEPARATOR . $timestamp . '-' . $analyzer . '.json';
    }

    private function slug(string $value): string
    {
        $normalized = str_replace(['\\', '/', ':'], '-', $value);
        $normalized = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $normalized) ?? 'artifact';
        $normalized = trim($normalized, '-');

        return '' === $normalized ? 'artifact' : substr($normalized, -80);
    }
}
