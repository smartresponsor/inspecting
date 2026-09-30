<?php

declare(strict_types=1);

namespace App\Inspecting\Inspector;

use App\Inspecting\ValueObject\EngineFinding;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Case_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;

/**
 * Evaluates parsed methods for complexity, size, parameter-count, and other method-level maintainability signals.
 */
final readonly class EngineMethodDesignInspector
{
    private const int METHOD_LINE_THRESHOLD = 60;
    private const int COMPLEXITY_THRESHOLD = 15;
    private const int INSTANCEOF_DISPATCH_THRESHOLD = 3;

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

    /**
 * Evaluates the supplied analysis target and returns the normalized findings, metrics, and evidence owned by this inspection surface.
 *
     * @return array{findings:list<EngineFinding>, complexity:int}
     */
    public function inspect(string $className, ClassMethod $method, string $file): array
    {
        $complexity = 1 + $this->countComplexity($method->stmts ?? []);
        $findings = [
            ...$this->inspectMethodSize($className, $method, $file),
            ...$this->inspectComplexity($className, $method, $file, $complexity),
            ...$this->inspectTypeDispatch($className, $method, $file),
        ];

        return [
            'findings' => $findings,
            'complexity' => $complexity,
        ];
    }

    /**
     * @return list<EngineFinding>
     */
    private function inspectMethodSize(string $className, ClassMethod $method, string $file): array
    {
        $methodLines = $method->getEndLine() - $method->getStartLine() + 1;

        if ($methodLines <= self::METHOD_LINE_THRESHOLD) {
            return [];
        }

        return [new EngineFinding(
            source: 'php-structure',
            rule: 'smell.long-method',
            category: 'maintainability',
            severity: 'medium',
            confidence: 0.82,
            message: sprintf('%s::%s() spans %d lines.', $className, $method->name->toString(), $methodLines),
            evidence: ['lines' => $methodLines],
            file: $file,
            line: $method->getStartLine(),
            endLine: $method->getEndLine(),
        )];
    }

    /**
     * @return list<EngineFinding>
     */
    private function inspectComplexity(string $className, ClassMethod $method, string $file, int $complexity): array
    {
        if ($complexity <= self::COMPLEXITY_THRESHOLD) {
            return [];
        }

        return [new EngineFinding(
            source: 'php-structure',
            rule: 'smell.high-cyclomatic-complexity',
            category: 'complexity',
            severity: $complexity >= 25 ? 'high' : 'medium',
            confidence: 0.9,
            message: sprintf('%s::%s() has cyclomatic complexity %d.', $className, $method->name->toString(), $complexity),
            evidence: ['complexity' => $complexity],
            file: $file,
            line: $method->getStartLine(),
            endLine: $method->getEndLine(),
        )];
    }

    /**
     * @return list<EngineFinding>
     */
    private function inspectTypeDispatch(string $className, ClassMethod $method, string $file): array
    {
        foreach ($method->params as $param) {
            if (!$param->var instanceof Variable || !is_string($param->var->name) || !$this->isPolymorphicParameterType($param->type)) {
                continue;
            }

            $instanceofCount = $this->countInstanceofForVariable($method->stmts ?? [], $param->var->name);

            if ($instanceofCount < self::INSTANCEOF_DISPATCH_THRESHOLD) {
                continue;
            }

            return [new EngineFinding(
                source: 'php-structure',
                rule: 'solid.ocp.repeated-type-dispatch',
                category: 'design',
                severity: 'medium',
                confidence: 0.88,
                message: sprintf(
                    '%s::%s() dispatches polymorphic parameter $%s through %d instanceof checks; review strategy or polymorphic behavior.',
                    $className,
                    $method->name->toString(),
                    $param->var->name,
                    $instanceofCount,
                ),
                evidence: [
                    'parameter' => $param->var->name,
                    'instanceofCount' => $instanceofCount,
                ],
                file: $file,
                line: $method->getStartLine(),
                endLine: $method->getEndLine(),
            )];
        }

        return [];
    }

    private function isPolymorphicParameterType(Identifier|Name|ComplexType|null $type): bool
    {
        if ($type instanceof Name) {
            return true;
        }

        return $type instanceof Identifier && in_array($type->toString(), ['object', 'mixed'], true);
    }

    /**
     * @param array<array-key, mixed> $values
     */
    private function countInstanceofForVariable(array $values, string $variableName): int
    {
        $count = 0;

        foreach ($values as $value) {
            if (!$value instanceof Node) {
                if (is_array($value)) {
                    $count += $this->countInstanceofForVariable($value, $variableName);
                }

                continue;
            }

            if (
                $value instanceof Instanceof_
                && $value->expr instanceof Variable
                && $variableName === $value->expr->name
            ) {
                ++$count;
            }

            foreach ($value->getSubNodeNames() as $subNodeName) {
                $subNode = $value->{$subNodeName};

                if ($subNode instanceof Node) {
                    $count += $this->countInstanceofForVariable([$subNode], $variableName);
                } elseif (is_array($subNode)) {
                    $count += $this->countInstanceofForVariable($subNode, $variableName);
                }
            }
        }

        return $count;
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
}
