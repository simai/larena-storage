<?php

declare(strict_types=1);

namespace Larena\Storage\Contracts;

use InvalidArgumentException;
use Larena\Storage\Enums\RoleLifecycle;
use Larena\Storage\Enums\RoleStatus;

/**
 * A structure role: a contract a structure can satisfy.
 *
 * A role owns no table and adds no behaviour. It names the fields and relations a
 * structure must have to play a part — a site node, a documentation page, an
 * organisation chart overlay — and a structure gains the role by conforming, not
 * by inheriting from anything. That is what keeps role-specific business code out
 * of Storage.
 */
final readonly class StructureRole
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,118}$/';

    public const REF_MAX_LENGTH = 140;

    /**
     * @param list<array{key: string, type: string}> $requiredFields
     * @param list<array{key: string, type: string}> $optionalFields
     * @param list<array{relation_key: string, kind: string, target_role_code: string}> $requiredRelations
     */
    public function __construct(
        public string $roleCode,
        public int $roleVersion,
        public string $title,
        public array $requiredFields,
        public array $optionalFields,
        public array $requiredRelations,
        public RoleLifecycle $lifecycle,
        public string $ownerPackage,
        public RoleStatus $status = RoleStatus::Active,
    ) {
        if (preg_match(self::CODE_PATTERN, $this->roleCode) !== 1) {
            throw new InvalidArgumentException('Structure role code is not a valid role code: ' . $this->roleCode);
        }

        if ($this->roleVersion < 1) {
            throw new InvalidArgumentException('Structure role version must be a positive integer.');
        }

        if (trim($this->title) === '') {
            throw new InvalidArgumentException('Structure role must have a title.');
        }

        if (strlen($this->ref()) > self::REF_MAX_LENGTH) {
            throw new InvalidArgumentException('Structure role reference exceeds ' . self::REF_MAX_LENGTH . ' characters.');
        }
    }

    /**
     * One addressable string for a versioned role.
     */
    public function ref(): string
    {
        return $this->roleCode . '@v' . $this->roleVersion;
    }

    public function isPublishable(): bool
    {
        return $this->lifecycle === RoleLifecycle::Publishable;
    }

    /**
     * @return list<string>
     */
    public function requiredFieldKeys(): array
    {
        return array_map(static fn (array $field): string => $field['key'], $this->requiredFields);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role_ref' => $this->ref(),
            'role_code' => $this->roleCode,
            'role_version' => $this->roleVersion,
            'title' => $this->title,
            'required_fields' => $this->requiredFields,
            'optional_fields' => $this->optionalFields,
            'required_relations' => $this->requiredRelations,
            'lifecycle' => $this->lifecycle->value,
            'owner_package' => $this->ownerPackage,
            'status' => $this->status->value,
        ];
    }
}
