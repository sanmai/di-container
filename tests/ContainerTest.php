<?php

/**
 * Copyright (c) 2017, Maks Rafalko
 * Copyright (c) 2025, Alexey Kopytko
 *
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its
 *    contributors may be used to endorse or promote products derived from
 *    this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 */

declare(strict_types=1);

namespace Tests\DIContainer;

use DIContainer\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\DIContainer\Fixtures\ComplexObject;
use Tests\DIContainer\Fixtures\ComplexObjectBuilder;
use Tests\DIContainer\Fixtures\DependentObject;
use Tests\DIContainer\Fixtures\NamedObjectInterface;
use Tests\DIContainer\Fixtures\SimpleObject;

#[CoversClass(Container::class)]
class ContainerTest extends TestCase
{
    public function testItBuildsSimpleObjects(): void
    {
        $container = new Container();
        $object = $container->get(SimpleObject::class);

        $this->assertInstanceOf(SimpleObject::class, $object);

        $object2 = $container->get(SimpleObject::class);
        $this->assertSame($object, $object2);

        $dependentObject = $container->get(DependentObject::class);

        $this->assertSame($object, $dependentObject->getSimpleObject());
    }

    public function testItWorksWithBuilderObject(): void
    {
        $container = new Container([
            ComplexObject::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $object = $container->get(ComplexObject::class);

        $this->assertSame('hello', $object->getName());

        $this->assertSame($container->get(SimpleObject::class), $object->getObject());
    }

    public function testItResolvesInterfaceBuilders(): void
    {
        $container = new Container([
            NamedObjectInterface::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $object = $container->get(NamedObjectInterface::class);
        $this->assertSame('hello', $object->getName());
    }
}
