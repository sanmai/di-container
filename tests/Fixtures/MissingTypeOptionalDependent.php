<?php

declare(strict_types=1);

namespace Tests\DIContainer\Fixtures;

use Tests\DIContainer\Fixtures\Absent\AbsentInterface;

/**
 * Optional dependency whose type cannot be loaded at all (the package providing
 * AbsentInterface is not installed), mirroring Symfony's optional collaborators
 * such as QuestionHelper's ?EventDispatcherInterface. The container must defer to
 * the default value instead of reflecting a non-existent type.
 */
class MissingTypeOptionalDependent
{
    public function __construct(
        private readonly ?AbsentInterface $optional = null,
    ) {}

    public function getOptional(): ?object
    {
        return $this->optional;
    }
}
