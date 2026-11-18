<?php

declare(strict_types=1);

use Estate\Http\Api;
use Estate\Policy;
use Estate\Store\Store;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$store = Store::open(
    getenv('ESTATE_DSN') ?: 'sqlite:' . $root . '/var/estate.sqlite',
    getenv('ESTATE_DB_USER') ?: null,
    getenv('ESTATE_DB_PASSWORD') ?: null
);
$store->migrate(); // CREATE TABLE IF NOT EXISTS: cheap, and a fresh database serves at once
$asOf = getenv('ESTATE_AS_OF');
$api = new Api(
    $store,
    Policy::load($root . '/config/estate.json'),
    (string) getenv('ESTATE_TOKEN'),
    static fn (): ?string => is_readable($root . '/reports/dev.json') ? (string) file_get_contents($root . '/reports/dev.json') : null,
    $asOf ? static fn () => new DateTimeImmutable($asOf) : null,
);
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (str_starts_with($k, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}
[$status, $body] = $api->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/', (string) file_get_contents('php://input'), $headers);
http_response_code($status);
header('Content-Type: application/json');
echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
