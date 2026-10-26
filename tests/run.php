<?php

declare(strict_types=1);

// Zero-dependency test runner: php agents/tests/run.php [filter]
// Runs every public test* method of every *Test.php class under tests/.

require dirname(__DIR__).'/bootstrap.php';

use Workspace\Agents\Tests\AssertionFailed;
use Workspace\Agents\Tests\Skipped;
use Workspace\Agents\Tests\TestCase;

$filter = $argv[1] ?? null;
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (str_ends_with($file->getFilename(), 'Test.php')) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$passed = $failed = $skipped = $assertions = 0;
$failures = [];
$started = microtime(true);
foreach ($files as $file) {
    $relative = substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', __DIR__)) + 1);
    $class = 'Workspace\\Agents\\Tests\\'.str_replace('/', '\\', substr($relative, 0, -4));
    if (! class_exists($class) || ! is_subclass_of($class, TestCase::class)) {
        fwrite(STDERR, "not a TestCase: {$class}\n");
        $failed++;
        continue;
    }
    foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();
        $label = substr($class, strlen('Workspace\\Agents\\Tests\\')).'::'.$name;
        if (! str_starts_with($name, 'test') || ($filter !== null && ! str_contains($label, $filter))) {
            continue;
        }
        /** @var TestCase $case */
        $case = new $class();
        try {
            $case->setUp();
            try {
                $case->$name();
            } finally {
                $case->tearDown();
            }
            $passed++;
            echo "  ok    {$label}\n";
        } catch (Skipped $skip) {
            $skipped++;
            echo "  skip  {$label} — {$skip->getMessage()}\n";
        } catch (Throwable $error) {
            $failed++;
            echo "  FAIL  {$label}\n";
            $detail = $error instanceof AssertionFailed
                ? $error->getMessage()
                : $error::class.': '.$error->getMessage()."\n".$error->getTraceAsString();
            $failures[] = "{$label}\n    ".str_replace("\n", "\n    ", $detail);
        }
        $assertions += $case->assertions;
    }
}

foreach ($failures as $failure) {
    echo "\nFAILURE {$failure}\n";
}
printf(
    "\n%s: %d passed, %d failed, %d skipped, %d assertions in %.2fs\n",
    $failed === 0 ? 'OK' : 'FAILED',
    $passed, $failed, $skipped, $assertions, microtime(true) - $started,
);
exit($failed === 0 ? 0 : 1);
