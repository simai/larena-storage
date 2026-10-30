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
 * The pages it writes become an ordinary storage, «Страницы сайта» (SitePagesStorage, owner decision 11), once
 * the first run has committed: the adoption builds the pages' витрина table, which is made outside a transaction.
 * If it fails, the installation stands, the pages are written all the same, and larena:storage:adopt-site-pages
 * adopts them. state() reads the pages, adopted or not.
 *
 * After the pages, the site gets its «Переадресации» storage (SiteRedirectsStorage, step 2.6), by the same route and
 * for the same reason; if it fails, larena:storage:site-redirects makes it.
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

    /**
     * @param Closure(string): mixed $demo Installs the demo as the person, in the scope they work in.
     * @param Closure(string, string): mixed $pages Adopts the site pages as the person, labelled in the locale.
     * @param Closure(string, string): mixed $redirects Gives the site its redirects storage as the person, in the locale.
     */
    public function __construct(
        private StarterSite $site,
        private ?Connection $connection = null,
        private ?Closure $demo = null,
        private ?Translator $translator = null,
        private ?ExceptionHandler $errors = null,
        private ?Closure $pages = null,
        private ?Closure $redirects = null,
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
        $errors = $this->errors;
        if ($this->pages !== null && $this->connection !== null) {
            $pages = $this->pages;
            $locale = $payload->locale;
            // Before the demo: the pages are the site's, the demo is an extra.
            $this->connection->afterCommit(static function () use ($pages, $actor, $locale, $errors): void {
                try {
                    $pages($actor, $locale);
                } catch (Throwable $failure) {
                    $errors?->report($failure);
                }
            });
        }
        if ($this->redirects !== null && $this->connection !== null) {
            $redirects = $this->redirects;
            $locale = $payload->locale;
            $this->connection->afterCommit(static function () use ($redirects, $actor, $locale, $errors): void {
                try {
                    $redirects($actor, $locale);
                } catch (Throwable $failure) {
                    $errors?->report($failure);
                }
            });
        }
        if ($payload->option(self::DEMO_DATA) && $this->demo !== null && $this->connection !== null) {
            $demo = $this->demo;
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
