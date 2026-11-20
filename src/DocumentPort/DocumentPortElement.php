<?php

declare(strict_types=1);

namespace Larena\Storage\DocumentPort;

use InvalidArgumentException;
use Larena\Property\Runtime\CompositionDocument;

/**
 * The storage element a document port is bound to, as the host binds it (the editor never names one): its target
 * (level, ref, title, parents), its languages (the main one first), the capabilities the host announces, the limits
 * of a language value and of all its languages together (`max_total_bytes`, optional: the host's own cap), the reader's rights (from the host on every load) and, with `levels`, each parent level's
 * stored values and the areas it locks.
 */
final readonly class DocumentPortElement
{
    public const CAPABILITIES = ['levels', 'history', 'publish', 'scheduled-publish'];

    /**
     * @param array<string, mixed> $target
     * @param list<string> $languages
     * @param list<string> $capabilities
     * @param array{max_nodes: int, max_bytes: int, max_total_bytes?: int} $limits
     * @param array{save: bool, publish: bool, languages?: list<string>} $rights
     * @param array<string, array{values?: array<string, mixed>, locked?: list<string>}> $parents by parent ref
     */
    public function __construct(
        public array $target,
        public array $languages,
        public array $capabilities,
        public array $limits = ['max_nodes' => CompositionDocument::MAX_NODES, 'max_bytes' => CompositionDocument::MAX_BYTES],
        public array $rights = ['save' => true, 'publish' => false],
        public array $parents = [],
    ) {
        if (!in_array($target['level'] ?? null, ['site', 'section', 'page'], true) || !is_string($target['ref'] ?? null)) {
            throw new InvalidArgumentException('document_port_target_invalid');
        }
        if ($languages === [] || count(array_unique($languages)) !== count($languages)) {
            throw new InvalidArgumentException('document_port_languages_invalid');
        }
        foreach ($languages as $language) {
            if (preg_match(CompositionDocument::LANGUAGE_PATTERN, $language) !== 1) {
                throw new InvalidArgumentException('document_port_languages_invalid');
            }
        }
        if (array_diff($capabilities, self::CAPABILITIES) !== []) {
            throw new InvalidArgumentException('document_port_capability_unknown');
        }
        if ($limits['max_nodes'] < 1 || $limits['max_nodes'] > CompositionDocument::MAX_NODES
            || $limits['max_bytes'] < 1 || $limits['max_bytes'] > CompositionDocument::MAX_BYTES
            || (array_key_exists('max_total_bytes', $limits) && $limits['max_total_bytes'] < 1)
            || array_diff(array_keys($limits), ['max_nodes', 'max_bytes', 'max_total_bytes']) !== []) {
            throw new InvalidArgumentException('document_port_limits_invalid');
        }
    }

    public function has(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function mainLanguage(): string
    {
        return $this->languages[0];
    }

    public function mayWrite(string $language): bool
    {
        return $this->rights['save'] && (!isset($this->rights['languages']) || in_array($language, $this->rights['languages'], true));
    }
}
