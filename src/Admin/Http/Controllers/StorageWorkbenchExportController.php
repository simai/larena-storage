<?php

declare(strict_types=1);

namespace Larena\Storage\Admin\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Larena\Storage\Admin\StorageWorkbenchDataviewPort;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A storage's records as CSV (owner decision 2026-10-19: export now, import later): the records the list shows,
 * with its search, filters and sort, in the columns the person sees. The rows come through the list's own port,
 * so the rights, the scope and the filters are the list's; at most EXPORT_LIMIT rows.
 */
final readonly class StorageWorkbenchExportController
{
    private const PAGE = 100;

    private const EXPORT_LIMIT = 10_000;

    public function __construct(private StorageWorkbenchDataviewPort $port, private Config $config) {}

    public function __invoke(Request $request, string $structure): StreamedResponse
    {
        $attribute = (string) $this->config->get('larena-auth.admin_entry.access_actor_attribute', 'larena_access_actor');
        $actor = $request->attributes->get($attribute);
        if (! is_string($actor) || trim($actor) === '' || strlen($actor) > 191) {
            throw new AccessDeniedHttpException('storage_export_actor_missing');
        }
        $scope = $request->query('scope_ref');
        if (! is_string($scope) || preg_match('/^scope:[a-z][a-z0-9_.:-]{0,184}$/D', $scope) !== 1
            || preg_match('/^workbench\.[a-z][a-z0-9_.:-]{0,109}$/D', $structure) !== 1) {
            throw new HttpException(422, 'storage_export_request_invalid');
        }
        $query = $this->query($request->query('query'));

        $columns = null;
        $rows = [];
        for ($page = 1; count($rows) < self::EXPORT_LIMIT; $page++) {
            // The export only pages on: no count, and the end is the first short page.
            $answer = $this->port->raise($actor, $scope, $structure, 'query.change', ['sequence' => $page,
                'query' => $query + ['page' => $page, 'per_page' => self::PAGE, 'count' => 'skip']]);
            if (($answer['answer'] ?? null) !== 'applied' || ! is_array($answer['data'] ?? null)) {
                throw new HttpException(($answer['answer'] ?? null) === 'refused' ? 403 : 503, 'storage_export_unavailable');
            }
            $data = $answer['data'];
            $columns ??= array_values(array_filter(is_array($data['columns'] ?? null) ? $data['columns'] : [],
                static fn (mixed $column): bool => is_array($column) && is_string($column['key'] ?? null) && ($column['visible'] ?? true) !== false
                    // An encrypted field never leaves through the CSV: not its plaintext, not its envelope, not its mask.
                    && ($column['encrypted'] ?? false) !== true));
            $records = is_array($data['records'] ?? null) ? $data['records'] : [];
            foreach ($records as $record) {
                if (is_array($record)) {
                    $rows[] = $record;
                }
            }
            if (count($records) < self::PAGE || ($data['echo']['page'] ?? $page) !== $page) {
                break;
            }
        }
        $rows = array_slice($rows, 0, self::EXPORT_LIMIT);
        // In the order the person arranged; a choice is written as the label they see, not its stored value.
        usort($columns, static fn (array $a, array $b): int => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));
        $labels = [];
        foreach ($columns as $column) {
            foreach (is_array($column['options'] ?? null) ? $column['options'] : [] as $option) {
                if (is_array($option) && is_scalar($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                    $labels[$column['key']][(string) $option['value']] = $option['label'];
                }
            }
        }

        return new StreamedResponse(static function () use ($columns, $rows, $labels): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            // The byte order mark makes spreadsheet programs read the file as UTF-8.
            fwrite($out, "\u{FEFF}");
            // The record's ID first: an edited file comes back through the import and updates the same records.
            fputcsv($out, ['ID', ...array_map(static fn (array $column): string => self::safe((string) ($column['label'] ?? $column['key'])), $columns)], ',', '"', '');
            foreach ($rows as $row) {
                $values = is_array($row['display_values'] ?? null) ? $row['display_values'] : [];
                fputcsv($out, [is_string($row['id'] ?? null) ? $row['id'] : '',
                    ...array_map(static fn (array $column): string => self::safe(self::text($values[$column['key']] ?? null, $labels[$column['key']] ?? [])), $columns)], ',', '"', '');
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^a-z0-9_.-]/', '-', $structure).'-'.date('Y-m-d').'.csv"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{search:string,filters:array<string,mixed>,sort:list<array<string,string>>} the list's query, as the page sent it */
    private function query(mixed $raw): array
    {
        $query = is_string($raw) && strlen($raw) <= 65_536 ? json_decode($raw, true) : null;
        $query = is_array($query) ? $query : [];

        return [
            'search' => is_string($query['search'] ?? null) ? mb_substr($query['search'], 0, 200) : '',
            'filters' => is_array($query['filters'] ?? null) && ! array_is_list($query['filters']) ? $query['filters'] : [],
            'sort' => is_array($query['sort'] ?? null) && array_is_list($query['sort']) ? array_slice(array_values(array_filter($query['sort'], 'is_array')), 0, 3) : [],
        ];
    }

    /**
     * A cell as text: the plain value, the label of a chosen option, or the text a formatted cell shows.
     *
     * @param  array<string, string>  $labels
     */
    private static function text(mixed $value, array $labels = []): string
    {
        return match (true) {
            is_string($value), is_int($value), is_float($value) => $labels[(string) $value] ?? (string) $value,
            is_bool($value) => $value ? '1' : '0',
            is_array($value) && array_is_list($value) => implode(', ', array_map(static fn (mixed $item): string => self::text($item, $labels), $value)),
            is_array($value) => self::text($value['text'] ?? $value['label'] ?? $value['value'] ?? '', $labels),
            default => '',
        };
    }

    /** A value a spreadsheet would read as a formula starts with an apostrophe. */
    private static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
