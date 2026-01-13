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

namespace DIContainer;

use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

use function array_key_exists;
use function count;
use function get_class;
use function is_a;
use function is_callable;
use function Pipeline\take;
use function reset;
use function sprintf;
use function str_contains;

class Container implements ContainerInterface
{
    /**
     * @var array<class-string<object>|non-empty-string, object>
     */
    private array $values = [];

    /**
     * @var array<class-string<object>|non-empty-string, callable>
     */
    private array $factories = [];

    /**
     * @var array<class-string<object>|non-empty-string, class-string<Builder<object>>>
     */
    private array $builders = [];

    /**
     * @param iterable<class-string<object>, callable|class-string<Builder<object>>> $values
     * @param iterable<non-empty-string, callable|class-string<Builder<object>>> $bindings
     */
    public function __construct(iterable $values = [], iterable $bindings = [])
    {
        $this->values[ContainerInterface::class] = $this;

        foreach ($values as $id => $value) {
            $this->set($id, $value);
        }

        foreach ($bindings as $id => $binding) {
            $this->bind($id, $binding);
        }
    }

    /**
     * Register a factory or builder for a class-based service ID.
     *
     * @template T of object
     * @param class-string<T> $id
     * @param class-string<Builder<T>>|callable(static): T $value
     */
    public function set(string $id, callable|string $value): void
    {
        $this->bind($id, $value);
    }

    /**
     * Register a factory or builder for a non-class service ID (e.g., 'app.locator').
     *
     * @param non-empty-string $id
     * @param class-string<Builder<object>>|callable(static): object $value
     */
    public function bind(string $id, callable|string $value): void
    {
        unset($this->values[$id]);

        // A value can be a callable and also implement our `Builder` interface:
        // we must treat such cases as factories, not builders, to ensure
        // backward compatibility with the existing code that uses callables.
        if (is_callable($value)) {
            $this->factories[$id] = $value;
            return;
        }

        $this->builders[$id] = $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @phpstan-return T
     */
    private function setValueOrThrow(string $id, object $value): object
    {
        // Break the contract to skip the type check for IDs that do not look like a valid namespaced PHP class name
        if (str_contains($id, '.') || !str_contains($id, '\\')) {
            $this->values[$id] = $value;

            /** @var T */
            return $value;
        }

        if (!$value instanceof $id) {
            throw new Exception(sprintf('Expected instance of %s, got %s', $id, get_class($value)));
        }

        $this->values[$id] = $value;

        return $value;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @return T
     */
    public function get(string $id)
    {
        if (array_key_exists($id, $this->values)) {
            return $this->values[$id];
        }

        if (array_key_exists($id, $this->builders)) {
            /** @var Builder<T> $builder */
            $builder = $this->get($this->builders[$id]);

            return $this->setValueOrThrow($id, $builder->build());
        }

        if (array_key_exists($id, $this->factories)) {
            /** @var T $value */
            $value = $this->factories[$id]($this);

            return $this->setValueOrThrow($id, $value);
        }

        $value = $this->createService($id);

        if (null === $value) {
            throw new Exception(sprintf('Unknown service "%s"', $id));
        }

        return $this->setValueOrThrow($id, $value);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @phpstan-return ?T
     */
    private function createService(string $id): ?object
    {
        $reflectionClass = new ReflectionClass($id);
        $constructor = $reflectionClass->getConstructor();

        if (!$reflectionClass->isInstantiable()) {
            return null;
        }

        if (null === $constructor || 0 === $constructor->getNumberOfParameters()) {
            return $reflectionClass->newInstance();
        }

        $resolvedArguments = take($constructor->getParameters())
            ->map($this->resolveParameter(...))
            ->toList();

        // Check if we identified all parameters for the service
        if (count($resolvedArguments) !== $constructor->getNumberOfParameters()) {
            return null;
        }

        return $reflectionClass->newInstanceArgs($resolvedArguments);
    }

    /**
     * Builds a potentially incomplete list of arguments for a constructor; as list of arguments may
     * contain null values, we use a generator that can yield none or one value as an option type.
     *
     * @return iterable<array-key, object>
     */
    private function resolveParameter(ReflectionParameter $parameter): iterable
    {
        // Variadic parameters need hand-weaving
        if ($parameter->isVariadic()) {
            return;
        }

        $paramType = $parameter->getType();

        // Not considering composite types, such as unions or intersections, for now
        if (!$paramType instanceof ReflectionNamedType) {
            throw new Exception('Composite types are not supported');
        }

        // Only attempt to resolve a non-built-in named type (a class/interface)
        if ($paramType->isBuiltin()) {
            return;
        }

        /** @var class-string $paramTypeName */
        $paramTypeName = $paramType->getName();

        // Found an instantiable class, done
        if ((new ReflectionClass($paramTypeName))->isInstantiable()) {
            yield $this->get($paramTypeName);

            return;
        }

        // Look for a factory that can create an instance of an interface or abstract class
        $matchingTypes = $this->providersForType($paramTypeName);

        // We expect exactly one factory to match the type, otherwise we cannot resolve the parameter
        if (1 !== count($matchingTypes)) {
            return;
        }

        yield $this->get(reset($matchingTypes));
    }

    /**
     * Retrieves the class or interface names of all registered factories/builders that can produce instances of the given type.
     * This includes direct implementations, subclasses, or the type itself.
     *
     * @template T of object
     * @param class-string<T> $type the class or interface name to find factories for
     * @return list<class-string<object>> a list of factory IDs (class-strings) that are compatible with the given type
     */
    private function providersForType(string $type): array
    {
        /** @var list<class-string<object>> */
        return take($this->factories, $this->builders)
            ->keys()
            ->filter(static fn(string $id) => is_a($id, $type, true))
            ->toList();
    }

    public function has(string $id): bool
    {
        if (array_key_exists($id, $this->values)) {
            return true;
        }

        if (array_key_exists($id, $this->factories)) {
            return true;
        }

        if (array_key_exists($id, $this->builders)) {
            return true;
        }

        // Very pessimistic; could probably try to create it.
        return false;
    }
}
