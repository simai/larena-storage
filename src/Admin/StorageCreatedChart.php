<?php

declare(strict_types=1);

namespace Larena\Storage\Admin;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Support\HtmlString;
use Larena\Ui\Smart;
use Throwable;

/**
 * The chart under a storage's records: how many records were created on each of the last 30 days, drawn by the SIMAI
 * Chart set's line chart (sf-chart-line; the set is declared in composer.json, extra.larena.component_packages.chart).
 * The element comes from larena/ui's Smart renderer, checked against the pinned runtime; the days are its `items`.
 *
 * No chart is shown when there is nothing to draw (no new records in the period) or the pinned runtime cannot draw it
 * (the Smart renderer does not know the tag or refuses its settings): the records page stays as it was.
 */
final readonly class StorageCreatedChart
{
    public const TAG = 'sf-chart-line';

    public function __construct(private Translator $translator)
    {
    }

    /**
     * The element's settings for the given days.
     *
     * @param list<array{day: string, created: int}> $days
     * @return array<string, mixed>
     */
    public function props(array $days): array
    {
        return [
            'label' => $this->copy('label'),
            'items' => array_map(static fn (array $day): array => ['day' => $day['day'], 'created' => $day['created']], $days),
            'x' => 'day',
            'y' => 'created',
            'names' => ['created' => $this->copy('series')],
            'x-scale' => 'time',
            'y-zero' => true,
            'y-format' => 'integer',
            'y-label' => $this->copy('axis'),
            'area' => true,
            'legend' => 'none',
            'size' => '1/2',
        ];
    }

    /**
     * The chart's markup, or null when there is nothing to draw or the pinned runtime cannot draw it.
     *
     * @param list<array{day: string, created: int}>|null $days
     */
    public function markup(?array $days): ?HtmlString
    {
        if ($days === null || array_sum(array_column($days, 'created')) === 0 || !class_exists(Smart::class)) {
            return null;
        }
        try {
            $description = '<p slot="description">' . htmlspecialchars($this->copy('note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

            return new HtmlString(Smart::render(self::TAG, $this->props($days), null, ['data-larena-storage-created-chart' => 'chart'], $description)->html);
        } catch (Throwable) {
            return null;
        }
    }

    private function copy(string $key): string
    {
        $text = $this->translator->get('larena-storage::admin.section.created_chart.' . $key);

        return is_string($text) ? $text : $key;
    }
}
