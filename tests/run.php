<?php
// Minimal test runner, no dependencies. Every tests/*_test.php file defines
// functions named test_*; each is called once. Usage: php tests/run.php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

final class TestFailure extends Exception {}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new TestFailure(
            ($message !== '' ? "$message: " : '') .
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new TestFailure($message !== '' ? $message : 'expected true');
    }
}

function fixture(string $name): array
{
    $json = json_decode((string) file_get_contents(__DIR__ . "/fixtures/$name.json"), true);
    if (!is_array($json)) {
        throw new RuntimeException("Fixture $name is missing or invalid; run php scripts/save_fixtures.php");
    }
    return $json;
}

// A fresh in-memory database loaded from fixtures, shared helper for store and query tests.
function fixture_store(): Store
{
    $store = new Store(Db::open(':memory:'));
    $fetcher = new Fetcher(new ApiClient('test', __DIR__ . '/fixtures'), $store);
    $fetcher->fetchMembers('JUQRRL8');
    $fetcher->fetchWarData('JUQRRL8');
    return $store;
}

$files = glob(__DIR__ . '/*_test.php') ?: [];
foreach ($files as $file) {
    require_once $file;
}

$passed = 0;
$failed = 0;
foreach (get_defined_functions()['user'] as $fn) {
    if (!str_starts_with($fn, 'test_')) {
        continue;
    }
    try {
        $fn();
        $passed++;
        echo "  ok   $fn\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $fn\n       " . $e->getMessage() . "\n";
        if (!$e instanceof TestFailure) {
            echo "       at " . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }
}
echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
