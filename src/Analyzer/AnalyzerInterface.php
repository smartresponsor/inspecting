<?php

declare(strict_types=1);

namespace App\Analyzer;

interface AnalyzerInterface
{
    public function name(): string;

    public function inspect(string $target): AnalyzerResult;
}
