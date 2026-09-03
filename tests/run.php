<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$only = $argv[1] ?? null;

$files = array_merge(
    glob(__DIR__ . '/unit/*_test.php') ?: [],
    glob(__DIR__ . '/http/*_test.php') ?: []
);
sort($files);

foreach ($files as $file) {
    if ($only !== null && !str_contains($file, $only)) {
        continue;
    }
    require $file;
}

$currentFile = null;
foreach (TestRegistry::$cases as $case) {
    try {
        ($case['fn'])();
        TestRegistry::$passed++;
        fwrite(STDOUT, '.');
    } catch (SkippedTest $e) {
        TestRegistry::$skipped++;
        TestRegistry::$skips[] = $case['name'] . ' — ' . $e->getMessage();
        fwrite(STDOUT, 's');
    } catch (Throwable $e) {
        TestRegistry::$failed++;
        TestRegistry::$failures[] = $case['name'] . "\n    " . $e->getMessage();
        fwrite(STDOUT, 'F');
    }
}

fwrite(STDOUT, "\n\n");

foreach (TestRegistry::$skips as $skip) {
    fwrite(STDOUT, "SKIP  {$skip}\n");
}
if (TestRegistry::$skips !== []) {
    fwrite(STDOUT, "\n");
}

foreach (TestRegistry::$failures as $failure) {
    fwrite(STDOUT, "FAIL  {$failure}\n");
}
if (TestRegistry::$failures !== []) {
    fwrite(STDOUT, "\n");
}

fwrite(STDOUT, sprintf(
    "%d pass, %d fail, %d skip (%d test)\n",
    TestRegistry::$passed,
    TestRegistry::$failed,
    TestRegistry::$skipped,
    count(TestRegistry::$cases)
));

exit(TestRegistry::$failed > 0 ? 1 : 0);
