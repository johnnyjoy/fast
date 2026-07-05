<?php declare(strict_types = 1);

/**
 * ref_ops.php must stay out of tests/run.php — it is a manual PHP diagnostic, not a contract gate.
 */

$run = (string) \file_get_contents(__DIR__ . '/run.php');

if (!\str_contains($run, "'ref_ops.php'")) {
    \fwrite(STDERR, "run.php must skip ref_ops.php (manual diagnostic, warning noise)\n");
    exit(1);
}

echo "ref ops skipped gate ok\n";
exit(0);
