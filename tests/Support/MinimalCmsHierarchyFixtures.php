<?php

declare(strict_types=1);

namespace Larena\Storage\Tests\Support;

final readonly class MinimalCmsHierarchyFixtures
{
    /** @return array<string, mixed> */
    public static function siteStructure(): array
    {
        return self::descriptor('workbench.cms.site_tree', 'Site and page hierarchy', 'node_kind');
    }

    /** @return array<string, mixed> */
    public static function organizationStructure(): array
    {
        return self::descriptor('workbench.cms.organization_tree', 'Organization hierarchy', 'unit_kind');
    }

    /** @return list<array<string, mixed>> */
    public static function siteRecords(): array
    {
        return [
            self::record('018f5000-0000-7000-8000-000000000001', null, 'site', 'main', 'Main site', 10, 'node_kind'),
            self::record('018f5000-0000-7000-8000-000000000002', '018f5000-0000-7000-8000-000000000001', 'page', 'home', 'Home', 10, 'node_kind'),
            self::record('018f5000-0000-7000-8000-000000000003', '018f5000-0000-7000-8000-000000000001', 'page', 'docs', 'Documentation', 20, 'node_kind'),
            self::record('018f5000-0000-7000-8000-000000000004', '018f5000-0000-7000-8000-000000000003', 'page', 'guide', 'Guide', 10, 'node_kind'),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function organizationRecords(): array
    {
        return [
            self::record('018f6000-0000-7000-8000-000000000001', null, 'company', 'simai', 'SIMAI', 10, 'unit_kind'),
            self::record('018f6000-0000-7000-8000-000000000002', '018f6000-0000-7000-8000-000000000001', 'department', 'engineering', 'Engineering', 10, 'unit_kind'),
            self::record('018f6000-0000-7000-8000-000000000003', '018f6000-0000-7000-8000-000000000002', 'team', 'larena', 'Larena team', 10, 'unit_kind'),
        ];
    }

    /** @return array<string, mixed> */
    private static function descriptor(string $id, string $label, string $kindField): array
    {
        return [
            'structure_id' => $id,
            'label' => $label,
            'fields' => [
                self::field('node_id', 'Node identity', 10, 'relation', true),
                self::field('parent_id', 'Parent identity', 20, 'relation', false),
                self::field($kindField, 'Node kind', 30, 'string', true, ['min_length' => 1, 'max_length' => 32]),
                self::field('code', 'Stable code', 40, 'string', true, ['min_length' => 1, 'max_length' => 64]),
                self::field('label', 'Label', 50, 'string', true, ['min_length' => 1, 'max_length' => 160]),
                self::field('sort', 'Sort', 60, 'integer', true, ['min' => 0, 'max' => 10000]),
            ],
        ];
    }

    /** @param array<string, int> $constraints @return array<string, mixed> */
    private static function field(
        string $key,
        string $label,
        int $position,
        string $type,
        bool $required,
        array $constraints = [],
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'position' => $position,
            'type' => $type,
            'type_version' => 1,
            'required' => $required,
            'visibility' => 'admin',
            'constraints' => $constraints,
        ];
    }

    /** @return array<string, mixed> */
    private static function record(
        string $nodeId,
        ?string $parentId,
        string $kind,
        string $code,
        string $label,
        int $sort,
        string $kindField,
    ): array {
        $record = [
            'node_id' => $nodeId,
            $kindField => $kind,
            'code' => $code,
            'label' => $label,
            'sort' => $sort,
        ];
        if ($parentId !== null) {
            $record['parent_id'] = $parentId;
        }

        return $record;
    }
}
