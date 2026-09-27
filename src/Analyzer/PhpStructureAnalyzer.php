<?php

declare(strict_types=1);

namespace App\Analyzer;

use App\Inspection\Finding;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\UnionType;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final readonly class PhpStructureAnalyzer implements AnalyzerInterface
{
    private const int CONSTRUCTOR_DEPENDENCY_THRESHOLD = 8;
    private const int CLASS_LINE_THRESHOLD = 400;
    private const int PUBLIC_METHOD_THRESHOLD = 15;
    private const int METHOD_LINE_THRESHOLD = 60;
    private const int COMPLEXITY_THRESHOLD = 15;
    private const int INTERFACE_METHOD_THRESHOLD = 10;

    /** @var list<class-string<Node>> */
    private const array COMPLEXITY_NODE_TYPES = [
        If_::class,
        ElseIf_::class,
        For_::class,
        Foreach_::class,
        While_::class,
        Do_::class,
        Case_::class,
        Catch_::class,
        Ternary::class,
        BooleanAnd::class,
        BooleanOr::class,
        MatchArm::class,
    ];

    public function name(): string
    {
        return 'php-structure';
    }

    public function inspect(string $target): AnalyzerResult
    {
        $target = realpath($target) ?: throw new \InvalidArgumentException(sprintf('Inspection target does not exist: %s', $target));
        $source = $target . DIRECTORY_SEPARATOR . 'src';

        if (!is_dir($source)) {
            throw new AnalyzerUnavailable(sprintf('No src directory exists in %s.', $target));
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $findings = [];
        /** @var array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int} $metrics */
        $metrics = [
            'files' => 0,
            'classes' => 0,
            'interfaces' => 0,
            'methods' => 0,
            'maxComplexity' => 0,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo instanceof \SplFileInfo && $fileInfo->isFile() && 'php' === strtolower($fileInfo->getExtension())) {
                $this->inspectFile($parser, $target, $fileInfo, $findings, $metrics);
            }
        }

        return new AnalyzerResult(
            analyzer: $this->name(),
            findings: $findings,
            metrics: $metrics,
        );
    }

    /**
     * @param list<Finding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int} $metrics
     */
    private function inspectFile(
        Parser $parser,
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
            $findings[] = new Finding(
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
            $this->inspectNodes(array_values($nodes), $relativeFile, $findings, $metrics);
        }
    }

    /**
     * @param list<Node> $nodes
     * @param list<Finding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int} $metrics
     */
    private function inspectNodes(array $nodes, string $file, array &$findings, array &$metrics): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Class_ && $node->name instanceof \PhpParser\Node\Identifier) {
                ++$metrics['classes'];
                $this->inspectClass($node, $file, $findings, $metrics);
            } elseif ($node instanceof Interface_) {
                ++$metrics['interfaces'];
                $this->inspectInterface($node, $file, $findings);
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $value = $node->{$subNodeName};

                if ($value instanceof Node) {
                    $this->inspectNodes([$value], $file, $findings, $metrics);
                } elseif (is_array($value)) {
                    $childNodes = array_values(array_filter($value, static fn(mixed $item): bool => $item instanceof Node));
                    /** @var list<Node> $childNodes */
                    $this->inspectNodes($childNodes, $file, $findings, $metrics);
                }
            }
        }
    }

    /**
     * @param list<Finding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int} $metrics
     */
    private function inspectClass(Class_ $class, string $file, array &$findings, array &$metrics): void
    {
        $className = $class->name?->toString() ?? 'anonymous';
        $methods = $class->getMethods();
        $metrics['methods'] += count($methods);

        $this->inspectConstructorDependencies($className, $class, $file, $findings);
        $this->inspectClassSize($className, $class, $file, $findings);
        $this->inspectPublicApi($className, $class, $file, $findings);

        foreach ($methods as $method) {
            $this->inspectMethod($className, $method, $file, $findings, $metrics);
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function inspectConstructorDependencies(string $className, Class_ $class, string $file, array &$findings): void
    {
        $constructor = $class->getMethod('__construct');

        if (!$constructor instanceof ClassMethod) {
            return;
        }

        $dependencyCount = count(array_filter(
            $constructor->params,
            fn(Param $param): bool => $this->isObjectDependency($param->type),
        ));

        if ($dependencyCount <= self::CONSTRUCTOR_DEPENDENCY_THRESHOLD) {
            return;
        }

        $findings[] = new Finding(
            source: $this->name(),
            rule: 'solid.srp.excessive-constructor-dependencies',
            category: 'design',
            severity: $dependencyCount >= 12 ? 'high' : 'medium',
            confidence: 0.78,
            message: sprintf('%s has %d constructor dependencies; review whether it owns too many responsibilities.', $className, $dependencyCount),
            evidence: ['dependencyCount' => $dependencyCount],
            file: $file,
            line: $constructor->getStartLine(),
        );
    }

    /**
     * @param list<Finding> $findings
     */
    private function inspectClassSize(string $className, Class_ $class, string $file, array &$findings): void
    {
        $classLines = $class->getEndLine() - $class->getStartLine() + 1;

        if ($classLines <= self::CLASS_LINE_THRESHOLD) {
            return;
        }

        $findings[] = new Finding(
            source: $this->name(),
            rule: 'smell.large-class',
            category: 'maintainability',
            severity: 'medium',
            confidence: 0.72,
            message: sprintf('%s spans %d lines; review cohesion and responsibility boundaries.', $className, $classLines),
            evidence: ['lines' => $classLines],
            file: $file,
            line: $class->getStartLine(),
            endLine: $class->getEndLine(),
        );
    }

    /**
     * @param list<Finding> $findings
     */
    private function inspectPublicApi(string $className, Class_ $class, string $file, array &$findings): void
    {
        $publicMethodCount = count(array_filter(
            $class->getMethods(),
            static fn(ClassMethod $method): bool => $method->isPublic(),
        ));

        if ($publicMethodCount <= self::PUBLIC_METHOD_THRESHOLD) {
            return;
        }

        $findings[] = new Finding(
            source: $this->name(),
            rule: 'smell.large-public-api',
            category: 'design',
            severity: 'medium',
            confidence: 0.7,
            message: sprintf('%s exposes %d public methods; review whether its public responsibility surface is too broad.', $className, $publicMethodCount),
            evidence: ['publicMethodCount' => $publicMethodCount],
            file: $file,
            line: $class->getStartLine(),
        );
    }

    /**
     * @param list<Finding> $findings
     * @param array{files:int, classes:int, interfaces:int, methods:int, maxComplexity:int} $metrics
     */
    private function inspectMethod(
        string $className,
        ClassMethod $method,
        string $file,
        array &$findings,
        array &$metrics,
    ): void {
        $methodName = $method->name->toString();
        $methodLines = $method->getEndLine() - $method->getStartLine() + 1;
        $complexity = 1 + $this->countComplexity($method->stmts ?? []);
        $metrics['maxComplexity'] = max($metrics['maxComplexity'], $complexity);

        if ($methodLines > self::METHOD_LINE_THRESHOLD) {
            $findings[] = new Finding(
                source: $this->name(),
                rule: 'smell.long-method',
                category: 'maintainability',
                severity: 'medium',
                confidence: 0.82,
                message: sprintf('%s::%s() spans %d lines.', $className, $methodName, $methodLines),
                evidence: ['lines' => $methodLines],
                file: $file,
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
            );
        }

        if ($complexity > self::COMPLEXITY_THRESHOLD) {
            $findings[] = new Finding(
                source: $this->name(),
                rule: 'smell.high-cyclomatic-complexity',
                category: 'complexity',
                severity: $complexity >= 25 ? 'high' : 'medium',
                confidence: 0.9,
                message: sprintf('%s::%s() has cyclomatic complexity %d.', $className, $methodName, $complexity),
                evidence: ['complexity' => $complexity],
                file: $file,
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
            );
        }
    }

    /**
     * @param list<Finding> $findings
     */
    private function inspectInterface(Interface_ $interface, string $file, array &$findings): void
    {
        $methodCount = count($interface->getMethods());

        if ($methodCount <= self::INTERFACE_METHOD_THRESHOLD) {
            return;
        }

        $findings[] = new Finding(
            source: $this->name(),
            rule: 'solid.isp.oversized-interface',
            category: 'design',
            severity: 'medium',
            confidence: 0.8,
            message: sprintf(
                '%s declares %d methods; review whether clients are forced to depend on a broader contract than necessary.',
                $interface->name?->toString() ?? 'anonymous-interface',
                $methodCount,
            ),
            evidence: ['methodCount' => $methodCount],
            file: $file,
            line: $interface->getStartLine(),
            endLine: $interface->getEndLine(),
        );
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function countComplexity(array $values): int
    {
        $complexity = 0;

        foreach ($values as $value) {
            if ($value instanceof Node) {
                if ($this->isComplexityNode($value)) {
                    ++$complexity;
                }

                foreach ($value->getSubNodeNames() as $subNodeName) {
                    $subNode = $value->{$subNodeName};

                    if ($subNode instanceof Node) {
                        $complexity += $this->countComplexity([$subNode]);
                    } elseif (is_array($subNode)) {
                        $complexity += $this->countComplexity($subNode);
                    }
                }
            } elseif (is_array($value)) {
                $complexity += $this->countComplexity($value);
            }
        }

        return $complexity;
    }

    private function isComplexityNode(Node $node): bool
    {
        foreach (self::COMPLEXITY_NODE_TYPES as $type) {
            if ($node instanceof $type) {
                return true;
            }
        }

        return false;
    }

    private function isObjectDependency(Identifier|Name|ComplexType|null $type): bool
    {
        if ($type instanceof Name) {
            return true;
        }

        if ($type instanceof NullableType) {
            return $this->isObjectDependency($type->type);
        }

        if ($type instanceof UnionType || $type instanceof IntersectionType) {
            foreach ($type->types as $innerType) {
                if ($this->isObjectDependency($innerType)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function relativePath(string $target, string $file): string
    {
        $prefix = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $prefix)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($prefix)))
            : $file;
    }
}
