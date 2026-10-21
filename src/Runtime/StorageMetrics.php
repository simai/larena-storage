<?php

declare(strict_types=1);

namespace Larena\Storage\Runtime;

use Closure;
use InvalidArgumentException;
use Illuminate\Contracts\Container\Container;
use Larena\Access\Exceptions\AccessMutationRejected;
use Larena\Storage\Exceptions\StorageRejected;
use Throwable;

/**
 * Reports Storage's hot-path metrics (storage.list, storage.read, storage.write,
 * storage.read.published_list, storage.view.rebuild, storage.view.rebuild_rows) to larena/monitoring when it
 * is installed and bound, and does nothing otherwise. Storage does not depend on
 * larena/monitoring: the Metrics contract is resolved by name from the
 * container. Tags carry codes only (never ids, titles or values), and recording
 * never throws into a storage operation.
 */
final class StorageMetrics
{
    public const string METRICS_CONTRACT = 'Larena\Monitoring\Contracts\Metrics';

    public const string CATALOG_CLASS = 'Larena\Monitoring\Runtime\MetricCatalog';

    public const string PACKAGE = 'larena/storage';

    public const array MODES = ['sql', 'legacy'];

    public const array COUNTS = ['exact', 'approx', 'skip'];

    /** How a list was sorted: every sorted field indexed, one without an index (or sorted in PHP), or no sort. */
    public const array SORTS = ['indexed', 'unindexed', 'none'];

    public const array OPERATIONS = ['create', 'update', 'archive', 'restore', 'purge', 'revert'];

    public const array OUTCOMES = ['ok', 'rejected', 'error'];

    /** The paths a public list takes: its site витрина slot, the published records, or none (refused before either). */
    public const array LIST_PATHS = ['slot', 'fallback', 'none'];

    /** @var (Closure(): ?object)|null */
    private ?Closure $resolver;

    private ?object $metrics = null;

    private bool $resolved = false;

    /** @param (Closure(): ?object)|null $resolver gives the Metrics implementation, or null when there is none */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver;
    }

    public static function fromContainer(Container $container): self
    {
        return new self(static function () use ($container): ?object {
            if (!interface_exists(self::METRICS_CONTRACT) || !$container->bound(self::METRICS_CONTRACT)) {
                return null;
            }
            return $container->make(self::METRICS_CONTRACT);
        });
    }

    public static function catalogPath(): string
    {
        return dirname(__DIR__, 2) . '/monitoring.yaml';
    }

    /** A rejection (a refused, conflicting or denied operation) is `rejected`; anything else `error`. */
    public static function outcomeOf(Throwable $exception): string
    {
        return $exception instanceof StorageRejected
            || $exception instanceof InvalidArgumentException
            || $exception instanceof AccessMutationRejected
            ? 'rejected'
            : 'error';
    }

    public static function elapsedMs(int $startedNs): float
    {
        return max(0.0, (hrtime(true) - $startedNs) / 1_000_000);
    }

    /**
     * @param ?string $count exact, approx or skip; left out when the list failed before it counted
     * @param ?string $sort indexed, unindexed or none; left out when the list failed before its query was known
     */
    public function list(string $mode, ?string $count, string $outcome, float $ms, ?string $sort = null): void
    {
        $tags = [
            'mode' => in_array($mode, self::MODES, true) ? $mode : 'legacy',
            'outcome' => self::knownOutcome($outcome),
        ];
        if ($count !== null) {
            $tags['count'] = in_array($count, self::COUNTS, true) ? $count : 'exact';
        }
        if ($sort !== null) {
            $tags['sort'] = in_array($sort, self::SORTS, true) ? $sort : 'none';
        }
        $this->timing('storage.list', $ms, $tags);
    }

    /**
     * One public list (storage.read.published_list). path is slot, fallback or none;
     * count is exact, approx or skip, left out when the list failed.
     */
    public function publishedList(string $path, ?string $count, string $outcome, float $ms): void
    {
        $tags = [
            'path' => in_array($path, self::LIST_PATHS, true) ? $path : 'none',
            'outcome' => self::knownOutcome($outcome),
        ];
        if ($count !== null) {
            $tags['count'] = in_array($count, self::COUNTS, true) ? $count : 'skip';
        }
        $this->timing('storage.read.published_list', $ms, $tags);
    }

    public function read(string $outcome, float $ms): void
    {
        $this->timing('storage.read', $ms, ['outcome' => self::knownOutcome($outcome)]);
    }

    public function write(string $operation, string $outcome, float $ms): void
    {
        if (!in_array($operation, self::OPERATIONS, true)) {
            return;
        }
        $this->timing('storage.write', $ms, ['operation' => $operation, 'outcome' => self::knownOutcome($outcome)]);
    }

    /** One committed rebuild chunk: its time and the rows it wrote. */
    public function rebuildChunk(float $ms, int $rows): void
    {
        $this->timing('storage.view.rebuild', $ms, ['outcome' => 'ok']);
        if ($rows > 0) {
            $this->count('storage.view.rebuild_rows', $rows);
        }
    }

    /**
     * Times a read and records its outcome; the exception is rethrown unchanged.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function timeRead(Closure $operation): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->read(self::outcomeOf($exception), self::elapsedMs($started));
            throw $exception;
        }
        $this->read('ok', self::elapsedMs($started));

        return $result;
    }

    /**
     * Times a write and records its operation and outcome; the exception is rethrown unchanged.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    public function timeWrite(string $code, Closure $operation): mixed
    {
        $started = hrtime(true);
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            $this->write($code, self::outcomeOf($exception), self::elapsedMs($started));
            throw $exception;
        }
        $this->write($code, 'ok', self::elapsedMs($started));

        return $result;
    }

    private static function knownOutcome(string $outcome): string
    {
        return in_array($outcome, self::OUTCOMES, true) ? $outcome : 'error';
    }

    /** @param array<string, string> $tags */
    private function timing(string $id, float $ms, array $tags): void
    {
        try {
            $timing = [$this->metrics(), 'timing'];
            if ($timing[0] !== null && is_callable($timing)) {
                $timing($id, $ms, $tags);
            }
        } catch (Throwable) {
            // Monitoring never breaks a storage operation.
        }
    }

    /** @param array<string, string> $tags */
    private function count(string $id, int $n, array $tags = []): void
    {
        try {
            $count = [$this->metrics(), 'count'];
            if ($count[0] !== null && is_callable($count)) {
                $count($id, $n, $tags);
            }
        } catch (Throwable) {
            // Monitoring never breaks a storage operation.
        }
    }

    private function metrics(): ?object
    {
        if (!$this->resolved) {
            $this->resolved = true;
            try {
                $this->metrics = $this->resolver !== null ? ($this->resolver)() : null;
            } catch (Throwable) {
                $this->metrics = null;
            }
        }

        return $this->metrics;
    }
}
