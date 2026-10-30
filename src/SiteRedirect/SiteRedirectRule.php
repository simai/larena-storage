<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

/** One site redirect rule as a record of a storage bound to site_redirect@v1 holds it. */
final readonly class SiteRedirectRule
{
    public const ORIGIN_MANUAL = 'manual';

    public const ORIGIN_AUTO = 'auto';

    public function __construct(
        public string $schemaId,
        public string $recordId,
        public string $from,
        public string $to,
        public int $code,
        public string $match,
        public string $origin,
    ) {
    }

    /**
     * A rule from a record's stored values, or null when they are not one (a record written before its fields, or by
     * hand around the workbench): such a record redirects nothing.
     *
     * @param array<string, mixed> $values
     */
    public static function fromValues(string $schemaId, string $recordId, array $values): ?self
    {
        $from = $values['from_address'] ?? null;
        $to = $values['to_address'] ?? null;
        $code = $values['status_code'] ?? null;
        $match = $values['match'] ?? null;
        if (!is_string($from) || !is_string($to) || !in_array($code, ['301', '302'], true)
            || !in_array($match, SiteRedirectPath::MATCHES, true)) {
            return null;
        }
        $origin = ($values['origin'] ?? null) === self::ORIGIN_AUTO ? self::ORIGIN_AUTO : self::ORIGIN_MANUAL;

        return new self($schemaId, $recordId, $from, $to, (int) $code, $match, $origin);
    }

    public function withTarget(string $to): self
    {
        return new self($this->schemaId, $this->recordId, $this->from, $to, $this->code, $this->match, $this->origin);
    }

    public function matches(string $path): bool
    {
        return SiteRedirectPath::matches($this->from, $this->match, $path);
    }

    /** Where the rule sends $path, which it matches. */
    public function target(string $path): string
    {
        return SiteRedirectPath::apply($this->from, $this->to, $this->match, $path);
    }
}
