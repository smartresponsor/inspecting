<?php

declare(strict_types=1);

namespace App\Inspecting\Analyzer;

use App\Inspecting\Contract\EngineAnalyzerInterface;
use App\Inspecting\Exception\EngineAnalyzerUnavailable;
use App\Inspecting\ValueObject\EngineAnalyzerResult;
use App\Inspecting\ValueObject\EngineFinding;
use App\Inspecting\Inspector\EngineClassDesignInspector;
use App\Inspecting\Inspector\EngineClassMetricsInspector;
use App\Inspecting\Inspector\EngineCohesionInspector;
use App\Inspecting\Inspector\EngineMethodDesignInspector;
use App\Inspecting\Inspector\EngineProjectCouplingInspector;
use App\Inspecting\Writer\EngineRawReportWriter;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Parses target PHP source to derive structural metrics, design findings, and dependency-graph evidence without executing application code.
 */
final readonly class EnginePhpStructureAnalyzer implements EngineAnalyzerInterface
{
    private EngineClassDesignInspector $classInspector;
    private EngineClassMetricsInspector $classMetricsInspector;
    private EngineCohesionInspector $cohesionInspector;
    private EngineMethodDesignInspector $methodInspector;
    private EngineRawReportWriter $rawReportWriter;

    public function __construct(
        ?EngineClassDesignInspector $classInspector = null,
        ?EngineClassMetricsInspector $classMetricsInspector = null,
        ?EngineCohesionInspector $cohesionInspector = null,
        ?EngineMethodDesignInspector $methodInspector = null,
        ?EngineRawReportWriter $rawReportWriter = null,
    ) {
        $this->classInspector = $classInspector ?? new EngineClassDesignInspector();
        $this->classMetricsInspector = $classMetricsInspector ?? new EngineClassMetricsInspector();
        $this->cohesionInspector = $cohesionInspector ?? new EngineCohesionInspector();
        $this->methodInspector = $methodInspector ?? new EngineMethodDesignInspector();
        $this->rawReportWriter = $rawReportWriter ?? new EngineRawReportWriter();
    }

    /**
 * Returns the stable analyzer identifier used for selection, metric namespacing, failure reporting, and normalized report output.
 */
    public function name(): string
    {
        return 'php-structure';
    }

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 */
    public function inspect(string $target): EngineAnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $source = $target . DIRECTORY_SEPARATOR . 'src';

        if (!is_dir($source)) {
            throw new EngineAnalyzerUnavailable(sprintf('No src directory exists in %s.', $target));
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $couplingInspector = new EngineProjectCouplingInspector();
        $findings = [];
        /** @var array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int, maxConstructorDependencies:int, maxImplementedInterfaces:int, inheritedClasses:int, maxFanOut:int, maxFanIn:int, maxInheritanceDepth:int} $metrics */
        $metrics = [
            'files' => 0,
            'classes' => 0,
            'interfaces' => 0,
            'methods' => 0,
            'maxComplexity' => 0,
            'maxConstructorDependencies' => 0,
            'maxImplementedInterfaces' => 0,
            'inheritedClasses' => 0,
            'maxFanOut' => 0,
            'maxFanIn' => 0,
            'maxInheritanceDepth' => 0,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && 'php' === strtolower($fileInfo->getExtension())) {
                $this->inspectFile($parser, $couplingInspector, $target, $fileInfo, $findings, $metrics);
            }
        }

        $metrics = array_replace($metrics, $couplingInspector->metrics());
        $dependencyGraph = $couplingInspector->graph();
        $dependencyGraphJson = json_encode(
            $dependencyGraph,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $dependencyGraphPath = $this->rawReportWriter->write(
            $target,
            'php-structure-dependency-graph',
            $dependencyGraphJson . PHP_EOL,
        );

        return new EngineAnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: $metrics,
            rawReportReferences: [$dependencyGraphPath],
        );
    }

    /**
     * @param list<EngineFinding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int, maxConstructorDependencies:int, maxImplementedInterfaces:int, inheritedClasses:int, maxFanOut:int, maxFanIn:int, maxInheritanceDepth:int} $metrics
     */
    private function inspectFile(
        Parser $parser,
        EngineProjectCouplingInspector $couplingInspector,
        string $target,
        \SplFileInfo $fileInfo,
        array &$findings,
        array &$metrics,
    ): void {
        $contents = file_get_contents($fileInfo->getPathname());

        if (false === $contents) {
            return;
        }

        ++$metrics['files'];
        $relativeFile = $this->relativePath($target, $fileInfo->getPathname());

        try {
            $nodes = $parser->parse($contents);
        } catch (Error $error) {
            $findings[] = new EngineFinding(
                source: $this->name(),
                rule: 'php.parse-error',
                category: 'correctness',
                severity: 'critical',
                confidence: 1.0,
                message: $error->getMessage(),
                file: $relativeFile,
                line: $error->getStartLine(),
            );

            return;
        }

        if (null !== $nodes) {
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new NameResolver(null, ['preserveOriginalNames' => true]));
            $resolvedNodes = array_values($traverser->traverse(array_values($nodes)));
            $couplingInspector->collect($resolvedNodes);
            $this->inspectNodes($resolvedNodes, $relativeFile, $findings, $metrics);
        }
    }

    /**
     * @param list<Node> $nodes
     * @param list<EngineFinding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int, maxConstructorDependencies:int, maxImplementedInterfaces:int, inheritedClasses:int, maxFanOut:int, maxFanIn:int, maxInheritanceDepth:int} $metrics
     */
    private function inspectNodes(array $nodes, string $file, array &$findings, array &$metrics): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_ && $node->name instanceof Identifier) {
                ++$metrics['classes'];
                $this->inspectClass($node, $file, $findings, $metrics);
            } elseif ($node instanceof Interface_) {
                ++$metrics['interfaces'];
                array_push($findings, ...$this->classInspector->inspectInterface($node, $file));
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $value = $node->{$subNodeName};

                if ($value instanceof Node) {
                    $this->inspectNodes([$value], $file, $findings, $metrics);
                } elseif (is_array($value)) {
                    $childNodes = array_values(array_filter(
                        $value,
                        static fn(mixed $item): bool => $item instanceof Node,
                    ));
                    /** @var list<Node> $childNodes */
                    $this->inspectNodes($childNodes, $file, $findings, $metrics);
                }
            }
        }
    }

    /**
     * @param list<EngineFinding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int, maxConstructorDependencies:int, maxImplementedInterfaces:int, inheritedClasses:int, maxFanOut:int, maxFanIn:int, maxInheritanceDepth:int} $metrics
     */
    private function inspectClass(Class_ $class, string $file, array &$findings, array &$metrics): void
    {
        $className = $class->name?->toString() ?? 'anonymous';
        $methods = $class->getMethods();
        $metrics['methods'] += count($methods);

        $classMetrics = $this->classMetricsInspector->inspect($class);
        $metrics['maxConstructorDependencies'] = max(
            $metrics['maxConstructorDependencies'],
            $classMetrics['constructorDependencies'],
        );
        $metrics['maxImplementedInterfaces'] = max(
            $metrics['maxImplementedInterfaces'],
            $classMetrics['implementedInterfaces'],
        );
        $metrics['inheritedClasses'] += $classMetrics['hasParent'] ? 1 : 0;

        array_push($findings, ...$this->classInspector->inspectClass($class, $file));
        array_push($findings, ...$this->cohesionInspector->inspect($class, $file));

        foreach ($methods as $method) {
            $result = $this->methodInspector->inspect($className, $method, $file);
            $metrics['maxComplexity'] = max($metrics['maxComplexity'], $result['complexity']);
            array_push($findings, ...$result['findings']);
        }
    }

    private function relativePath(string $target, string $file): string
    {
        $prefix = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($prefix)))
            : $file;
    }
}
