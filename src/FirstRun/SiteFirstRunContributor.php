<?php

declare(strict_types=1);

namespace Larena\Storage\FirstRun;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Connection;
use Larena\Core\Contracts\FirstRunContributor;
use Larena\Core\Contracts\FirstRunOptions;
use Larena\Core\FirstRun\FirstRunContext;
use Larena\Core\FirstRun\FirstRunPayload;
use Throwable;

/**
 * The third first-run step: the starter site.
 *
 * It replaces larena/content's contributor and keeps its position, after the
 * administrator and the site settings, because the page is written by that
 * administrator under settings that already exist.
 *
 * It offers «Установить демо-данные» (off unless chosen, owner decision
 * 2026-10-29): the storage demo, written as that administrator into the scope
 * they work in once the first run has committed. A storage's витрина table is made
 * outside a transaction, so the demo cannot run inside the first run's; if it
 * fails, the installation stands and the demo can be installed again with
 * larena:storage:demo.
 */
final readonly class SiteFirstRunContributor implements FirstRunContributor, FirstRunOptions
{
    public const ID = 'site';

    public const DEMO_DATA = 'demo_data';

    /** @param Closure(string): mixed $demo Installs the demo as the person, in the scope they work in. */
    public function __construct(
        private StarterSite $site,
        private ?Connection $connection = null,
        private ?Closure $demo = null,
        private ?Translator $translator = null,
        private ?ExceptionHandler $errors = null,
    ) {
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
        $actor = $context->string('auth.subject_ref');
        $recordId = $this->site->apply(
            $actor,
            $payload->siteName,
            $payload->locale,
        );
        if ($payload->option(self::DEMO_DATA) && $this->demo !== null && $this->connection !== null) {
            $demo = $this->demo;
            $errors = $this->errors;
            $this->connection->afterCommit(static function () use ($demo, $actor, $errors): void {
                try {
                    $demo($actor);
                } catch (Throwable $failure) {
                    $errors?->report($failure);
                }
            });
        }

        return $context->with('site.homepage_record_id', $recordId);
    }

    public function options(): array
    {
        if ($this->demo === null || $this->connection === null) {
            return [];
        }
        $label = $this->translator?->get('larena-storage::admin.first_run.demo_data');

        return [self::DEMO_DATA => is_string($label) && $label !== 'larena-storage::admin.first_run.demo_data' ? $label : 'Install demo data'];
    }
}
