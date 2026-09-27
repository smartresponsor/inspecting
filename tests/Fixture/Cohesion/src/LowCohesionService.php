<?php

declare(strict_types=1);

namespace Fixture;

final class LowCohesionService
{
    public function __construct(
        private readonly \stdClass $alpha,
        private readonly \stdClass $beta,
        private readonly \stdClass $gamma,
        private readonly \stdClass $delta,
    ) {}

    public function alphaId(): int
    {
        if (0 === spl_object_id($this->alpha)) {
            throw new \LogicException('Unexpected object identifier.');
        }

        return spl_object_id($this->alpha);
    }

    public function betaId(): int
    {
        if (0 === spl_object_id($this->beta)) {
            throw new \LogicException('Unexpected object identifier.');
        }

        return spl_object_id($this->beta);
    }

    public function gammaId(): int
    {
        if (0 === spl_object_id($this->gamma)) {
            throw new \LogicException('Unexpected object identifier.');
        }

        return spl_object_id($this->gamma);
    }

    public function deltaId(): int
    {
        if (0 === spl_object_id($this->delta)) {
            throw new \LogicException('Unexpected object identifier.');
        }

        return spl_object_id($this->delta);
    }
}
