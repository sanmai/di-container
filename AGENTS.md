# AGENTS.md

This library is a PSR-11 dependency injection container. It resolves dependencies with reflection. It has few source files, no configuration format, and no compilation step.

@README.md

README.md specifies the behavior. This file explains what README.md does not.

## Commands

Run all the CI steps:
```bash
make -j -k
```

Run one test:
```bash
vendor/bin/phpunit tests/ContainerTest.php --filter testItBuildsSimpleObjects
```

Correct the code style with PHP CS Fixer:
```bash
make cs
```

Run PHPBench with OPcache and JIT:
```bash
make benchmark
```

## Source

| File | Content |
|------|---------|
| `src/Container.php` | All the logic: registration, resolution, autowiring, and introspection |
| `src/Builder.php` | `Builder<T>` declares one method, `build(): T`. The template is covariant. |
| `src/Exception.php` | `extends InvalidArgumentException implements NotFoundExceptionInterface` |

## Internal Invariants

The container retains its state in internal arrays. The unit tests cover these rules thoroughly:

- **Each service ID is in one registration array only.** The registration arrays are `prebuilt`, `factories`, `builders`, and `implementations` (update this file when adding a new array). `bind()` and `inject()` call `remove()` first, and thus an ID cannot be in two of them. `getIterator()` follows this rule: it joins these four arrays and expects no duplicate keys. The fifth array, `values`, is a resolution cache; an ID can be in `values` alongside its registration after `get()` resolves it.
- **The iterator ignores `values`.** `get()` puts each resolved service into `values`. The iterator yields the registrations only. As a result, you get the same sequence before and after you resolve a service.
- **The container registers a factory for itself.** The constructor puts `static fn(self $container): self => $container` into `factories[ContainerInterface::class]`, before it reads its own parameters. `get()` calls a factory with `$this`, and thus the factory gives the clone when a clone calls it. The registration also lets `providersForType()` find the container for a constructor parameter of that type, and thus a clone autowires the clone. `remove()` deletes the registration as it does for any other service. The container registers itself first, and thus it comes first in `getIterator()`.
- **`setValueOrThrow()` does not cache the container itself.** The `$this !== $value` examination keeps `$this` out of `values`, thus a clone finds no entry for `ContainerInterface::class` and the factory gives the clone. A value that the user registers is not `$this`, thus the cache keeps it and a clone reuses it as is. The container needs no `__clone()`, thus a subclass can still make `__clone()` private to forbid cloning.
- **`bind()` examines `is_callable()` before `Builder`.** A callable object that also implements `Builder` becomes a factory. This keeps the behavior of the initial code. The `CallableBuilder` fixture exists to test this rule.
- **`inject()` examines the type before it calls `remove()`.** If the type is not correct, `inject()` throws an exception and the container does not change.
- **`assertType()` does not examine non-class IDs.** It ignores an ID that contains a dot, and an ID that does not contain a backslash. This lets `bind('app.repository', ...)` operate.
- **`get()` examines `implementations[$id] !== $id`.** Without this examination, `get()` calls itself forever when you register a class as its own implementation.
- **Iteration hides its implementation.** `getIterator()` returns `Traversable`, not `Generator`. The class declares `IteratorAggregate<array-key, class-string<object>|non-empty-string>`, not `IteratorAggregate<int, ...>`. Both types promise the caller one thing only: a `foreach` loop gives the service IDs. Thus you can change the keys and the iterator class later.
- **`has()` is pessimistic.** It reports the registrations and the cached values. It returns `false` for a class that the container can autowire, because it does not try.

## Limits by Design

These are not defects. Each one has a test.

- The container does not resolve composite types, such as unions and intersections. If the parameter is optional, the container uses the default value. If the parameter is necessary, the container cannot make the service.
- The container ignores variadic parameters and gives no values for them.
- The container does not inject built-in types, such as `int` and `string`. If the parameter is optional, the container uses the default value.
- The container resolves an interface only if one registration makes a compatible type. If no registration makes one, the container uses the default value. If two or more registrations make one, the container cannot resolve the parameter.
- The container does not find circular dependencies, yet. The first test of such a service shows the error.

## Quality Gates

- **Infection must report 100%**: Each new branch, `unset()`, and comparison must have a test that kills its mutant. If a mutant stays alive, first look for code that does nothing. Then look for a missing test.
- **PHPStan** runs three times: `level: max` on `src` with `.phpstan.src.neon`, `level: max` on `tests/Fixtures` with `.phpstan.fixtures.neon`, and `level: 2` on `src` and `tests` together with `.phpstan.neon`. The fixtures have the maximum level because they use the generic annotations of the library.
- **PHPUnit** runs with `requireCoverageMetadata`, `failOnRisky`, and a random order. Each new test class must have `#[CoversClass]`.
- **CI examines more configurations than a local run.** CI runs the tests with various PHP versions up to `latest`. It runs them one more time with `psr/container` v1 in the place of v2. A local run uses one PHP version. Thus only CI finds an error that occurs with v1 alone, or with PHP 8.N alone.
- The CI test job removes phpstan, infection, and php-cs-fixer before it installs the dependencies. Do not make a test that needs a tool other than PHPUnit.

## Benchmarks

`benchmarks/generate-fixtures.php` makes the fixtures, Git-ignored. `benchmarks/README.md` gives each benchmark, the scenario, and the code path that it measures.

@benchmarks/README.md

When you add a benchmark, add a row for it to the table in `benchmarks/README.md`. That file is not internal: the benchmark workflow adds it to a comment on each pull request that changes a `.php` file, below the results. If you omit the row, the comment shows a measurement with no explanation.

To get stable and meaningful results, run the variants in turn (A/B/A/B), attach the process to one core, and change one file only between the variants.

## Conventions

- Expose only the details that you cannot hide.
- Put the data providers above the tests that use them.
- Keep the fixtures in `tests/Fixtures/`. Use an existing fixture before you make a new one.
- Each PHP file starts with the BSD header from `LICENSE`. Do not write the header manually: `make cs` adds it.
- `make cs` also applies Yoda conditions, strict comparisons, and `use function` imports for the global functions.
