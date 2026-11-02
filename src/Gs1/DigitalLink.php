<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * Converts between GS1 element strings and GS1 Digital Link URIs.
 *
 *     $link = new DigitalLink();                       // https://id.gs1.org
 *     $link->toUri(ElementString::parse('(01)09506000134352(10)ABC(17)251231'));
 *     // https://id.gs1.org/01/09506000134352/10/ABC?17=251231
 *
 *     $link->toElementString('https://example.com/01/09506000134352/10/ABC?17=251231');
 *
 * The primary key and its qualifiers form the path, in the order the GS1 Digital
 * Link standard prescribes; other AIs that are permitted as data attributes go in
 * the query string. Put the URI in a QR Code or Data Matrix to make a product
 * barcode that phones can open and point-of-sale scanners can still decode.
 */
final class DigitalLink
{
    private readonly string $domain;

    private readonly AiDictionary $dictionary;

    public function __construct(
        string $domain = 'https://id.gs1.org',
        ?AiDictionary $dictionary = null,
    ) {
        $this->dictionary = $dictionary ?? AiDictionary::default();

        if (preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#i', $domain) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an http(s) URI prefix.', $domain));
        }
        $this->domain = rtrim($domain, '/');
    }

    /**
     * @throws Gs1Exception when the data has no primary key or contains AIs that Digital Link cannot carry
     */
    public function toUri(ElementString|string $data): string
    {
        $elements = ($data instanceof ElementString ? $data : ElementString::parse($data, true, $this->dictionary))->elements;

        $key = null;
        foreach ($elements as $element) {
            if ($element->ai->isDigitalLinkPrimaryKey()) {
                $key = $element;
                break;
            }
        }
        if ($key === null) {
            throw new Gs1Exception('A GS1 Digital Link needs a primary key such as (01) GTIN, (00) SSCC or (414) GLN.');
        }

        $remaining = array_values(array_filter($elements, static fn(Element $e): bool => $e !== $key));
        $qualifiers = $this->qualifiersFor($key, $remaining);

        $path = '/' . $key->ai->code . '/' . rawurlencode($key->value);
        foreach ($qualifiers as $qualifier) {
            $path .= '/' . $qualifier->ai->code . '/' . rawurlencode($qualifier->value);
        }

        $query = [];
        foreach ($remaining as $element) {
            if (in_array($element, $qualifiers, true)) {
                continue;
            }
            if (!$element->ai->digitalLinkAttribute) {
                throw new Gs1Exception(sprintf(
                    'AI (%s) %s cannot be expressed in a GS1 Digital Link URI.',
                    $element->ai->code,
                    $element->ai->title,
                ), $element->ai->code);
            }
            $query[] = $element->ai->code . '=' . rawurlencode($element->value);
        }

        return $this->domain . $path . ($query === [] ? '' : '?' . implode('&', $query));
    }

    /**
     * Extracts the element string from a GS1 Digital Link URI on any domain.
     * Query parameters that are not AIs (e.g. tracking parameters) are ignored.
     *
     * @throws Gs1Exception
     */
    public function toElementString(string $uri, bool $checkAssociations = true): ElementString
    {
        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['host'])) {
            throw new Gs1Exception(sprintf('"%s" is not a valid URI.', $uri));
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? ''), static fn(string $s): bool => $s !== ''));
        $dictionary = $this->dictionary;

        // The path may carry an arbitrary prefix; the key is the first AI segment that is a primary key.
        $start = null;
        for ($i = 0, $count = count($segments); $i < $count - 1; $i++) {
            if ($dictionary->has($segments[$i]) && $dictionary->get($segments[$i])->isDigitalLinkPrimaryKey()) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            throw new Gs1Exception('The URI path contains no GS1 Digital Link primary key.');
        }
        if ((count($segments) - $start) % 2 !== 0) {
            throw new Gs1Exception('The URI path must consist of AI/value pairs after the primary key.');
        }

        $key = $dictionary->get($segments[$start]);
        $values = [];
        $pathAis = [];
        for ($i = $start; $i < count($segments); $i += 2) {
            $values[] = [$segments[$i], rawurldecode($segments[$i + 1])];
            if ($i > $start) {
                $pathAis[] = $segments[$i];
            }
        }
        $this->assertQualifierOrder($key, $pathAis);

        parse_str($parts['query'] ?? '', $query);
        foreach ($query as $name => $value) {
            $name = (string) $name;
            if (is_string($value) && ctype_digit($name) && $dictionary->has($name)) {
                $values[] = [$name, $value];
            }
        }

        $array = [];
        foreach ($values as [$ai, $value]) {
            if (isset($array[$ai])) {
                throw new Gs1Exception(sprintf('AI (%s) appears more than once in the URI.', $ai), $ai);
            }
            $array[$ai] = $value;
        }

        return ElementString::fromArray($array, $checkAssociations, $dictionary);
    }

    /**
     * Picks the qualifiers of $key from $elements in the standard's order.
     *
     * @param list<Element> $elements
     *
     * @return list<Element>
     *
     * @throws Gs1Exception if the qualifiers present belong to incompatible sequences
     */
    private function qualifiersFor(Element $key, array $elements): array
    {
        $sequences = $key->ai->digitalLinkQualifiers ?? [];
        $byCode = [];
        foreach ($elements as $element) {
            $byCode[$element->ai->code] = $element;
        }

        $best = [];
        foreach ($sequences as $sequence) {
            $present = array_values(array_filter($sequence, static fn(string $ai): bool => isset($byCode[$ai])));
            if (count($present) > count($best)) {
                $best = $present;
            }
        }

        foreach ($sequences as $sequence) {
            foreach ($sequence as $ai) {
                if (isset($byCode[$ai]) && !in_array($ai, $best, true) && !$byCode[$ai]->ai->digitalLinkAttribute) {
                    throw new Gs1Exception(sprintf(
                        'AI (%s) cannot be combined with the other qualifiers of AI (%s) in a GS1 Digital Link.',
                        $ai,
                        $key->ai->code,
                    ), $ai);
                }
            }
        }

        return array_map(static fn(string $ai): Element => $byCode[$ai], $best);
    }

    /**
     * @param list<string> $qualifiers
     *
     * @throws Gs1Exception
     */
    private function assertQualifierOrder(ApplicationIdentifier $key, array $qualifiers): void
    {
        if ($qualifiers === []) {
            return;
        }

        foreach ($key->digitalLinkQualifiers ?? [] as $sequence) {
            $position = -1;
            $valid = true;
            foreach ($qualifiers as $ai) {
                $index = array_search($ai, $sequence, true);
                if ($index === false || $index <= $position) {
                    $valid = false;
                    break;
                }
                $position = $index;
            }
            if ($valid) {
                return;
            }
        }

        throw new Gs1Exception(sprintf(
            'Invalid GS1 Digital Link path: qualifiers %s are not allowed after AI (%s) in that order.',
            implode(', ', array_map(static fn(string $ai): string => '(' . $ai . ')', $qualifiers)),
            $key->code,
        ));
    }
}
