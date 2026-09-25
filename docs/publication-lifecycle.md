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

The state becomes `scheduled` and the live head keeps serving. The sweep publishes what
is due:

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
| published | ✓ | ✓ | ✓ | ✓ |
| archived | ✓ | ✗ | ✗ | ✓ |

An illegal transition writes nothing and says `invalid_transition`.

## Two tables

`larena_storage_publication_states` is the current truth, with the published head as a
column. `larena_storage_publication_log` is append-only and answers questions about the
past: `history()`, and which head an unpublish replaced. A current-state row cannot
answer a question about the past, which is why there are two.

Every transition writes both, in one transaction, and the log distinguishes a sweep
(`sweep_publish`) from an editor's publish. Same outcome, different accountability.
