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
use function class_exists;
use function interface_exists;

class Container implements ContainerInterface
{
    /**
     * @var array<class-string<object>|non-empty-string, object>
     */
    private array $values = [];

    /**
     * @var array<class-string<object>|non-empty-string, object>
     */
    private array $prebuilt = [];

    /**
     * @var array<class-string<object>|non-empty-string, callable>
     */
    private array $factories = [];

    /**
     * @var array<class-string<object>|non-empty-string, class-string<Builder<object>>>
     */
    private array $builders = [];

    /** Placeholder marking an unresolved parameter */
    private readonly object $missing;

    /**
     * @param iterable<class-string<object>, callable|class-string<Builder<object>>> $values
     * @param iterable<non-empty-string, callable|class-string<Builder<object>>> $bindings
     */
    public function __construct(iterable $values = [], iterable $bindings = [])
    {
        // Cache the value letting a builder override it
        $this->values[ContainerInterface::class] = $this;

        $this->missing = new class {};

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
     * Inject a pre-built object instance directly into the container.
     *
     * @template T of object
     * @param class-string<T>|non-empty-string $id
     * @param T $value
     */
    public function inject(string $id, object $value): void
    {
        // Injected pre-built dependencies override everything else at the time of injection
        unset($this->values[$id], $this->factories[$id], $this->builders[$id]);

        self::assertType($id, $value);

        $this->prebuilt[$id] = $value;
    }

    /**
     * Stores a value, validating type for class-string IDs only.
     *
     * @template T of object
     *
     * @param class-string<T> $id accepts any string; non-class IDs skip validation
     * @phpstan-return T
     */
    private function setValueOrThrow(string $id, object $value): object
    {
        self::assertType($id, $value);

        $this->values[$id] = $value;

        /** @var T */
        return $value;
    }

    private static function assertType(string $id, object $value): void
    {
        // Break the contract to skip the type check for IDs that do not look like a valid namespaced PHP class name
        if (str_contains($id, '.') || !str_contains($id, '\\')) {
            return;
        }

        if (!$value instanceof $id) {
            throw new Exception(sprintf('Expected instance of %s, got %s', $id, get_class($value)));
        }
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

        // Consider pre-built instances last to give way to factories and builders
        if (array_key_exists($id, $this->prebuilt)) {
            return $this->prebuilt[$id];
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
            ->cast($this->findParameterValue(...))
            ->select($this->notMissing(...))
            ->toList();

        $requiredNumberOfParameters = $constructor->getNumberOfParameters();

        // Account for the variadic parameter (there can be only one, always optional)
        if ($constructor->isVariadic()) {
            $requiredNumberOfParameters -= 1;
        }

        // Check if we identified all parameters for the service
        if (count($resolvedArguments) !== $requiredNumberOfParameters) {
            return null;
        }

        return $reflectionClass->newInstanceArgs($resolvedArguments);
    }

    private function notMissing(mixed $value): bool
    {
        return $this->missing !== $value;
    }

    private function resolveDefaultValue(ReflectionParameter $parameter): mixed
    {
        return match ($parameter->isDefaultValueAvailable()) {
            true => $parameter->getDefaultValue(),
            default => $this->missing,
        };
    }

    /**
     * Returns a possible argument value for the constructor; it can return a specific computed
     * value just as well as a placeholder for the missing value.
     *
     * @return mixed
     */
    private function findParameterValue(ReflectionParameter $parameter): mixed
    {
        // Variadic parameters imply collections, which we do not provide, for now
        if ($parameter->isVariadic()) {
            return $this->missing;
        }

        $paramType = $parameter->getType();

        // Not considering composite types, such as unions or intersections, for now
        if (!$paramType instanceof ReflectionNamedType) {
            return $this->resolveDefaultValue($parameter);
        }

        /** @var class-string $paramTypeName */
        $paramTypeName = $paramType->getName();

        // Defer to a default value for built-in types and classes that cannot be reflected
        if (!class_exists($paramTypeName) && !interface_exists($paramTypeName)) {
            return $this->resolveDefaultValue($parameter);
        }

        // Found an instantiable class, done
        if ((new ReflectionClass($paramTypeName))->isInstantiable()) {
            return $this->get($paramTypeName);
        }

        // Look for a factory that can create an instance of an interface or abstract class
        $matchingTypes = $this->providersForType($paramTypeName);

        // We expect exactly one factory to match the type to resolve a parameter unambiguously
        if (1 === count($matchingTypes)) {
            return $this->get(reset($matchingTypes));
        }

        // But we should also consider default values if present and no default provider
        if ([] === $matchingTypes) {
            return $this->resolveDefaultValue($parameter);
        }

        return $this->missing;
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
        return take($this->factories, $this->builders, $this->prebuilt)
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
