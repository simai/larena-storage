<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;

/**
 * The third first-run step: the starter site.
 *
 * It replaces larena/content's contributor and keeps its position, after the
 * administrator and the site settings, because the page is written by that
 * administrator under settings that already exist.
 */
final readonly class SiteFirstRunContributor implements FirstRunContributor
{
    public const ID = 'site';

    public function __construct(private StarterSite $site)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function priority(): int
    {
        return 300;
    }

    public function validate(FirstRunPayload $payload): array
    {
        return trim($payload->siteName) === '' ? ['site_name' => 'required'] : [];
    }

    public function state(): string
    {
        return $this->site->state();
    }

    public function apply(FirstRunPayload $payload, FirstRunContext $context): FirstRunContext
    {
        $recordId = $this->site->apply(
            $context->string('auth.subject_ref'),
            $payload->siteName,
            $payload->locale,
        );

        return $context->with('site.homepage_record_id', $recordId);
    }
}
