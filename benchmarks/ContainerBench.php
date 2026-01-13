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

namespace Benchmarks\DIContainer;

use Benchmarks\DIContainer\Fixtures\A\FixtureA1;
use Benchmarks\DIContainer\Fixtures\A\FixtureA100;
use Benchmarks\DIContainer\Fixtures\C\FixtureC500;
use DIContainer\Container;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Warmup;

class ContainerBench
{
    private Container $container;

    public function setUp(): void
    {
        $this->container = new Container();
    }

    /**
     * Benchmark: Create container + resolve 100-class linear dependency chain.
     * Measures: Container setup + full autowiring cost.
     */
    #[Warmup(1)]
    #[Revs(50)]
    #[Iterations(3)]
    public function benchLinearChainCold(): void
    {
        $container = new Container();
        $container->get(FixtureA100::class);
    }

    /**
     * Benchmark: Resolve 100-class chain from existing container (first resolution).
     * Measures: Pure autowiring/reflection cost without container setup.
     */
    #[Warmup(1)]
    #[Revs(50)]
    #[Iterations(3)]
    #[BeforeMethods('setUp')]
    public function benchLinearChainWarm(): void
    {
        $this->container->get(FixtureA100::class);
    }

    /**
     * Benchmark: Re-fetch already-resolved 100-class chain.
     * Measures: Singleton cache retrieval cost.
     */
    #[Warmup(1)]
    #[Revs(250)]
    #[Iterations(3)]
    public function benchLinearChainCached(): void
    {
        static $container = null;

        if (null === $container) {
            $container = new Container();
            $container->get(FixtureA100::class);
        }

        $container->get(FixtureA100::class);
    }

    /**
     * Benchmark: Create container + instantiate 100 independent classes.
     * Measures: Bulk instantiation throughput for simple classes.
     */
    #[Warmup(1)]
    #[Revs(5)]
    #[Iterations(3)]
    public function benchIndependentClassesCold(): void
    {
        $container = new Container();

        for ($i = 1; $i <= 100; $i++) {
            $class = "Benchmarks\\DIContainer\\Fixtures\\B\\FixtureB{$i}";
            $container->get($class);
        }
    }

    /**
     * Benchmark: Instantiate 100 independent classes from existing container.
     * Measures: Pure instantiation cost for no-dependency classes.
     */
    #[Warmup(1)]
    #[Revs(5)]
    #[Iterations(3)]
    #[BeforeMethods('setUp')]
    public function benchIndependentClassesWarm(): void
    {
        for ($i = 1; $i <= 100; $i++) {
            $class = "Benchmarks\\DIContainer\\Fixtures\\B\\FixtureB{$i}";
            $this->container->get($class);
        }
    }

    /**
     * Benchmark: Create container + resolve 500-class deep dependency chain.
     * Measures: Performance with deep recursion (stress test).
     */
    #[Warmup(1)]
    #[Revs(5)]
    #[Iterations(3)]
    public function benchDeepChainCold(): void
    {
        $container = new Container();
        $container->get(FixtureC500::class);
    }

    /**
     * Benchmark: Resolve 500-class chain from existing container.
     * Measures: Deep autowiring cost.
     */
    #[Warmup(1)]
    #[Revs(5)]
    #[Iterations(3)]
    #[BeforeMethods('setUp')]
    public function benchDeepChainWarm(): void
    {
        $this->container->get(FixtureC500::class);
    }

    /**
     * Benchmark: Factory invocation with no parameters.
     * Measures: Minimal factory overhead.
     */
    #[Warmup(1)]
    #[Revs(250)]
    #[Iterations(3)]
    public function benchFactoryNoParams(): void
    {
        $container = new Container([
            FixtureA1::class => static fn() => new FixtureA1(),
        ]);
        $container->get(FixtureA1::class);
    }

    /**
     * Benchmark: Factory invocation with container parameter.
     * Measures: Factory overhead with explicit container access.
     */
    #[Warmup(1)]
    #[Revs(250)]
    #[Iterations(3)]
    public function benchFactoryWithContainer(): void
    {
        $container = new Container([
            FixtureA1::class => static fn(Container $c) => new FixtureA1(),
        ]);
        $container->get(FixtureA1::class);
    }
}
