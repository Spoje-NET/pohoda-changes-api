<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Build ease://pohoda/... DocumentUri and webhook context.
 */
class DocumentUriBuilder
{
    /**
     * @param array<string, mixed> $unit
     * @param array<string, mixed> $document
     *
     * @return array{document_uri: string, context: array<string, mixed>, cache_path: string}
     */
    public static function build(array $unit, string $evidence, int $recordId, array $document = []): array
    {
        $ico = (string) $unit['ico'];
        $year = (int) $unit['year'];
        $serverUrl = (string) $unit['url'];

        $documentUri = DocumentUri::build(
            'pohoda',
            $evidence,
            (string) $recordId,
            [
                'serverUrl' => $serverUrl,
                'ico' => $ico,
                'year' => $year,
            ],
        );

        $context = [
            'ico' => $ico,
            'year' => $year,
            'serverUrl' => $serverUrl,
            'agenda' => $evidence,
            'database' => $unit['db_database'] ?? AccountingUnit::defaultDatabase($ico, $year),
        ];

        if ($evidence === 'invoice') {
            $context['invoiceType'] = $document['invoiceType']
                ?? $document['invoiceHeader']['invoiceType']
                ?? 'issuedInvoice';
        }

        foreach (['number', 'code', 'symVar', 'id'] as $key) {
            if (isset($document[$key]) && (is_scalar($document[$key]) || $document[$key] === null)) {
                $context[$key] = $document[$key];
            }
        }

        if (isset($document['invoiceHeader']) && is_array($document['invoiceHeader'])) {
            $header = $document['invoiceHeader'];

            if (isset($header['number']['numberRequested'])) {
                $context['code'] = $header['number']['numberRequested'];
            } elseif (isset($header['number']) && is_scalar($header['number'])) {
                $context['code'] = $header['number'];
            }
        }

        $cachePath = sprintf('%s/%d/%s/%d', $ico, $year, $evidence, $recordId);

        return [
            'document_uri' => $documentUri,
            'context' => $context,
            'cache_path' => $cachePath,
        ];
    }
}
