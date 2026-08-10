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
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\DIContainer\Fixtures\CallableBuilder;
use Tests\DIContainer\Fixtures\ComplexDepender;
use Tests\DIContainer\Fixtures\ComplexObject;
use Tests\DIContainer\Fixtures\ComplexObjectBuilder;
use Tests\DIContainer\Fixtures\CompositeDefaultDependent;
use Tests\DIContainer\Fixtures\DependentObject;
use Tests\DIContainer\Fixtures\ExtendedContainer;
use Tests\DIContainer\Fixtures\NamedObjectInterface;
use Tests\DIContainer\Fixtures\NameNeeder;
use Tests\DIContainer\Fixtures\NameProvider;
use Tests\DIContainer\Fixtures\NameProvidingContainer;
use Tests\DIContainer\Fixtures\BuiltinDefaultDependent;
use Tests\DIContainer\Fixtures\MissingTypeOptionalDependent;
use Tests\DIContainer\Fixtures\NameNeederOptional;
use Tests\DIContainer\Fixtures\OptionalInterfaceDependent;
use Tests\DIContainer\Fixtures\SimpleObject;
use Tests\DIContainer\Fixtures\SomeAbstractObject;
use Tests\DIContainer\Fixtures\TypedVariadicConstructor;
use Tests\DIContainer\Fixtures\VariadicConstructor;
use Closure;
use SplFileInfo;

use function iterator_to_array;

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

        $this->assertSame(ComplexObject::DEFAULT_ID, $object->getId());
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

    public function testItHandlesClassesWithVariadicArguments(): void
    {
        $container = new Container();

        $object = $container->get(VariadicConstructor::class);

        $this->assertSame([], $object->getInputs());
    }

    public function testItSkipsTypedVariadicParameters(): void
    {
        $container = new Container();

        $object = $container->get(TypedVariadicConstructor::class);

        $this->assertInstanceOf(TypedVariadicConstructor::class, $object);
        $this->assertSame($container->get(SimpleObject::class), $object->getObject());
        $this->assertSame([], $object->getVariadicDependencies());
    }

    public function testItThrowsOnClassesWithCompositeArgumentsWithoutDefault(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(ComplexDepender::class);
    }

    public function testItResolvesCompositeArgumentWithDefault(): void
    {
        $container = new Container();

        $object = $container->get(CompositeDefaultDependent::class);

        $this->assertInstanceOf(CompositeDefaultDependent::class, $object);
        $this->assertNull($object->getOptionalCompositeDependency());
    }

    public static function provideNameNeeders(): iterable
    {
        yield [NameNeeder::class];
        yield [NameNeederOptional::class];
    }

    #[DataProvider('provideNameNeeders')]
    public function testItThrowsIfMultipleBuilders(string $nameNeeder): void
    {
        $container = new Container([
            NamedObjectInterface::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
            ComplexObject::class => static fn(Container $container) => $container->get(ComplexObjectBuilder::class)->build(),
        ]);

        $this->expectExceptionMessage('Unknown service');
        $container->get($nameNeeder);
    }

    public function testItIgnoresRedundantBuilders(): void
    {
        $container = new Container([
            SimpleObject::class => static fn(Container $container) => new SimpleObject(),
        ]);

        $object = $container->get(DependentObject::class);

        $this->assertInstanceOf(DependentObject::class, $object);
    }

    public function testItHandlesCallableBuilders(): void
    {
        $builder = new CallableBuilder("example");

        $container = new Container([
            ComplexObject::class => $builder,
        ]);

        $object = $container->get(ComplexObject::class);

        $this->assertInstanceOf(ComplexObject::class, $object);
        $this->assertSame("example", $object->getName());
    }

    public function testItUnderstandsBuilders(): void
    {
        $container = new Container([
            NamedObjectInterface::class => ComplexObjectBuilder::class,
        ]);

        $this->assertTrue($container->has(NamedObjectInterface::class));

        $object = $container->get(NamedObjectInterface::class);

        $this->assertSame('hello', $object->getName());

        $this->assertFalse($container->has(NameNeeder::class));
        $nameNeeder = $container->get(NameNeeder::class);
        $this->assertTrue($container->has(NameNeeder::class));

        $this->assertSame('hello', $nameNeeder->getName());
    }

    public function testItUnderstandsImplementationClassNames(): void
    {
        $container = new Container([
            NamedObjectInterface::class => NameProvider::class,
        ]);

        $this->assertTrue($container->has(NamedObjectInterface::class));

        $object = $container->get(NamedObjectInterface::class);

        $this->assertInstanceOf(NameProvider::class, $object);
        $this->assertSame('hello', $object->getName());

        $this->assertSame($object, $container->get(NameProvider::class));

        $this->assertSame('hello', $container->get(NameNeeder::class)->getName());

        $container->set(SimpleObject::class, SimpleObject::class);

        $this->assertInstanceOf(SimpleObject::class, $container->get(SimpleObject::class));
    }

    public function testItThrowsOnSelfReferencingInterfaces(): void
    {
        $container = new Container([
            NamedObjectInterface::class => NamedObjectInterface::class,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(NamedObjectInterface::class);
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
        $container = new Container(bindings: [
            'app.locator' => static fn() => new SimpleObject(),
        ]);

        $object = $container->get('app.locator');

        $this->assertInstanceOf(SimpleObject::class, $object);
    }

    public function testBindMethod(): void
    {
        $container = new Container();
        $container->bind('app.service', static fn() => new SimpleObject());

        $this->assertInstanceOf(SimpleObject::class, $container->get('app.service'));
    }

    public function testBindMethodWithBuilder(): void
    {
        $container = new Container();
        $container->bind('app.complex', ComplexObjectBuilder::class);

        $this->assertInstanceOf(ComplexObject::class, $container->get('app.complex'));
    }

    public function testItInjectsItself(): void
    {
        $container = new Container();

        $this->assertSame($container, $container->get(ContainerInterface::class));
    }

    public function testItAllowsOverridingContainerInterface(): void
    {
        $custom = new Container();

        $container = new Container([
            ContainerInterface::class => static fn() => $custom,
        ]);

        $this->assertSame($custom, $container->get(ContainerInterface::class));
    }

    public function testItContainerBindingsIndependent(): void
    {
        // Bindings are independent: overriding Container::class does not
        // affect ContainerInterface::class (which still returns $this).
        $custom = new Container();

        $container = new Container([
            Container::class => static fn() => $custom,
        ]);

        $this->assertSame($custom, $container->get(Container::class));
        $this->assertSame($container, $container->get(ContainerInterface::class));
    }

    public function testRegisteredProviderWinsOverContainerSelfType(): void
    {
        $container = new NameProvidingContainer([
            NamedObjectInterface::class => static fn() => new NameProvider(),
        ]);

        $this->assertSame('hello', $container->get(NameNeeder::class)->getName());
        $this->assertNotSame('the container itself', $container->get(NameNeeder::class)->getName());
    }

    public function testSubclassCanUseCallableWithStaticType(): void
    {
        $container = new ExtendedContainer();

        $result = $container->withService(
            SimpleObject::class,
            static fn(ExtendedContainer $c) => new SimpleObject()
        );

        $this->assertInstanceOf(SimpleObject::class, $result->get(SimpleObject::class));
    }

    public function testFactoryWithContainerParameter(): void
    {
        $container = new Container([
            SimpleObject::class => static fn(Container $c) => new SimpleObject(),
        ]);

        $this->assertInstanceOf(SimpleObject::class, $container->get(SimpleObject::class));
    }

    public function testFactorySelfType(): void
    {
        $container = new ExtendedContainer();
        $result = $container->withSelfFactory();

        $this->assertInstanceOf(SimpleObject::class, $result->get(SimpleObject::class));
    }

    public function testInjectPreBuiltObject(): void
    {
        $container = new Container();
        $injected = $this->createMock(NamedObjectInterface::class);
        $injected->method('getName')->willReturn('injected');

        $container->inject(NamedObjectInterface::class, $injected);

        // The injected instance must be discoverable when autowiring a dependent
        $this->assertSame('injected', $container->get(NameNeeder::class)->getName());
    }

    public function testPreBuiltObjectDependency(): void
    {
        $injected = $this->createMock(NamedObjectInterface::class);
        $injected->expects($this->once())
            ->method('getName')
            ->willReturn('injected');

        $container = new Container();
        $container->inject(NamedObjectInterface::class, $injected);

        $this->assertSame('injected', $container->get(NameNeeder::class)->getName());
    }

    public function testInjectTypeMismatchThrows(): void
    {
        $container = new Container();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/Expected instance of .*DependentObject, got .*SimpleObject/');

        $container->inject(DependentObject::class, new SimpleObject());
    }

    public function testInjectKeepsPriorRegistration(): void
    {
        $canary = new SimpleObject();

        $container = new Container([
            SimpleObject::class => static fn() => $canary,
        ]);

        $this->assertSame($canary, $container->get(SimpleObject::class));

        try {
            $container->inject(SimpleObject::class, new NameProvider());
            $this->fail('Expected a type mismatch exception');
        } catch (Exception $exception) {
            $this->assertStringContainsString('Expected instance of', $exception->getMessage());
        }

        $this->assertTrue($container->has(SimpleObject::class));
        $this->assertSame($canary, $container->get(SimpleObject::class));
    }

    public function testInjectOverridesBind(): void
    {
        $container = new Container();
        $container->bind(SimpleObject::class, static fn() => new SimpleObject());

        $injected = new SimpleObject();
        $container->inject(SimpleObject::class, $injected);

        $this->assertSame($injected, $container->get(SimpleObject::class));
    }

    public function testInjectOverridesResolvedService(): void
    {
        $container = new Container();
        $built = $container->get(SimpleObject::class);

        $injected = new SimpleObject();
        $container->inject(SimpleObject::class, $injected);

        $this->assertSame($injected, $container->get(SimpleObject::class));
        $this->assertNotSame($built, $container->get(SimpleObject::class));
    }

    public function testBindOverridesInject(): void
    {
        $container = new Container();
        $container->inject(SimpleObject::class, new SimpleObject());
        $injected = $container->get(SimpleObject::class);

        $rebound = new SimpleObject();
        $container->bind(SimpleObject::class, static fn() => $rebound);

        $this->assertSame($rebound, $container->get(SimpleObject::class));
        $this->assertNotSame($injected, $container->get(SimpleObject::class));

        $this->assertSame($rebound, $container->get(DependentObject::class)->getSimpleObject());
    }

    public function testBindOverridesBuilder(): void
    {
        $container = new Container();
        $container->set(NamedObjectInterface::class, ComplexObjectBuilder::class);
        $built = $container->get(NamedObjectInterface::class);

        $bound = new NameProvider();
        $container->set(NamedObjectInterface::class, static fn() => $bound);

        $this->assertNotSame($built, $container->get(NamedObjectInterface::class));
        $this->assertSame($bound, $container->get(NamedObjectInterface::class));

        $this->assertInstanceOf(NameProvider::class, $container->get(NamedObjectInterface::class));
        $this->assertNotInstanceOf(ComplexObject::class, $container->get(NamedObjectInterface::class));
    }

    public function testInjectSingletonBehavior(): void
    {
        $container = new Container();
        $object = new SimpleObject();

        $container->inject(SimpleObject::class, $object);

        $this->assertTrue($container->has(SimpleObject::class));
        $this->assertSame($object, $container->get(SimpleObject::class));
        $this->assertSame($object, $container->get(SimpleObject::class));
    }

    public function testItResolvesOptionalNullableInterfaceWithDefault(): void
    {
        $container = new Container();

        $object = $container->get(OptionalInterfaceDependent::class);

        $this->assertInstanceOf(OptionalInterfaceDependent::class, $object);
        $this->assertInstanceOf(SimpleObject::class, $object->getRequired());
        $this->assertNull($object->getOptional());
    }

    public function testItResolvesOptionalDependencyWithUnloadableType(): void
    {
        $container = new Container();

        $object = $container->get(MissingTypeOptionalDependent::class);

        $this->assertInstanceOf(MissingTypeOptionalDependent::class, $object);
        $this->assertNull($object->getOptional());
    }

    public function testItResolvesOptionalBuiltinWithDefault(): void
    {
        $container = new Container();

        $object = $container->get(BuiltinDefaultDependent::class);

        $this->assertSame(42, $object->getId());
        $this->assertNull($object->getNamed());
    }

    public function testItBindsAfterSkippingScalarDefault(): void
    {
        $container = new Container();

        $injected = $this->createMock(NamedObjectInterface::class);
        $container->inject(NamedObjectInterface::class, $injected);

        $object = $container->get(BuiltinDefaultDependent::class);

        $this->assertSame(42, $object->getId());
        $this->assertSame($injected, $object->getNamed());
    }

    /**
     * Every way to register NamedObjectInterface with set().
     */
    public static function provideRegistrationKinds(): iterable
    {
        yield 'factory' => [static fn() => new NameProvider()];

        // An invocable builder is a factory: callables win, as they do in bind()
        yield 'invocable builder' => [new CallableBuilder()];

        yield 'builder' => [ComplexObjectBuilder::class];

        yield 'implementation' => [NameProvider::class];
    }

    #[DataProvider('provideRegistrationKinds')]
    public function testUnbindForgetsAnyRegistration(callable|string $registration): void
    {
        $container = new Container();
        $container->set(NamedObjectInterface::class, $registration);

        $this->assertUnbindForgets($container, $registration);
    }

    public function testUnbindForgetsInjectedInstance(): void
    {
        $container = new Container();
        $injected = new NameProvider();

        $container->inject(NamedObjectInterface::class, $injected);

        $this->assertUnbindForgets($container, $injected);
    }

    /**
     * A registration is visible through introspection, and unbind() leaves no trace of it.
     */
    private function assertUnbindForgets(Container $container, callable|string|object $registration): void
    {
        $this->assertTrue($container->has(NamedObjectInterface::class));

        // Introspection gives back exactly what was registered
        $this->assertSame([NamedObjectInterface::class => $registration], iterator_to_array($container));

        // Building the service must not keep it alive past unbind()
        $this->assertInstanceOf(NamedObjectInterface::class, $container->get(NamedObjectInterface::class));

        $container->unbind(NamedObjectInterface::class);

        $this->assertFalse($container->has(NamedObjectInterface::class));
        $this->assertSame([], iterator_to_array($container));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unknown service');

        $container->get(NamedObjectInterface::class);
    }

    public function testUnbindIgnoresUnknownServiceId(): void
    {
        $container = new Container();

        $container->unbind(NamedObjectInterface::class);

        $this->assertFalse($container->has(NamedObjectInterface::class));
    }

    public function testRejectedInjectionLeavesContainerUntouched(): void
    {
        $container = new Container();
        $accepted = new SimpleObject();
        $container->inject(SimpleObject::class, $accepted);

        try {
            $container->inject(SimpleObject::class, new NameProvider());
            $this->fail('Expected a type mismatch');
        } catch (Exception $e) {
            $this->assertStringContainsString('Expected instance of', $e->getMessage());
        }

        $this->assertSame($accepted, $container->get(SimpleObject::class));
    }

    public function testItIteratesOverEveryRegistration(): void
    {
        $factory = static fn() => new NameProvider();
        $injected = new SimpleObject();

        $container = new Container([
            NamedObjectInterface::class => $factory,
            ComplexObject::class => ComplexObjectBuilder::class,
            SomeAbstractObject::class => NameProvider::class,
        ]);
        $container->inject(SimpleObject::class, $injected);

        $this->assertEquals([
            NamedObjectInterface::class => $factory,
            ComplexObject::class => ComplexObjectBuilder::class,
            SomeAbstractObject::class => NameProvider::class,
            SimpleObject::class => $injected,
        ], iterator_to_array($container));
    }

    public function testItDoesNotIterateOverServicesBuiltOnDemand(): void
    {
        $container = new Container();

        $this->assertInstanceOf(SimpleObject::class, $container->get(SimpleObject::class));
        $this->assertTrue($container->has(SimpleObject::class));
        $this->assertTrue($container->has(ContainerInterface::class));

        $this->assertSame([], iterator_to_array($container));
    }

    public function testItCanBeIteratedOverMoreThanOnce(): void
    {
        $container = new Container([
            NamedObjectInterface::class => NameProvider::class,
        ]);

        $first = iterator_to_array($container);

        $this->assertSame($first, iterator_to_array($container));
    }

    public function testRebindingToAnotherKindDoesNotDuplicateAnId(): void
    {
        $container = new Container([
            NamedObjectInterface::class => NameProvider::class,
        ]);

        $container->set(NamedObjectInterface::class, ComplexObjectBuilder::class);

        $this->assertSame([
            NamedObjectInterface::class => ComplexObjectBuilder::class,
        ], iterator_to_array($container));
    }
}
