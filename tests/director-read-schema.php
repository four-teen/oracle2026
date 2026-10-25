<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Database.php';
final class DirectorSchemaProbePDO extends PDO
{
    public $queries = [];
    public $failure;
    public function __construct() {}
    #[\ReturnTypeWillChange]
    public function query($sql, $mode = null, ...$args) {
        $this->queries[] = $sql;
        if ($this->failure !== null) {
            $failure = $this->failure;
            $this->failure = null;
            throw $failure;
        }
        return false;
    }
}
$pdo = new DirectorSchemaProbePDO();
$connection = new ReflectionProperty(Database::class, 'connection');
$connection->setAccessible(true);
$connection->setValue(null, $pdo);
$probe = new ReflectionMethod(Database::class, 'ensureReadSchema');
$probe->setAccessible(true);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$fallbacks = 0;
$fallback = static function () use (&$fallbacks): void { $fallbacks++; };
Database::ensureAccountReadSchema();
Database::ensureDirectorAnalyticsSchema();
$assert(count($pdo->queries) === 7 && $fallbacks === 0, 'Existing schemas should need only seven zero-row reads.');
Database::ensureAccountReadSchema();
Database::ensureDirectorAnalyticsSchema();
$assert(count($pdo->queries) === 7, 'Read schema checks should run once per request.');
foreach ($pdo->queries as $query) {
    $assert(strpos($query, 'SELECT ') === 0 && substr($query, -7) === 'LIMIT 0', 'A schema probe changed data or scanned rows.');
}
foreach ([1054, 1146] as $code) {
    $exception = new PDOException('Missing schema fixture');
    $exception->errorInfo = ['42S22', $code, 'Fixture'];
    $pdo->failure = $exception;
    $probe->invoke(null, 'missing_' . $code, ['SELECT fixture LIMIT 0'], $fallback);
    $probe->invoke(null, 'missing_' . $code, ['SELECT fixture LIMIT 0'], $fallback);
}
$assert($fallbacks === 2, 'Missing columns or tables must initialize once.');
$exception = new PDOException('Connection fixture');
$exception->errorInfo = ['HY000', 2006, 'Fixture'];
$pdo->failure = $exception;
try {
    $probe->invoke(null, 'outage', ['SELECT fixture LIMIT 0'], $fallback);
    throw new RuntimeException('A database outage was swallowed.');
} catch (PDOException $expected) {
    $assert($expected === $exception && $fallbacks === 2, 'An outage incorrectly triggered maintenance.');
}
echo "PASS: zero-row schema reads, per-request reuse, legacy schema fallback, and database error propagation\n";
