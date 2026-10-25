<?php

declare(strict_types=1);

namespace App\Tests\Resolver;

use App\Inspecting\Resolver\EngineSemgrepConfigResolver;
use PHPUnit\Framework\TestCase;

final class EngineSemgrepConfigResolverTest extends TestCase
{
    public function testExplicitConfigurationOverridesProjectAndRejectsMissingFile(): void
    {
        $root = $this->fixture();
        try {
            mkdir($root.'/config/semgrep', 0775, true);
            file_put_contents($root.'/config/semgrep/project.yaml', 'rules: []');
            file_put_contents($root.'/explicit.yml', 'rules: []');
            $resolver = new EngineSemgrepConfigResolver();
            self::assertSame($root.'/explicit.yml', $resolver->resolve($root, $root.'/explicit.yml'));
            self::assertSame($root.'/config/semgrep', $resolver->resolve($root));
            $this->expectException(\InvalidArgumentException::class);
            $resolver->resolve($root, $root.'/missing.yml');
        } finally {
            $this->cleanup($root);
        }
    }

    public function testRootPrecedenceAndOwnedFallback(): void
    {
        $root = $this->fixture();
        try {
            file_put_contents($root.'/.semgrep.yml', 'rules: []');
            file_put_contents($root.'/.semgrep.yaml', 'rules: []');
            $resolver = new EngineSemgrepConfigResolver($root.'/absent');
            self::assertSame($root.'/.semgrep.yml', $resolver->resolve($root));
            unlink($root.'/.semgrep.yml');
            self::assertSame($root.'/.semgrep.yaml', $resolver->resolve($root));
            unlink($root.'/.semgrep.yaml');
            self::assertNull($resolver->resolve($root));
            mkdir($root.'/owned');
            file_put_contents($root.'/owned/rule.yaml', 'rules: []');
            self::assertSame($root.'/owned', (new EngineSemgrepConfigResolver($root.'/owned'))->resolve($root));
        } finally {
            $this->cleanup($root);
        }
    }

    private function fixture(): string
    {
        $path = sys_get_temp_dir().'/inspecting-semgrep-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0775, true));

        return $path;
    }

    private function cleanup(string $root): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }
}
