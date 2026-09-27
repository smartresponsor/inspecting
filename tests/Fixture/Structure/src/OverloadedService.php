<?php

declare(strict_types=1);

namespace Fixture;

final class OverloadedService
{
    public function __construct(
        private readonly \stdClass $one,
        private readonly \stdClass $two,
        private readonly \stdClass $three,
        private readonly \stdClass $four,
        private readonly \stdClass $five,
        private readonly \stdClass $six,
        private readonly \stdClass $seven,
        private readonly \stdClass $eight,
        private readonly \stdClass $nine,
    ) {}

    public function dependencyCount(): int
    {
        return count([
            $this->one,
            $this->two,
            $this->three,
            $this->four,
            $this->five,
            $this->six,
            $this->seven,
            $this->eight,
            $this->nine,
        ]);
    }
}
