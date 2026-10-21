# Publication lifecycle

A record is published per **scope** and per **locale**, so one site can serve it and
another not, and English can be live while Russian is still a draft.

```php
$publication->publish($schemaId, $recordId, 'site:main', 'en', $revision, $actorId);
$publication->head($schemaId, $recordId, 'site:main', 'en')->publishedRevision;
```

Publishing changes only which revision is the head. It never touches the revision, its
values or its localized values — that is what makes an immutable revision worth having,
and a test asserts it byte for byte.

## Four operations, four rights

`publish`, `unpublish`, `schedule` and `archive` each have their own access code, so an
editor can save and schedule without holding the right to publish.

## Scheduling does not publish

```php
$publication->schedule($schemaId, $recordId, 'site:main', 'en', $revision, '2026-10-01 09:00:00', $actorId);
```

A record that is not published becomes `scheduled`, which is not live. A record that is
published stays `published`: its head keeps serving the site, and the state row carries
the pending revision (`scheduled_revision`, `scheduled_at`; `PublicationState::
hasPendingRevision()`) until the sweep promotes it. The old page stays on the site until
the new one goes out.

| With a pending schedule | Result |
| --- | --- |
| the sweep, when due | the scheduled revision becomes the head (`sweep_publish`); the published-key index, the site витрина and observers move with it |
| schedule again | the new schedule replaces the pending one; the head stays live |
| publish | the published revision becomes the head; the schedule is cleared |
| unpublish | the record leaves the site; the schedule is cleared |
| archive | the record is archived; the schedule is cleared |

A schedule the guard refuses at sweep time (a key taken in the meantime) stays pending
and the old head stays live. The sweep promotes only a schedule that is still pending
when it reaches the record.

The sweep publishes what is due:

```php
$publication->sweep($actorId);           // run by a worker, the console, or a request boundary
$publication->dueSchedules();            // what a sweep would publish, without publishing
```

A read never publishes. A read that mutated state would make every page view a write.

## The transition matrix

| From | publish | unpublish | schedule | archive |
| --- | --- | --- | --- | --- |
| absent / draft | ✓ | ✗ | ✓ | ✓ |
| scheduled | ✓ | ✓ | ✓ | ✓ |
| published (with or without a pending schedule) | ✓ | ✓ | ✓ | ✓ |
| archived | ✓ | ✗ | ✗ | ✓ |

An illegal transition writes nothing and says `invalid_transition`.

## Two tables

`larena_storage_publication_states` is the current truth, with the published head as a
column. `larena_storage_publication_log` is append-only and answers questions about the
past: `history()`, and which head an unpublish replaced. A current-state row cannot
answer a question about the past, which is why there are two.

Every transition writes both, in one transaction, and the log distinguishes a sweep
(`sweep_publish`) from an editor's publish. Same outcome, different accountability.
