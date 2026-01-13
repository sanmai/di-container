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

/**
 * Generates fixture classes for benchmarking the DI container.
 *
 * Fixture A: 100 classes with linear dependency chain (A100 -> A99 -> ... -> A1)
 * Fixture B: 1000 independent classes with no dependencies
 * Fixture C: 1000 classes with linear dependency chain (deep recursion test)
 */

$fixtureDir = __DIR__ . '/Fixtures';

@mkdir($fixtureDir . '/A', 0755, true);
@mkdir($fixtureDir . '/B', 0755, true);
@mkdir($fixtureDir . '/C', 0755, true);

// Fixture A: Linear dependency chain (100 classes)
echo "Generating Fixture A (100 classes with linear dependencies)...\n";

// First class has no dependency
$data = <<<'EOF'
    <?php

    declare(strict_types=1);

    namespace Benchmarks\DIContainer\Fixtures\A;

    class FixtureA1
    {
    }
    EOF;
file_put_contents($fixtureDir . '/A/FixtureA1.php', $data);

for ($i = 2; $i <= 100; $i++) {
    $prev = $i - 1;
    $data = <<<EOF
        <?php

        declare(strict_types=1);

        namespace Benchmarks\DIContainer\Fixtures\A;

        class FixtureA{$i}
        {
            public function __construct(FixtureA{$prev} \$dependency)
            {
            }
        }
        EOF;
    file_put_contents($fixtureDir . "/A/FixtureA{$i}.php", $data);
}

// Fixture B: Independent classes (1000 classes)
echo "Generating Fixture B (1000 independent classes)...\n";

for ($i = 1; $i <= 1000; $i++) {
    $data = <<<EOF
        <?php

        declare(strict_types=1);

        namespace Benchmarks\DIContainer\Fixtures\B;

        class FixtureB{$i}
        {
        }
        EOF;
    file_put_contents($fixtureDir . "/B/FixtureB{$i}.php", $data);
}

// Fixture C: Deep dependency chain (500 classes)
echo "Generating Fixture C (500 classes with linear dependencies)...\n";

// First class has no dependency
$data = <<<'EOF'
    <?php

    declare(strict_types=1);

    namespace Benchmarks\DIContainer\Fixtures\C;

    class FixtureC1
    {
    }
    EOF;
file_put_contents($fixtureDir . '/C/FixtureC1.php', $data);

for ($i = 2; $i <= 500; $i++) {
    $prev = $i - 1;
    $data = <<<EOF
        <?php

        declare(strict_types=1);

        namespace Benchmarks\DIContainer\Fixtures\C;

        class FixtureC{$i}
        {
            public function __construct(FixtureC{$prev} \$dependency)
            {
            }
        }
        EOF;
    file_put_contents($fixtureDir . "/C/FixtureC{$i}.php", $data);
}

echo "Done! Generated fixtures in {$fixtureDir}\n";
