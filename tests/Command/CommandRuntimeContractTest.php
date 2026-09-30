<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Inspecting\Command\EngineInspectCommand;
use App\Inspecting\Command\EngineReportCommand;
use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\InspectingBundle;
use App\Inspecting\Inspector\EngineInspector;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\ValueObject\EngineFinding;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandRuntimeContractTest extends TestCase
{
    public function testInspectCommandRunsSelectedAnalyzerAndWritesReport(): void
    {
        $analyzer = new class implements EngineAnalyzerInterface {
            public function name(): string
            {
                return 'synthetic';
            }

            public function inspect(string $target): EngineAnalyzerResult
            {
                return new EngineAnalyzerResult(
                    analyzer: 'synthetic',
                    findings: [new EngineFinding(
                        source: 'synthetic',
                        rule: 'contract.test',
                        category: 'correctness',
                        severity: 'low',
                        confidence: 1.0,
                        message: 'Synthetic boundary finding used to verify the Inspecting CLI contract.',
                    )],
                    metrics: ['visited' => 1],
                );
            }
        };

        $tester = new CommandTester(new EngineInspectCommand(new EngineInspector([$analyzer])));
        $status = $tester->execute([
            'target' => dirname(__DIR__, 2),
            '--analyzer' => ['synthetic'],
        ]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('"synthetic"', $tester->getDisplay());
        self::assertStringContainsString('Report written:', $tester->getDisplay());
    }

    public function testReportCommandReadsPersistedSummary(): void
    {
        $path = $this->temporaryReport([
            'target' => '/tmp/example',
            'summary' => ['findingCount' => 3],
        ]);

        try {
            $tester = new CommandTester(new EngineReportCommand());
            $status = $tester->execute(['report' => $path]);

            self::assertSame(Command::SUCCESS, $status);
            self::assertStringContainsString('"findingCount": 3', $tester->getDisplay());
            self::assertStringContainsString('/tmp/example', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function testReportCommandRejectsMissingArtifact(): void
    {
        $tester = new CommandTester(new EngineReportCommand());

        $this->expectException(\InvalidArgumentException::class);
        $tester->execute(['report' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-inspecting-report.json']);
    }

    public function testReportCommandRejectsArtifactWithoutSummary(): void
    {
        $path = $this->temporaryReport(['target' => '/tmp/example']);

        try {
            $tester = new CommandTester(new EngineReportCommand());

            $this->expectException(\UnexpectedValueException::class);
            $tester->execute(['report' => $path]);
        } finally {
            @unlink($path);
        }
    }

    public function testBundleExposesRepositoryRoot(): void
    {
        self::assertSame(dirname(__DIR__, 2), (new InspectingBundle())->getPath());
    }

    /** @param array<string, mixed> $payload */
    private function temporaryReport(array $payload): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inspecting-command-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

        return $path;
    }
}
