<?php

declare(strict_types=1);

namespace App\Tests\Analyzer;

use App\Analyzer\PhpStructureAnalyzer;
use PHPUnit\Framework\TestCase;

final class PhpStructureDesignRulesTest extends TestCase
{
    public function testItReportsRepeatedTypeDispatch(): void
    {
        $result = (new PhpStructureAnalyzer())->inspect(__DIR__ . '/../Fixture/TypeDispatch');

        self::assertCount(1, $result->findings);
        self::assertSame('solid.ocp.repeated-type-dispatch', $result->findings[0]->rule);
        self::assertSame(3, $result->findings[0]->evidence['instanceofCount']);
    }

    public function testItReportsServiceLocatorUsage(): void
    {
        $result = (new PhpStructureAnalyzer())->inspect(__DIR__ . '/../Fixture/ServiceLocator');

        self::assertCount(1, $result->findings);
        self::assertSame('solid.dip.service-locator', $result->findings[0]->rule);
        self::assertSame('container', $result->findings[0]->evidence['property']);
    }

    public function testItReportsLowPropertyCohesion(): void
    {
        $result = (new PhpStructureAnalyzer())->inspect(__DIR__ . '/../Fixture/Cohesion');

        self::assertCount(1, $result->findings);
        self::assertSame('solid.srp.low-property-cohesion', $result->findings[0]->rule);
        self::assertSame(4, $result->findings[0]->evidence['components']);
        self::assertSame(4, $result->findings[0]->evidence['participatingMethods']);
    }
}
