<?php

declare(strict_types=1);

namespace Estate\Tests;

use Estate\Cli\Stdio;
use Estate\Http\Api;
use Estate\Policy;
use Estate\Store\Store;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    private function api(): Api
    {
        $store = Store::open('sqlite::memory:');
        $store->migrate();
        $store->import([
            'clients' => [['id' => 'acme', 'name' => 'Acme Coffee']],
            'apps' => [['id' => 'acme-shop', 'client' => 'acme', 'name' => 'Acme shop', 'stack' => 'laravel', 'provider' => 'aws',
                'url' => 'https://shop.acme.example', 'freeze' => [['from' => '2026-11-20T00:00:00Z', 'to' => '2026-12-27T00:00:00Z']],
                'dependencies' => [['ecosystem' => 'packagist', 'name' => 'laravel/framework', 'version' => '11.9.0']]]],
            'advisories' => [['id' => 'GHSA-lv-1', 'ecosystem' => 'packagist', 'package' => 'laravel/framework', 'severity' => 'high',
                'published' => '2026-03-01', 'ranges' => [['events' => [['introduced' => '11.0.0'], ['fixed' => '11.9.2']]]]]],
            'findings' => [['resource' => 'vm-1', 'client' => 'acme', 'provider' => 'aws', 'action' => 'delete',
                'monthly_saving_eur' => 120.5, 'reason' => 'idle']],
        ]);
        return new Api(
            $store,
            Policy::load(dirname(__DIR__, 2) . '/config/estate.json'),
            'secret',
            null,
            fn () => new \DateTimeImmutable('2026-03-10T09:00:00Z')
        );
    }

    public function testEstateAndApp(): void
    {
        $api = $this->api();
        [$code, $body] = $api->handle('GET', '/api/estate');
        $this->assertSame(200, $code);
        $app = $body['clients'][0]['apps'][0];
        $this->assertSame(1, $app['exposures']['high']);
        $this->assertFalse($app['frozen']);
        $this->assertSame(120.5, $body['clients'][0]['monthly_savings_eur']);
        [$code, $body] = $api->handle('GET', '/api/apps/acme-shop');
        $this->assertSame(['11.9.2'], $body['exposures'][0]['fixed_in']);
        [$code] = $api->handle('GET', '/api/apps/nope');
        $this->assertSame(404, $code);
        [$code] = $api->handle('GET', '/api/report');
        $this->assertSame(404, $code);
    }

    public function testWritesNeedTheToken(): void
    {
        $api = $this->api();
        $osv = json_encode(['id' => 'GHSA-new', 'ecosystem' => 'npm', 'package' => 'axios', 'severity' => 'critical',
            'published' => '2026-03-09', 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '1.6.8']]]]]);
        [$code] = $api->handle('POST', '/api/advisories', (string) $osv);
        $this->assertSame(401, $code);
        [$code] = $api->handle('POST', '/api/advisories', (string) $osv, ['authorization' => 'Bearer wrong']);
        $this->assertSame(401, $code);
        [$code, $body] = $api->handle('POST', '/api/advisories', (string) $osv, ['authorization' => 'Bearer secret']);
        $this->assertSame(202, $code);
        $this->assertSame(1, $body['stored']);
        [$code] = $api->handle('POST', '/api/advisories', '{not json', ['authorization' => 'Bearer secret']);
        $this->assertSame(400, $code);
        [$code] = $api->handle('POST', '/api/advisories', '{"id":"x"}', ['authorization' => 'Bearer secret']);
        $this->assertSame(422, $code);
        [$code, $body] = $api->handle(
            'POST',
            '/api/canary/verdict',
            '{"baseline":{"requests":10000,"errors":40},"canary":{"requests":1000,"errors":30}}',
            ['authorization' => 'Bearer secret']
        );
        $this->assertSame('rollback', $body['verdict']);
        [$code, $body] = $api->handle('POST', '/api/apps/acme-shop/plan', '', ['authorization' => 'Bearer secret']);
        $this->assertSame(200, $code);
        $this->assertSame('held', $body['jobs'][0]['lane'], 'no released fix is known to the store: held with the reason');
    }

    public function testStdio(): void
    {
        $s = new Stdio(Policy::load(dirname(__DIR__, 2) . '/config/estate.json'));
        $this->assertSame(['pong' => true], $s->answer(['op' => 'ping']));
        $this->assertSame(['design' => 'auto_merge'], $s->answer(['op' => 'design', 'name' => 'auto_merge']));
        $out = $s->answer(['op' => 'pages', 'rounds' => [['t' => '2026-03-01T00:00:00Z', 'failed' => ['eu']]]]);
        $this->assertCount(1, $out['pages'], 'auto_merge pages on any failure');
        $this->expectException(\InvalidArgumentException::class);
        $s->answer(['op' => 'drop tables']);
    }
}
