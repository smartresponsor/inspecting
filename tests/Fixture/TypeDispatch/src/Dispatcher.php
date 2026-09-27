<?php

declare(strict_types=1);

namespace Fixture;

final class Dispatcher
{
    public function handle(object $value): string
    {
        if ($value instanceof \DateTimeImmutable) {
            return 'immutable';
        }

        if ($value instanceof \DateTime) {
            return 'mutable';
        }

        if ($value instanceof \ArrayObject) {
            return 'array';
        }

        return 'other';
    }
}
