<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Load a single Pohoda document (+ optional XML) via mServer.
 */
class MserverDocumentLoader extends \Ease\Sand
{
    /**
     * @param array<string, mixed> $unit
     *
     * @return array{document: ?array<string, mixed>, xml: ?string}
     */
    public function load(array $unit, string $agenda, int $recordId): array
    {
        $class = AgendaWatcher::AGENDA_CLASSES[$agenda]
            ?? AgendaWatcher::AGENDA_CLASSES[strtolower($agenda)]
            ?? null;

        if ($class === null) {
            throw new \InvalidArgumentException('Unsupported agenda: '.$agenda);
        }

        $opts = AccountingUnit::mServerOptions($unit);
        /** @var \mServer\Client $client */
        $client = new $class(null, [
            'url' => $opts['url'],
            'ico' => $opts['ico'],
            'user' => $opts['user'],
            'password' => $opts['password'],
            'debug' => (bool) \Ease\Shared::cfg('APP_DEBUG', false),
        ]);

        try {
            $client->reset();
            $documents = $client->getColumnsFromPohoda(['*'], ['id' => (string) $recordId]) ?? [];
        } catch (\Throwable $e) {
            $this->addStatusMessage(sprintf('Load failed %s#%d: %s', $agenda, $recordId, $e->getMessage()), 'warning');

            return ['document' => null, 'xml' => null];
        }

        $document = null;

        if (isset($documents[(string) $recordId]) && is_array($documents[(string) $recordId])) {
            $document = $documents[(string) $recordId];
        } elseif (isset($documents[$recordId]) && is_array($documents[$recordId])) {
            $document = $documents[$recordId];
        } elseif (isset($documents[0]) && is_array($documents[0])) {
            $document = $documents[0];
        } elseif (isset($documents['id'])) {
            $document = $documents;
        } else {
            $first = reset($documents);
            $document = is_array($first) ? $first : null;
        }

        if (!is_array($document) || $document === []) {
            $this->addStatusMessage(sprintf('Empty document %s#%d for %s', $agenda, $recordId, $unit['ico']), 'warning');

            return ['document' => null, 'xml' => null];
        }

        $xml = null;

        try {
            $client->reset();
            $xml = $client->getPohodaXML(['id' => (string) $recordId]);
        } catch (\Throwable $e) {
            $this->addStatusMessage(sprintf('XML snapshot failed for %s#%d: %s', $agenda, $recordId, $e->getMessage()), 'warning');
        }

        return ['document' => $document, 'xml' => is_string($xml) ? $xml : null];
    }
}
