<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use App\Inspecting\Inspector\EngineClassDesignInspector;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class EngineClassDesignInspectorTest extends TestCase
{
    public function testConstructorDependencyThresholdAndSeverity(): void
    {
        $inspector = new EngineClassDesignInspector();
        foreach ([8 => null, 9 => 'medium', 12 => 'high'] as $count => $severity) {
            $parameters = implode(', ', array_map(
                static fn (int $index): string => 'Dependency $dependency'.$index,
                range(1, $count),
            ));
            $class = $this->parseClass('class Example { public function __construct('.$parameters.') {} }');
            $findings = $inspector->inspectClass($class, 'fixture.php');
            if (null === $severity) {
                self::assertSame([], $findings);
                continue;
            }
            self::assertSame('solid.srp.excessive-constructor-dependencies', $findings[0]->rule);
            self::assertSame($severity, $findings[0]->severity);
            self::assertSame($count, $findings[0]->evidence['dependencyCount']);
        }
    }

    public function testPublicMethodThreshold(): void
    {
        foreach ([15, 16] as $count) {
            $methods = implode(' ', array_map(
                static fn (int $index): string => 'public function method'.$index.'(): void {}',
                range(1, $count),
            ));
            $findings = (new EngineClassDesignInspector())->inspectClass(
                $this->parseClass('class Example { '.$methods.' }'),
                'fixture.php',
            );
            self::assertSame($count === 16 ? ['smell.large-public-api'] : [], array_map(
                static fn ($finding): string => $finding->rule,
                $findings,
            ));
        }
    }

    public function testInterfaceMethodThreshold(): void
    {
        foreach ([10, 11] as $count) {
            $methods = implode(' ', array_map(
                static fn (int $index): string => 'public function method'.$index.'(): void;',
                range(1, $count),
            ));
            $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse('' . '<?php interface Example { '.$methods.' }');
            self::assertNotNull($nodes);
            self::assertInstanceOf(\PhpParser\Node\Stmt\Interface_::class, $nodes[0]);
            $findings = (new EngineClassDesignInspector())->inspectInterface($nodes[0], 'fixture.php');
            self::assertSame($count === 11 ? ['solid.isp.oversized-interface'] : [], array_map(
                static fn ($finding): string => $finding->rule,
                $findings,
            ));
        }
    }

    public function testContainerLookupRequiresMatchingPromotedProperty(): void
    {
        $inspector = new EngineClassDesignInspector();
        $positive = $this->parseClass('class Example { public function __construct(private ContainerInterface $container) {} public function run(): void { $this->container->get("service"); } }');
        self::assertSame('solid.dip.service-locator', $inspector->inspectClass($positive, 'fixture.php')[0]->rule);

        $negative = $this->parseClass('class Example { public function __construct(private ContainerInterface $container) {} public function run(): void { $this->other->get("service"); } }');
        self::assertSame([], $inspector->inspectClass($negative, 'fixture.php'));
    }

    public function testClassSizeBoundary(): void
    {
        foreach ([400, 401] as $lineCount) {
            $source = 'class Example {' . str_repeat("\n", $lineCount - 1) . '}';
            $findings = (new EngineClassDesignInspector())->inspectClass(
                $this->parseClass($source),
                'fixture.php',
            );
            self::assertSame($lineCount === 401 ? ['smell.large-class'] : [], array_map(
                static fn ($finding): string => $finding->rule,
                $findings,
            ));
        }
    }

    public function testServiceLocatorIgnoresNonLookupCallsAndSupportsHas(): void
    {
        $inspector = new EngineClassDesignInspector();
        $nonLookup = $this->parseClass('class Example {
            public function __construct(private ContainerInterface $container) {}
            public function run(): void { $this->container->set("service", new \\stdClass()); }
        }');
        self::assertSame([], $inspector->inspectClass($nonLookup, 'fixture.php'));

        $lookup = $this->parseClass('class Example {
            public function __construct(private ContainerInterface $container) {}
            public function run(): void { if ($this->container->has("service")) { $this->container->has("other"); } }
        }');
        self::assertSame('solid.dip.service-locator', $inspector->inspectClass($lookup, 'fixture.php')[0]->rule);
    }

    public function testConstructorDependencyTypeVariants(): void
    {
        $inspector = new EngineClassDesignInspector();
        $typed = $this->parseClass('class Example {
            public function __construct(
                A|B $first, A&B $second, ?A $third, int $fourth,
                A $fifth, B $sixth, C $seventh, D $eighth, E $ninth, F $tenth
            ) {}
        }');
        $findings = $inspector->inspectClass($typed, 'fixture.php');
        self::assertSame('solid.srp.excessive-constructor-dependencies', $findings[0]->rule);
        self::assertSame(9, $findings[0]->evidence['dependencyCount']);
    }

    private function parseClass(string $source): \PhpParser\Node\Stmt\Class_
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$source);
        self::assertNotNull($nodes);
        self::assertInstanceOf(\PhpParser\Node\Stmt\Class_::class, $nodes[0]);
        return $nodes[0];
    }
}
