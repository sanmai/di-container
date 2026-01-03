<?php

/**
 * Copyright (c) 2017, Maks Rafalko
 * Copyright (c) 2020, Théo FIDRY
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
use DIContainer\Exception;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\DIContainer\Fixtures\ComplexDepender;
use Tests\DIContainer\Fixtures\ComplexObject;
use Tests\DIContainer\Fixtures\ComplexObjectBuilder;
use Tests\DIContainer\Fixtures\DependentObject;
use Tests\DIContainer\Fixtures\NamedObjectInterface;
use Tests\DIContainer\Fixtures\NameNeeder;
use Tests\DIContainer\Fixtures\SimpleObject;
use Tests\DIContainer\Fixtures\SomeAbstractObject;
use Tests\DIContainer\Fixtures\VariadicConstructor;
use Closure;
use SplFileInfo;

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

        $object = $container->get(NameNeeder::class);

        $this->assertSame('hello', $object->getName());
    }

    public function testItThrowsOnUnexpectedTypesReturnedFromFactories(): void
    {
        $container = new Container([
            SomeAbstractObject::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Expected instance of .*SomeAbstractObject, got .*ComplexObject/');

        $container->get(SomeAbstractObject::class);
    }

    public function testItThrowsOnAbstractClasses(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Unknown service ".*SomeAbstractObject"/');

        $container->get(SomeAbstractObject::class);
    }

    public function testItThrowsOnInterfacesWithoutBuilder(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(NamedObjectInterface::class);
    }

    public function testItThrowsOnClassesItCannotBuild(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(ComplexObject::class);
    }

    public function testItThrowsOnClassesWithVariadicArguments(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(VariadicConstructor::class);
    }

    public function testItThrowsOnClassesWithCompositeArguments(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Composite types are not supported');

        $container->get(ComplexDepender::class);
    }

    public function testItThrowsIfMultipleBuilders(): void
    {
        $container = new Container([
            NamedObjectInterface::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
            ComplexObject::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $this->expectExceptionMessage('Unknown service');
        $container->get(NameNeeder::class);
    }

    public function testItIgnoresRedundantBuilders(): void
    {
        $container = new Container([
            SimpleObject::class => static fn(Container $container) => new SimpleObject(),
        ]);

        $object = $container->get(DependentObject::class);

        $this->assertInstanceOf(DependentObject::class, $object);
    }

    public function testItUnderstandsBuilders(): void
    {
        $container = new Container([
            NamedObjectInterface::class => ComplexObjectBuilder::class,
        ]);

        $object = $container->get(NamedObjectInterface::class);

        $this->assertSame('hello', $object->getName());

        $nameNeeder = $container->get(NameNeeder::class);

        $this->assertSame('hello', $nameNeeder->getName());
    }

    public function testItHas(): void
    {
        $container = new Container([
            NamedObjectInterface::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $this->assertTrue($container->has(NamedObjectInterface::class));

        $this->assertFalse($container->has(SimpleObject::class));

        $container->get(SimpleObject::class);

        $this->assertTrue($container->has(SimpleObject::class));

        $container->set(
            NameNeeder::class,
            static fn(Container $container) => new NameNeeder($container->get(NamedObjectInterface::class))
        );

        $this->assertSame('hello', $container->get(NameNeeder::class)->getName());
    }

    public function testItCanBeExtended(): void
    {
        $container = new class ([]) extends Container {};

        $this->assertFalse($container->has(NamedObjectInterface::class));
    }

    public function testItSkipsTypeCheckForNonNamespacedIds(): void
    {
        // Edge case: SplFileInfo is a real non-namespaced class, but we return Closure.
        // Type check is skipped because the ID lacks a namespace separator.
        $container = new Container([
            SplFileInfo::class => static fn() => static fn() => null,
        ]);

        $object = $container->get(SplFileInfo::class);

        $this->assertInstanceOf(Closure::class, $object);
    }

    public function testItSkipsTypeCheckForDottedIds(): void
    {
        $container = new Container([
            'app.locator' => static fn() => new SimpleObject(),
        ]);

        $object = $container->get('app.locator');

        $this->assertInstanceOf(SimpleObject::class, $object);
    }

    public function testItInjectsItself(): void
    {
        $container = new Container();

        $this->assertSame($container, $container->get(ContainerInterface::class));
    }

    public function testItAllowsOverridingContainerInterface(): void
    {
        // Overriding Container::class does not affect ContainerInterface::class.
        // This is intentional: ContainerInterface always returns $this unless
        // explicitly overridden. Users who override concrete Container::class
        // while depending on ContainerInterface get what they asked for.
        $custom = new Container();

        $container = new Container([
            Container::class => static fn() => $custom,
        ]);

        $this->assertSame($custom, $container->get(Container::class));
        $this->assertSame($container, $container->get(ContainerInterface::class));
    }
}
