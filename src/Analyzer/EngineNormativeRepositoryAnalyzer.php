<?php

declare(strict_types=1);

namespace App\Inspecting\Analyzer;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\ValueObject\EngineFinding;

final readonly class EngineNormativeRepositoryAnalyzer implements EngineAnalyzerInterface
{
    public function name(): string
    {
        return 'normative-repository';
    }
    public function inspect(string $target): EngineAnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException('Missing target.');
        $root = $target . DIRECTORY_SEPARATOR . '.canonization';
        if (!is_dir($root)) {
            throw new EngineAnalyzerUnavailable('No normative directory.');
        }
        $files = 0;
        $findings = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile() || strtolower($entry->getExtension()) !== 'md') {
                continue;
            }
            ++$files;
            $contents = file_get_contents($entry->getPathname());
            if ($contents === false) {
                throw new \RuntimeException('Cannot read normative document.');
            }
            if (trim($contents) === '') {
                $findings[] = new EngineFinding(source: $this->name(), rule: 'normative.empty-document', category: 'correctness', severity: 'high', confidence: 1.0, message: 'Empty normative document.', file: str_replace('\\', '/', substr($entry->getPathname(), strlen($target) + 1)));
            }
        }
        if ($files === 0) {
            throw new EngineAnalyzerUnavailable('No normative Markdown files.');
        }
        return new EngineAnalyzerResult($this->name(), $findings, ['documents' => $files]);
    }
}
