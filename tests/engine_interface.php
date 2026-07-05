<?php declare(strict_types = 1);

/**
 * Contract test: Engine Interface.
 *
 * Exit 0 on success, 1 on failure. Invoked by tests/run.php unless skipped.
 */

namespace Fast;

require __DIR__ . '/bootstrap.php';

use Fast\Engine\Flat;
use Fast\Engine\Striped;
use Fast\Contract\Engine;

/**
 * O1 regression: the Fast facade drives its engine through one method contract,
 * Engine, implemented by BOTH Flat and Striped. This guards three things:
 *
 *   1. both engines declare `implements Engine` (load-time parity);
 *   2. every method the facade invokes polymorphically on its engine is part of
 *      the interface (so a facade call can't depend on a method only one engine
 *      happens to have);
 *   3. a Engine-typed handle is actually drivable end to end.
 */

$fail = static function (string $m): never { \fwrite(\STDERR, $m . \PHP_EOL); exit(1); };

// 1. both engines implement the contract.
foreach ([Flat::class, Striped::class] as $cls) {
    $rc = new \ReflectionClass($cls);
    if (!$rc->implementsInterface(Engine::class)) {
        $fail($cls . ' must implement Engine');
    }
}

// 2. every method the facade calls on $this->engine must live on Engine.
//    (If a future edit adds an engine call to the facade, add it here; the test
//    then forces it onto the interface, which forces BOTH engines to provide it.)
$facadeDrivenMethods = [
    'set', 'get', 'delete', 'has', 'isNullType', 'count',
    'rewind', 'valid', 'current', 'key', 'next', 'seek',
    'lock', 'unlock',
    'compact', 'close', 'destroy',
];

$ifaceMethods = [];
foreach ((new \ReflectionClass(Engine::class))->getMethods() as $m) {
    $ifaceMethods[$m->getName()] = true;
}

foreach ($facadeDrivenMethods as $name) {
    if (!isset($ifaceMethods[$name])) {
        $fail('Engine is missing facade-driven method: ' . $name . '()');
    }
}

// Confirm the facade really does only drive the engine through these methods (so
// the curated list above cannot quietly fall out of date).
$facadeSrc = \file_get_contents(\dirname(__DIR__) . '/src/Fast.php');
if ($facadeSrc === false) {
    $fail('could not read Fast.php to verify engine call sites');
}
\preg_match_all('/\$this->engine->([a-zA-Z_]\w*)\s*\(/', $facadeSrc, $calls);
$allowedNonContract = ['attach' => true, 'initLocal' => true, 'stripes' => true];
foreach (\array_unique($calls[1]) as $called) {
    if (isset($allowedNonContract[$called])) { continue; } // construction / instanceof-guarded
    if (!isset($ifaceMethods[$called])) {
        $fail('Fast facade calls $engine->' . $called . '() which is not on Engine');
    }
}

// 3. a Engine-typed handle is drivable (local Flat behind the interface).
$flat = new Flat();
$flat->initLocal();              // construction-only, off the contract
$engine = $flat;                 // typed below
assertDrivable($engine, $fail);

/** @param callable(string):never $fail */
function assertDrivable(Engine $engine, callable $fail): void
{
    $engine->set('a', 1);
    $engine->set('b', 2);
    if ($engine->count() !== 2) { $fail('Engine handle: count mismatch'); }

    $v = null;
    if (!$engine->get('a', $v) || $v !== 1) { $fail('Engine handle: get mismatch'); }

    $seen = [];
    for ($engine->rewind(); $engine->valid(); $engine->next()) {
        $seen[] = $engine->key();
    }
    if ($seen !== ['a', 'b']) { $fail('Engine handle: iteration order mismatch'); }

    $engine->delete('a');
    if ($engine->count() !== 1) { $fail('Engine handle: delete mismatch'); }
}

echo 'engine interface ok' . \PHP_EOL;
