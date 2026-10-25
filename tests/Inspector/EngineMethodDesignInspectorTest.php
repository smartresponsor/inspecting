<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use App\Inspecting\Inspector\EngineMethodDesignInspector;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class EngineMethodDesignInspectorTest extends TestCase
{
    public function testMethodSizeThreshold(): void
    {
        foreach ([60, 61] as $lines) {
            $method = $this->parseMethod('public function run(): void {' . str_repeat("\n", $lines - 1) . '}');
            $result = (new EngineMethodDesignInspector())->inspect('Example', $method, 'fixture.php');
            self::assertSame($lines === 61 ? ['smell.long-method'] : [], $this->rules($result['findings']));
        }
    }

    public function testComplexityThresholdAndSeverity(): void
    {
        foreach ([14 => null, 15 => 'medium', 24 => 'high'] as $branches => $severity) {
            $body = str_repeat('if ($value) {} ', $branches);
            $method = $this->parseMethod('public function run(bool $value): void {'.$body.'}');
            $result = (new EngineMethodDesignInspector())->inspect('Example', $method, 'fixture.php');
            self::assertSame($branches + 1, $result['complexity']);
            $findings = $result['findings'];
            if (null === $severity) {
                self::assertSame([], $findings);
            } else {
                self::assertSame(['smell.high-cyclomatic-complexity'], $this->rules($findings));
                self::assertSame($severity, $findings[0]->severity);
            }
        }
    }

    public function testTypeDispatchOnlyCountsMatchingPolymorphicParameter(): void
    {
        $body = 'if ($item instanceof First) {} if ($item instanceof Second) {} if ($other instanceof Third) {}';
        $inspector = new EngineMethodDesignInspector();
        $method = $this->parseMethod('public function run(object $item, object $other): void {'.$body.'}');
        self::assertSame([], $this->rules($inspector->inspect('Example', $method, 'fixture.php')['findings']));

        $method = $this->parseMethod('public function run(object $item): void {'.$body.' if ($item instanceof Third) {} }');
        $findings = $inspector->inspect('Example', $method, 'fixture.php')['findings'];
        self::assertSame(['solid.ocp.repeated-type-dispatch'], $this->rules($findings));
        self::assertSame(3, $findings[0]->evidence['instanceofCount']);
    }

    public function testDispatchSupportsNamedTypesAndIgnoresScalarParameters(): void
    {
        $inspector = new EngineMethodDesignInspector();
        $method = $this->parseMethod('public function run(Contract $item, int $count): void {
            if ($item instanceof First) {}
            if ($item instanceof Second) {}
            if ($item instanceof Third) {}
            if ($count instanceof Fourth) {}
        }');
        $findings = $inspector->inspect('Example', $method, 'fixture.php')['findings'];
        self::assertSame(['solid.ocp.repeated-type-dispatch'], $this->rules($findings));
        self::assertSame('item', $findings[0]->evidence['parameter']);
        self::assertSame(3, $findings[0]->evidence['instanceofCount']);
    }

    public function testComplexityCountsNestedBooleanAndLoopBranches(): void
    {
        $method = $this->parseMethod('public function run(bool $value): void {
            for ($i = 0; $i < 2; $i++) {
                if ($value && $i > 0 || !$value) { break; }
            }
        }');
        self::assertSame(5, (new EngineMethodDesignInspector())->inspect('Example', $method, 'fixture.php')['complexity']);
    }

    public function testNestedDispatchCountsOnlyMatchingVariable(): void
    {
        $method = $this->parseMethod('public function run(object $item, object $other): void {
            $checks = [
                [$item instanceof First, $other instanceof Second],
                [$item instanceof Third, $item instanceof Fourth],
            ];
        }');
        $result = (new EngineMethodDesignInspector())->inspect('Example', $method, 'fixture.php');
        self::assertSame(['solid.ocp.repeated-type-dispatch'], $this->rules($result['findings']));
        self::assertSame(3, $result['findings'][0]->evidence['instanceofCount']);
    }

    /** @param list<\App\Inspecting\ValueObject\EngineFinding> $findings
     * @return list<string>
     */
    private function rules(array $findings): array
    {
        return array_map(static fn ($finding): string => $finding->rule, $findings);
    }

    private function parseMethod(string $source): ClassMethod
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php class Example { '.$source.' }');
        self::assertNotNull($nodes);
        self::assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        $method = $nodes[0]->getMethod('run');
        self::assertInstanceOf(ClassMethod::class, $method);

        return $method;
    }
}
