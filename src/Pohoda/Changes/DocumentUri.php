<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * ease:// DocumentUri helper (compatible with Ease\DocumentUri when available).
 */
final class DocumentUri
{
    /**
     * @param array<string, mixed> $args
     */
    public static function build(
        string $sourceSystem,
        string $documentType,
        string $recordId,
        array $args = [],
    ): string {
        if (class_exists(\Ease\DocumentUri::class)) {
            return \Ease\DocumentUri::build($sourceSystem, $documentType, $recordId, $args);
        }

        $base = 'ease://'.$sourceSystem.'/'.ltrim($documentType, '/');

        if ($args) {
            $base .= '?'.http_build_query($args, '', '&', \PHP_QUERY_RFC3986);
        }

        return $base.'#'.rawurlencode($recordId);
    }

    /**
     * @return array{
     *   source_system: string,
     *   document_type: string,
     *   record_id: string,
     *   args: array<string, string>
     * }
     */
    public static function validate(string $uri): array
    {
        if (class_exists(\Ease\DocumentUri::class)) {
            return \Ease\DocumentUri::validate($uri);
        }

        $parsed = parse_url($uri);

        if ($parsed === false || ($parsed['scheme'] ?? null) !== 'ease') {
            throw new \InvalidArgumentException('Malformed DocumentUri');
        }

        $sourceSystem = $parsed['host'] ?? '';
        $documentType = ltrim($parsed['path'] ?? '', '/');
        $recordId = urldecode($parsed['fragment'] ?? '');

        if ($sourceSystem === '' || $documentType === '' || $recordId === '') {
            throw new \InvalidArgumentException('Incomplete DocumentUri');
        }

        $args = [];

        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $args);
        }

        return [
            'source_system' => $sourceSystem,
            'document_type' => $documentType,
            'record_id' => $recordId,
            'args' => $args,
        ];
    }
}
