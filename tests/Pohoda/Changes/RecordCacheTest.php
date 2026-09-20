<?php

declare(strict_types=1);

namespace Pohoda\Changes\Tests;

use PHPUnit\Framework\TestCase;
use Pohoda\Changes\AccountingUnit;
use Pohoda\Changes\DocumentUriBuilder;
use Pohoda\Changes\FormatNegotiator;
use Pohoda\Changes\RecordCache;

final class RecordCacheTest extends TestCase
{
    private AccountingUnit $units;

    private RecordCache $cache;

    private array $unit;

    protected function setUp(): void
    {
        pohoda_changes_test_reset();
        $this->units = new AccountingUnit();
        $this->cache = new RecordCache();
        $this->units->register([
            'ico' => '12345678',
            'year' => 2026,
            'url' => 'http://pohoda:40000',
            'name' => 'Test',
        ]);
        $this->unit = $this->units->findByIcoYear('12345678', 2026);
        self::assertNotNull($this->unit);
    }

    public function testStoresVersionsAndPrevious(): void
    {
        $serverUrl = (string) $this->unit['serverurl'];
        $this->cache->store(100, 42, 'invoice', $serverUrl, ['id' => 42, 'number' => 'A'], '<xml>a</xml>');
        $this->cache->store(200, 42, 'invoice', $serverUrl, ['id' => 42, 'number' => 'B'], '<xml>b</xml>');

        $latest = $this->cache->loadLatest(42, 'invoice', $serverUrl);
        $prev = $this->cache->loadPrevious(42, 'invoice', $serverUrl);

        self::assertSame('B', $latest['number'] ?? null);
        self::assertSame('A', $prev['number'] ?? null);
        self::assertCount(2, $this->cache->loadAll(42, 'invoice', $serverUrl));
    }

    public function testIsolationByIcoYear(): void
    {
        $this->units->register([
            'ico' => '12345678',
            'year' => 2025,
            'url' => 'http://pohoda:40000',
        ]);
        $u2025 = $this->units->findByIcoYear('12345678', 2025);
        $u2026 = $this->unit;

        $this->cache->store(1, 42, 'invoice', (string) $u2025['serverurl'], ['year' => 2025]);
        $this->cache->store(1, 42, 'invoice', (string) $u2026['serverurl'], ['year' => 2026]);

        self::assertSame(2025, $this->cache->loadLatest(42, 'invoice', (string) $u2025['serverurl'])['year']);
        self::assertSame(2026, $this->cache->loadLatest(42, 'invoice', (string) $u2026['serverurl'])['year']);
    }

    public function testDocumentUriContainsIcoAndYear(): void
    {
        $built = DocumentUriBuilder::build($this->unit, 'invoice', 99, ['number' => 'FA1']);
        self::assertStringContainsString('ico=12345678', $built['document_uri']);
        self::assertStringContainsString('year=2026', $built['document_uri']);
        self::assertStringEndsWith('#99', $built['document_uri']);
        self::assertSame('12345678/2026/invoice/99', $built['cache_path']);
    }

    public function testFormatNegotiation(): void
    {
        self::assertSame('yaml', FormatNegotiator::resolve(['format' => 'yaml']));
        self::assertSame('xml', FormatNegotiator::resolve([], ['HTTP_ACCEPT' => 'application/xml']));
        self::assertSame('json', FormatNegotiator::resolve([], [], '/cache/x/2026/invoice/1.json'));

        $data = ['id' => 1, 'name' => 'Test', '_xml' => '<?xml version="1.0"?><invoice><id>1</id></invoice>'];
        $xml = FormatNegotiator::render($data, 'xml');
        self::assertStringContainsString('<invoice>', $xml['body']);
        self::assertStringContainsString('application/xml', $xml['content_type']);

        $json = FormatNegotiator::render($data, 'json');
        self::assertStringContainsString('"id": 1', $json['body']);
        self::assertStringNotContainsString('_xml', $json['body']);

        $yaml = FormatNegotiator::render($data, 'yaml');
        self::assertStringContainsString('application/yaml', $yaml['content_type']);
        self::assertStringContainsString('name: Test', $yaml['body']);
    }

    public function testDefaultDatabaseName(): void
    {
        self::assertSame('StwPh_12345678_2026', AccountingUnit::defaultDatabase('12345678', 2026));
    }
}
