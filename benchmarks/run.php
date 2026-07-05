<?php declare(strict_types = 1);

/**
 * Benchmark CLI entry point — delegates to {@see Bench\Runner}.
 */

require __DIR__ . '/../tests/bootstrap.php';



require __DIR__ . '/../tests/index_matrix_lib.php';
require __DIR__ . '/lib/Stats.php';
require __DIR__ . '/lib/Table.php';
require __DIR__ . '/lib/Bench.php';
require __DIR__ . '/lib/EngineCompare.php';
require __DIR__ . '/lib/Cases.php';
require __DIR__ . '/lib/Track.php';

$runner = new \Bench\Runner($argv);
exit($runner->run());
