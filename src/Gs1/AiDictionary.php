<?php

declare(strict_types=1);

namespace PhpBarcode\Gs1;

/**
 * The table of GS1 Application Identifiers.
 *
 * Generated from the GS1 Barcode Syntax Dictionary
 * (https://github.com/gs1/gs1-syntax-dictionary, Apache-2.0), which defines
 * every AI's format, check-digit and date rules, mandatory and invalid
 * pairings, and GS1 Digital Link key qualifiers.
 */
final class AiDictionary
{
    private static ?self $default = null;

    /** @var array<string, ApplicationIdentifier> */
    private array $identifiers = [];

    /**
     * @param array<string, array{title: string, predefined: bool, dl: bool,
     *     components: list<array{type: string, min: int, max: int, optional: bool, linters: list<string>}>,
     *     req: list<list<list<string>>>, ex: list<string>, dlpkey: list<list<string>>|null}> $definitions
     */
    public function __construct(array $definitions)
    {
        foreach ($definitions as $code => $definition) {
            $code = (string) $code;
            $this->identifiers[$code] = new ApplicationIdentifier(
                code: $code,
                title: $definition['title'],
                components: array_map(
                    static fn(array $c): Component => new Component(
                        CharacterSet::from($c['type']),
                        $c['min'],
                        $c['max'],
                        $c['optional'],
                        $c['linters'],
                    ),
                    $definition['components'],
                ),
                predefinedLength: $definition['predefined'],
                digitalLinkAttribute: $definition['dl'],
                requires: $definition['req'],
                excludes: $definition['ex'],
                digitalLinkQualifiers: $definition['dlpkey'],
            );
        }
    }

    /**
     * The full, current GS1 AI table.
     */
    public static function default(): self
    {
        if (self::$default === null) {
            /** @var array<string, array{title: string, predefined: bool, dl: bool, components: list<array{type: string, min: int, max: int, optional: bool, linters: list<string>}>, req: list<list<list<string>>>, ex: list<string>, dlpkey: list<list<string>>|null}> $definitions */
            $definitions = require __DIR__ . '/Resources/application-identifiers.php';
            self::$default = new self($definitions);
        }

        return self::$default;
    }

    public function has(string $code): bool
    {
        return isset($this->identifiers[$code]);
    }

    /**
     * @throws Gs1Exception for unknown AIs
     */
    public function get(string $code): ApplicationIdentifier
    {
        return $this->identifiers[$code] ?? throw new Gs1Exception(sprintf('Unknown GS1 Application Identifier (%s).', $code), $code);
    }

    /**
     * Finds the AI at the start of $data (from $offset). GS1 AIs are prefix-free,
     * so at most one 2-, 3- or 4-digit code can match.
     */
    public function matchPrefix(string $data, int $offset = 0): ?ApplicationIdentifier
    {
        for ($length = 2; $length <= 4; $length++) {
            $code = substr($data, $offset, $length);
            if (strlen($code) === $length && isset($this->identifiers[$code])) {
                return $this->identifiers[$code];
            }
        }

        return null;
    }

    /**
     * @return array<string, ApplicationIdentifier>
     */
    public function all(): array
    {
        return $this->identifiers;
    }
}
