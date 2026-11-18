<?php

// Imports a snapshot into a real database and reads it back through the API: run by CI against MySQL and PostgreSQL.
// Usage: php estate/tests/db_smoke.php <dsn> <snapshot.json>   (ESTATE_DB_USER / ESTATE_DB_PASSWORD for credentials)

declare(strict_types=1);

use Estate\Http\Api;
use Estate\Policy;
use Estate\Store\Store;

require dirname(__DIR__) . '/vendor/autoload.php';

[$dsn, $file] = [$argv[1] ?? '', $argv[2] ?? ''];
$store = Store::open($dsn, getenv('ESTATE_DB_USER') ?: null, getenv('ESTATE_DB_PASSWORD') ?: null);
$store->migrate();
$store->reset();
$snapshot = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$store->import($snapshot);
$api = new Api($store, Policy::load(dirname(__DIR__, 2) . '/config/estate.json'), 't', null,
    fn () => new DateTimeImmutable($snapshot['as_of']));
[$code, $body] = $api->handle('GET', '/api/estate');
$apps = array_sum(array_map(fn ($c) => count($c['apps']), $body['clients']));
if ($code !== 200 || $apps !== count($snapshot['apps'])) {
    fwrite(STDERR, "estate read back {$apps} apps, expected " . count($snapshot['apps']) . "\n");
    exit(1);
}
[$code, $one] = $api->handle('GET', '/api/apps/' . $snapshot['apps'][0]['id']);
if ($code !== 200 || count($one['dependencies']) !== count($snapshot['apps'][0]['dependencies'])) {
    fwrite(STDERR, "app detail did not round-trip\n");
    exit(1);
}
fwrite(STDOUT, "{$dsn}: {$apps} apps, " . count($store->findings()) . " findings, " . count($store->jobs()) . " jobs read back\n");
