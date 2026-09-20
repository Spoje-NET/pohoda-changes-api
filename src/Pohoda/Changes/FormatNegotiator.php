<?php

declare(strict_types=1);

namespace Pohoda\Changes;

use Symfony\Component\Yaml\Yaml;

/**
 * Negotiate and render cache payloads as json / yaml / xml.
 */
class FormatNegotiator
{
    public const FORMATS = ['json', 'yaml', 'xml'];

    /**
     * Resolve format from query, Accept header or path suffix.
     *
     * @param array<string, string> $query
     * @param array<string, string> $server
     */
    public static function resolve(array $query = [], array $server = [], string $path = ''): string
    {
        if (!empty($query['format'])) {
            $f = strtolower((string) $query['format']);

            return \in_array($f, self::FORMATS, true) ? $f : 'json';
        }

        if (preg_match('~\.(json|yaml|yml|xml)$~i', $path, $m)) {
            $ext = strtolower($m[1]);

            return $ext === 'yml' ? 'yaml' : $ext;
        }

        $accept = strtolower((string) ($server['HTTP_ACCEPT'] ?? ''));

        if (str_contains($accept, 'application/yaml') || str_contains($accept, 'text/yaml')) {
            return 'yaml';
        }

        if (str_contains($accept, 'application/xml') || str_contains($accept, 'text/xml')) {
            return 'xml';
        }

        return 'json';
    }

    /**
     * @param array<string, mixed> $data decoded document (+ optional _xml, _inversion)
     *
     * @return array{body: string, content_type: string}
     */
    public static function render(array $data, string $format): array
    {
        return match ($format) {
            'yaml' => [
                'body' => Yaml::dump(self::stripMeta($data), 8, 2),
                'content_type' => 'application/yaml; charset=UTF-8',
            ],
            'xml' => [
                'body' => self::renderXml($data),
                'content_type' => 'application/xml; charset=UTF-8',
            ],
            default => [
                'body' => json_encode(self::stripMeta($data), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n",
                'content_type' => 'application/json; charset=UTF-8',
            ],
        };
    }

    /**
     * @param list<array<string, mixed>> $versions
     *
     * @return array{body: string, content_type: string}
     */
    public static function renderHistory(array $versions, string $format): array
    {
        if ($format === 'xml') {
            $parts = ['<?xml version="1.0" encoding="UTF-8"?>', '<versions>'];

            foreach ($versions as $v) {
                $inv = htmlspecialchars((string) ($v['_inversion'] ?? ''), ENT_XML1);
                $created = htmlspecialchars((string) ($v['_created'] ?? ''), ENT_XML1);
                $inner = self::renderXml($v);
                // strip xml declaration from inner
                $inner = preg_replace('~^<\?xml[^?]*\?>\s*~i', '', $inner) ?? $inner;
                $parts[] = sprintf('<version inversion="%s" created="%s">%s</version>', $inv, $created, $inner);
            }

            $parts[] = '</versions>';

            return [
                'body' => implode("\n", $parts)."\n",
                'content_type' => 'application/xml; charset=UTF-8',
            ];
        }

        $clean = array_map([self::class, 'stripMeta'], $versions);

        if ($format === 'yaml') {
            return [
                'body' => Yaml::dump($clean, 8, 2),
                'content_type' => 'application/yaml; charset=UTF-8',
            ];
        }

        return [
            'body' => json_encode($clean, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT)."\n",
            'content_type' => 'application/json; charset=UTF-8',
        ];
    }

    /**
     * Prefer stored Stormware XML; fall back to pohodaser re-serialize or simple wrapper.
     *
     * @param array<string, mixed> $data
     */
    public static function renderXml(array $data): string
    {
        if (!empty($data['_xml']) && is_string($data['_xml'])) {
            return $data['_xml'];
        }

        $payload = self::stripMeta($data);

        try {
            if (class_exists(\Pohoda\Helper::class)) {
                $serializer = \Pohoda\Helper::getSerializer();
                // Attempt JSON→object→XML when class hint present
                if (!empty($data['_pohoda_class']) && is_string($data['_pohoda_class'])) {
                    $obj = $serializer->deserialize(
                        json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
                        $data['_pohoda_class'],
                        'json',
                    );

                    return $serializer->serialize($obj, 'xml');
                }
            }
        } catch (\Throwable) {
            // fall through to generic wrapper
        }

        return self::arrayToSimpleXml($payload, 'document');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function stripMeta(array $data): array
    {
        unset($data['_xml'], $data['_inversion'], $data['_created'], $data['_pohoda_class']);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function arrayToSimpleXml(array $data, string $root): string
    {
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><'.$root.'/>');
        self::arrayToXml($data, $xml);

        $dom = dom_import_simplexml($xml)->ownerDocument;
        $dom->formatOutput = true;

        return $dom->saveXML() ?: $xml->asXML() ?: '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function arrayToXml(array $data, \SimpleXMLElement $xml): void
    {
        foreach ($data as $key => $value) {
            $key = is_numeric($key) ? 'item'.$key : preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string) $key);

            if (is_array($value)) {
                $child = $xml->addChild((string) $key);
                self::arrayToXml($value, $child);
            } else {
                $xml->addChild((string) $key, htmlspecialchars((string) $value, ENT_XML1));
            }
        }
    }
}
