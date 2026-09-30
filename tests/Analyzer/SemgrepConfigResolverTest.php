<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Inspecting\Resolver\EngineSemgrepConfigResolver;
use PHPUnit\Framework\TestCase;

final class SemgrepConfigResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inspecting-semgrep-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExplicitConfigHasPriority(): void
    {
        $explicit = $this->root . DIRECTORY_SEPARATOR . 'custom.yml';
        file_put_contents($explicit, "rules: []\n");

        $resolver = new EngineSemgrepConfigResolver($this->createRulePack('owned'));

        self::assertSame($explicit, $resolver->resolve($this->root, $explicit));
    }

    public function testProjectRulePackHasPriorityOverRootConfigAndOwnedRules(): void
    {
        $projectRulePack = $this->root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'semgrep';
        mkdir($projectRulePack, 0775, true);
        file_put_contents($projectRulePack . DIRECTORY_SEPARATOR . 'project.yml', "rules: []\n");
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.semgrep.yml', "rules: []\n");

        $resolver = new EngineSemgrepConfigResolver($this->createRulePack('owned'));

        self::assertSame($projectRulePack, $resolver->resolve($this->root));
    }

    public function testOwnedRulePackIsFallback(): void
    {
        $owned = $this->createRulePack('owned');
        $resolver = new EngineSemgrepConfigResolver($owned);

        self::assertSame($owned, $resolver->resolve($this->root));
    }

    public function testItReturnsNullWhenNoRulesAreAvailable(): void
    {
        $resolver = new EngineSemgrepConfigResolver($this->root . DIRECTORY_SEPARATOR . 'missing');

        self::assertNull($resolver->resolve($this->root));
    }

    private function createRulePack(string $name): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        mkdir($directory, 0775, true);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'rules.yml', "rules: []\n");

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
        }

        rmdir($directory);
    }
}
