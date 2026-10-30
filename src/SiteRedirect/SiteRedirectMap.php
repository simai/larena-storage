<?php

declare(strict_types=1);

namespace Larena\Storage\SiteRedirect;

use Closure;

/**
 * A site's redirect rules, ready to answer a path: the exact rules by their path (a hash lookup), the prefix rules by
 * their prefix, tried from the longest (the path itself, then each parent up to `/`), so the answer costs the path's
 * depth whatever the number of rules. An exact rule wins over a prefix rule of the same path; the site-root rule, the one
 * of its kind, answers last and only what its caller lets it.
 *
 * It is plain data (toArray / fromArray) so that the configured cache can keep it.
 */
final readonly class SiteRedirectMap
{
    /**
     * @param array<string, array{0: string, 1: int, 2: string}> $exact path => [target, code, record id]
     * @param array<string, array{0: string, 1: int, 2: string}> $prefix prefix => [target, code, record id]
     * @param array{0: string, 1: int, 2: string}|null $root the site-root rule
     */
    private function __construct(private array $exact, private array $prefix, private ?array $root = null)
    {
    }

    /** @param iterable<SiteRedirectRule> $rules */
    public static function of(iterable $rules): self
    {
        $exact = [];
        $prefix = [];
        $root = null;
        foreach ($rules as $rule) {
            if ($rule->match === SiteRedirectPath::MATCH_SITE_ROOT) {
                $root ??= [$rule->to, $rule->code, $rule->recordId];
            } elseif ($rule->match === SiteRedirectPath::MATCH_PREFIX) {
                $prefix[$rule->from] ??= [$rule->to, $rule->code, $rule->recordId];
            } else {
                $exact[$rule->from] ??= [$rule->to, $rule->code, $rule->recordId];
            }
        }

        return new self($exact, $prefix, $root);
    }

    /** @param array<mixed> $data */
    public static function fromArray(array $data): ?self
    {
        $exact = self::entries($data['exact'] ?? null);
        $prefix = self::entries($data['prefix'] ?? null);

        $root = $data['root'] ?? null;
        if ($exact === null || $prefix === null || ($root !== null && (!is_array($root) || !is_string($root[0] ?? null) || !is_int($root[1] ?? null) || !is_string($root[2] ?? null)))) {
            return null;
        }

        return new self($exact, $prefix, $root === null ? null : [$root[0], $root[1], $root[2]]);
    }

    /** @return array{exact: array<string, array{0: string, 1: int, 2: string}>, prefix: array<string, array{0: string, 1: int, 2: string}>, root: array{0: string, 1: int, 2: string}|null} */
    public function toArray(): array
    {
        return ['exact' => $this->exact, 'prefix' => $this->prefix, 'root' => $this->root];
    }

    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->prefix === [] && $this->root === null;
    }

    public function count(): int
    {
        return count($this->exact) + count($this->prefix) + ($this->root === null ? 0 : 1);
    }

    /**
     * Where $path (normalized) is sent: the target, the status code and the rule's record id; null when no rule
     * answers it.
     *
     * @param (Closure(string): bool)|null $kept whether a first segment stays out of the site-root rule
     * @return array{target: string, code: int, record_id: string}|null
     */
    public function resolve(string $path, ?Closure $kept = null): ?array
    {
        if (isset($this->exact[$path])) {
            [$target, $code, $recordId] = $this->exact[$path];

            return ['target' => $target, 'code' => $code, 'record_id' => $recordId];
        }
        $candidate = $this->prefix === [] ? null : $path;
        while ($candidate !== null) {
            if (isset($this->prefix[$candidate])) {
                [$target, $code, $recordId] = $this->prefix[$candidate];

                return ['target' => SiteRedirectPath::apply($candidate, $target, SiteRedirectPath::MATCH_PREFIX, $path), 'code' => $code, 'record_id' => $recordId];
            }
            if ($candidate === '/') {
                break;
            }
            $cut = strrpos($candidate, '/');
            $candidate = $cut === 0 || $cut === false ? '/' : substr($candidate, 0, $cut);
        }
        if ($this->root === null) {
            return null;
        }
        [$target, $code, $recordId] = $this->root;
        $segment = explode('/', ltrim($path, '/'), 2)[0];
        if ($path === $target || str_starts_with($path, $target . '/') || ($segment !== '' && $kept !== null && $kept($segment))) {
            return null;
        }

        return ['target' => $path === '/' ? $target : $target . $path, 'code' => $code, 'record_id' => $recordId];
    }

    /** @return array<string, array{0: string, 1: int, 2: string}>|null */
    private static function entries(mixed $entries): ?array
    {
        if (!is_array($entries)) {
            return null;
        }
        $valid = [];
        foreach ($entries as $path => $entry) {
            if (!is_string($path) || !is_array($entry) || !is_string($entry[0] ?? null) || !is_int($entry[1] ?? null) || !is_string($entry[2] ?? null)) {
                return null;
            }
            $valid[$path] = [$entry[0], $entry[1], $entry[2]];
        }

        return $valid;
    }
}
