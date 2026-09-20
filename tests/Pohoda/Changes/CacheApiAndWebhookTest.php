<?php

declare(strict_types=1);

namespace Pohoda\Changes\Tests;

use PHPUnit\Framework\TestCase;
use Pohoda\Changes\AccountingUnit;
use Pohoda\Changes\CacheApi;
use Pohoda\Changes\ChangeRecorder;
use Pohoda\Changes\RecordCache;
use Pohoda\Changes\Webhook\Endpoint;

final class CacheApiAndWebhookTest extends TestCase
{
    private array $unit;

    protected function setUp(): void
    {
        pohoda_changes_test_reset();
        $units = new AccountingUnit();
        $units->register([
            'ico' => '87654321',
            'year' => 2026,
            'url' => 'http://pohoda:50000',
        ]);
        $this->unit = $units->findByIcoYear('87654321', 2026);
        self::assertNotNull($this->unit);

        $cache = new RecordCache();
        $cache->store(
            10,
            7,
            'bank',
            (string) $this->unit['serverurl'],
            ['id' => 7, 'number' => 'BV1'],
            '<?xml version="1.0"?><bank><id>7</id></bank>',
        );
        $cache->store(
            20,
            7,
            'bank',
            (string) $this->unit['serverurl'],
            ['id' => 7, 'number' => 'BV2'],
            '<?xml version="1.0"?><bank><id>7</id><n>2</n></bank>',
        );
    }

    public function testCacheApiLatestAndPrevious(): void
    {
        $api = new CacheApi();
        [$status, $headers, $body] = $api->handle(
            'GET',
            '/cache/87654321/2026/bank/7',
            ['format' => 'json'],
        );
        self::assertSame(200, $status);
        self::assertStringContainsString('application/json', $headers['Content-Type']);
        $data = json_decode($body, true);
        self::assertSame('BV2', $data['number']);

        [$st2, , $body2] = $api->handle('GET', '/cache/87654321/2026/bank/7/previous', ['format' => 'json']);
        self::assertSame(200, $st2);
        self::assertSame('BV1', json_decode($body2, true)['number']);
    }

    public function testCacheApiXmlUsesStoredXml(): void
    {
        $api = new CacheApi();
        [$status, $headers, $body] = $api->handle(
            'GET',
            '/cache/87654321/2026/bank/7.xml',
        );
        self::assertSame(200, $status);
        self::assertStringContainsString('application/xml', $headers['Content-Type']);
        self::assertStringContainsString('<bank>', $body);
    }

    public function testWebhookEndpointFilter(): void
    {
        $ep = new Endpoint();
        $id = $ep->register([
            'url' => 'https://example.com/hook',
            'evidences' => 'invoice',
            'icos' => '87654321',
        ]);
        self::assertGreaterThan(0, $id);

        $row = $ep->getFluentPDO()->from('webhook_endpoints')->where('id', $id)->fetch();
        self::assertTrue($ep->matches($row, ['evidence' => 'invoice', 'operation' => 'create'], $this->unit));
        self::assertFalse($ep->matches($row, ['evidence' => 'bank', 'operation' => 'create'], $this->unit));
    }

    public function testChangeRecorderWritesCacheUrl(): void
    {
        $recorder = new ChangeRecorder();
        $recorded = $recorder->record($this->unit, 'bank', 7, 'update', 99, ['id' => 7, 'number' => 'BV2']);
        self::assertStringContainsString('ease://pohoda/bank', $recorded['document_uri']);
        self::assertStringContainsString('/cache/87654321/2026/bank/7', $recorded['cache_url']);
        self::assertSame('87654321', $recorded['context_decoded']['ico']);
    }
}
