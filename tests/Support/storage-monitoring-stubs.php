<?php

declare(strict_types=1);

// Stand-ins for larena/monitoring's Metrics contract and MetricCatalog, which storage does not depend on.
// They are declared only when the real package is absent, so the storage tests can observe what reaches them.

namespace Larena\Monitoring\Contracts {
    if (!interface_exists(Metrics::class)) {
        interface Metrics
        {
            /** @param array<string, string> $tags */
            public function count(string $id, int $n = 1, array $tags = []): void;

            /** @param array<string, string> $tags */
            public function timing(string $id, float $ms, array $tags = []): void;
        }
    }
}

namespace Larena\Monitoring\Runtime {
    if (!class_exists(MetricCatalog::class)) {
        final class MetricCatalog
        {
            /** @var array<string, string> */
            public array $sources = [];

            public function register(string $package, string $yamlPath): void
            {
                $this->sources[$package] = $yamlPath;
            }
        }
    }
}

namespace {
    use Larena\Monitoring\Contracts\Metrics;

    /** Records every metric that reaches it; it has the real contract's four methods. */
    final class StorageMetricsRecorder implements Metrics
    {
        /** @var list<array{kind: string, id: string, value: float, tags: array<string, string>}> */
        public array $records = [];

        public function count(string $id, int $n = 1, array $tags = []): void
        {
            $this->records[] = ['kind' => 'count', 'id' => $id, 'value' => (float) $n, 'tags' => $tags];
        }

        public function timing(string $id, float $ms, array $tags = []): void
        {
            $this->records[] = ['kind' => 'timing', 'id' => $id, 'value' => $ms, 'tags' => $tags];
        }

        /** @param array<string, string> $tags */
        public function gauge(string $id, float $value, array $tags = []): void
        {
            $this->records[] = ['kind' => 'gauge', 'id' => $id, 'value' => $value, 'tags' => $tags];
        }

        /**
         * @param callable(): mixed $operation
         * @param array<string, string> $tags
         */
        public function measure(string $id, callable $operation, array $tags = []): mixed
        {
            return $operation();
        }

        /** @return list<string> one "id kind tag=value&…" line per record of the id, in order */
        public function lines(?string $id = null): array
        {
            $lines = [];
            foreach ($this->records as $record) {
                if ($id === null || $record['id'] === $id) {
                    $tags = $record['tags'];
                    ksort($tags);
                    $lines[] = $record['id'] . ' ' . http_build_query($tags);
                }
            }

            return $lines;
        }

        public function reset(): void
        {
            $this->records = [];
        }
    }
}
