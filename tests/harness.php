<?php

declare(strict_types=1);

/**
 * A dependency-free test harness.
 *
 * It is not a replacement for PHPUnit - it is 80 lines of PHP that let you
 * run the tests of this tutorial with nothing but `php tests/run.php`.
 */
final class Harness
{
    private static int $passed = 0;

    /** @var list<string> */
    private static array $failures = [];

    private static string $current = '';

    public static function test(string $name, callable $body): void
    {
        self::$current = $name;

        try {
            $body();
            self::$passed++;
            echo "  ok   {$name}\n";
        } catch (Throwable $e) {
            $message = self::$current . ': ' . $e->getMessage();
            self::$failures[] = $message;
            echo "  FAIL {$name}\n         " . $e->getMessage() . "\n";
        }
    }

    public static function assertSame(mixed $expected, mixed $actual, string $context = ''): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException(
                ($context !== '' ? $context . ' - ' : '')
                . 'expected ' . self::export($expected) . ', got ' . self::export($actual)
            );
        }
    }

    public static function assertTrue(mixed $value, string $context = ''): void
    {
        if ($value !== true) {
            throw new RuntimeException(($context !== '' ? $context . ' - ' : '') . 'expected true, got ' . self::export($value));
        }
    }

    public static function assertFalse(mixed $value, string $context = ''): void
    {
        if ($value !== false) {
            throw new RuntimeException(($context !== '' ? $context . ' - ' : '') . 'expected false, got ' . self::export($value));
        }
    }

    public static function assertThrows(string $expectedClass, callable $body): void
    {
        try {
            $body();
        } catch (Throwable $e) {
            if (!$e instanceof $expectedClass) {
                throw new RuntimeException('expected ' . $expectedClass . ', got ' . get_class($e));
            }

            return;
        }

        throw new RuntimeException('expected ' . $expectedClass . ', nothing was thrown');
    }

    /**
     * Print the summary and return the exit code.
     */
    public static function summary(string $suite): int
    {
        $failed = count(self::$failures);

        echo "\n{$suite}: " . self::$passed . ' passed, ' . $failed . " failed\n";

        if ($failed > 0) {
            echo "\nFailures:\n";

            foreach (self::$failures as $failure) {
                echo "  - {$failure}\n";
            }

            return 1;
        }

        return 0;
    }

    private static function export(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_scalar($value)) {
            return var_export($value, true);
        }

        return gettype($value);
    }
}

/**
 * A fresh in-memory SQLite database with the settings the receiver needs.
 */
function testDatabase(): PDO
{
    $db = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $db->exec('PRAGMA busy_timeout = 5000');
    $db->exec('PRAGMA foreign_keys = ON');

    return $db;
}
