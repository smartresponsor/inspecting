<?php

declare(strict_types=1);

namespace App\Tests\Inspector;

use App\Inspecting\Inspector\EngineCohesionInspector;
use PhpParser\Node\Stmt\Class_;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class EngineCohesionInspectorTest extends TestCase
{
    public function testFourIndependentPropertyGroupsProduceCohesionFinding(): void
    {
        $source = '<?php class Fragmented {
            private int $a = 0;
            private int $b = 0;
            private int $c = 0;
            private int $d = 0;
            public function first(): int { $this->a++; return $this->a; }
            public function second(): int { $this->b++; return $this->b; }
            public function third(): int { $this->c++; return $this->c; }
            public function fourth(): int { $this->d++; return $this->d; }
        }';

        $findings = $this->inspect($source);
        self::assertCount(1, $findings);
        self::assertSame('solid.srp.low-property-cohesion', $findings[0]->rule);
        self::assertSame('fixture.php', $findings[0]->file);
        self::assertSame([
            'participatingMethods' => 4,
            'propertyCount' => 4,
            'components' => 4,
        ], $findings[0]->evidence);
    }

    public function testSharedPropertyConnectsMethodsAndAvoidsFalsePositive(): void
    {
        $source = '<?php class Cohesive {
            public function first(): int { $this->shared++; return $this->shared; }
            public function second(): int { $this->shared++; return $this->shared; }
            public function third(): int { $this->shared++; return $this->shared; }
            public function fourth(): int { $this->shared++; return $this->shared; }
        }';

        self::assertSame([], $this->inspect($source));
    }

    public function testIgnoresStaticConstructorAndNonparticipatingMethods(): void
    {
        $source = '<?php class Limited {
            public function __construct() { $this->a = 1; $this->b = 2; }
            public static function utility(): int { $other = new self(); return $other->a; }
            public function first(): int { $this->a++; return $this->a; }
            public function second(): int { $this->b++; return $this->b; }
            public function third(): int { $this->c++; return $this->c; }
            public function noAccess(): int { $local = 1; return $local; }
            public function tooShort(): int { return $this->d; }
        }';

        self::assertSame([], $this->inspect($source));
    }

    /** @return list<\App\Inspecting\ValueObject\EngineFinding> */
    private function inspect(string $source): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($source);
        self::assertNotNull($nodes);
        self::assertInstanceOf(Class_::class, $nodes[0]);

        return (new EngineCohesionInspector())->inspect($nodes[0], 'fixture.php');
    }
}
