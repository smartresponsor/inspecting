<?php

declare(strict_types=1);

namespace App\Analyzer;

final readonly class SemgrepConfigResolver
{
    public function __construct(private ?string $ownedRulePack = null) {}

    public function resolve(string $target, ?string $explicitConfig = null): ?string
    {
        if (null !== $explicitConfig) {
            return $this->existingConfig($explicitConfig);
        }

        $projectRulePack = $target . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'semgrep';
        if ($this->containsRuleFiles($projectRulePack)) {
            return $projectRulePack;
        }

        foreach ([
            $target . DIRECTORY_SEPARATOR . '.semgrep.yml',
            $target . DIRECTORY_SEPARATOR . '.semgrep.yaml',
            $target . DIRECTORY_SEPARATOR . 'semgrep.yml',
            $target . DIRECTORY_SEPARATOR . 'semgrep.yaml',
        ] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        $ownedRulePack = $this->ownedRulePack ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'semgrep';

        return $this->containsRuleFiles($ownedRulePack) ? $ownedRulePack : null;
    }

    private function existingConfig(string $config): string
    {
        if (is_file($config) || $this->containsRuleFiles($config)) {
            return $config;
        }

        throw new \InvalidArgumentException(sprintf('Semgrep configuration does not exist or contains no YAML rules: %s', $config));
    }

    private function containsRuleFiles(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        foreach (['*.yml', '*.yaml'] as $pattern) {
            $matches = glob($directory . DIRECTORY_SEPARATOR . $pattern);
            if (is_array($matches) && [] !== $matches) {
                return true;
            }
        }

        return false;
    }
}
