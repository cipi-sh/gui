<?php

namespace CipiGui\Infrastructure\Aws;

use CipiGui\Infrastructure\ConnectorException;
use DOMDocument;
use DOMElement;

/**
 * The XML of the AWS query and REST APIs as arrays. Elements whose children
 * are all <item> or <member> become lists, a name repeated among siblings
 * becomes a list, a leaf becomes its text. Namespaces are ignored.
 */
final class Xml
{
    /**
     * The content of the root element; a root that only holds text is [text].
     *
     * @return array<mixed>
     */
    public static function parse(string $xml): array
    {
        if (trim($xml) === '') {
            return [];
        }

        $document = new DOMDocument;

        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA) || ! $document->documentElement) {
            throw new ConnectorException(__('AWS answered with something that is not XML.'));
        }

        $content = self::node($document->documentElement);

        return is_array($content) ? $content : [$content];
    }

    /**
     * A value that may be a list, a single item or empty, as a list.
     *
     * @return list<mixed>
     */
    public static function list(mixed $value): array
    {
        return match (true) {
            $value === null, $value === '' => [],
            is_array($value) && array_is_list($value) => $value,
            default => [$value],
        };
    }

    private static function node(DOMElement $element): mixed
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        if ($children === []) {
            return $element->textContent;
        }

        $names = array_map(fn (DOMElement $child) => (string) $child->localName, $children);

        if (array_diff($names, ['item', 'member']) === []) {
            return array_map(self::node(...), $children);
        }

        $counts = array_count_values($names);
        $content = [];

        foreach ($children as $child) {
            $name = (string) $child->localName;

            if ($counts[$name] > 1) {
                $content[$name][] = self::node($child);
            } else {
                $content[$name] = self::node($child);
            }
        }

        return $content;
    }

    /** Text safe inside an element. */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
