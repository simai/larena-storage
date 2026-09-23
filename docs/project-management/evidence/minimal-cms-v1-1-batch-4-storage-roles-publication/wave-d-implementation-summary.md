# Batch 4 wave D — Publication lifecycle

Repository: `simai/larena-storage`
Branch: `feature/minimal-cms-v1-1-batch-4-storage-publication-lifecycle` from wave C at `9c515d9`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-4-wave-d-storage-publication-lifecycle.json`

## What exists now

A record has a publication state per scope and per locale. One table holds the
current truth — state, published head, the head it replaced, the schedule — and a
second, append-only table holds every transition.

Two tables, and not for symmetry. `storage.publication.history` and "which head did
this unpublish replace" are both questions about the past, and a current-state row
cannot answer a question about the past at all. Each transition writes the state row
and appends exactly one log row **inside the same transaction**: a state change nobody
can account for would be unacceptable in a system whose point is sanitized
attribution.

## Four operations, four access codes

Publish, unpublish, schedule and archive are four operations with four distinct access
codes, not one operation with a mode argument. That is what lets an editor save and
schedule without holding the right to publish, which is exactly what the accepted
target state asks for. A test asserts the four codes are distinct rather than merely
present.

## A schedule is not a publication

Scheduling records an intention and leaves the head alone: a record that is already
published keeps serving its current revision until the schedule fires. The state
`scheduled` never carries a published revision.

The sweep publishes what is due, and it is the only thing that does. A read never
publishes — a read that mutated state would make every page view a write. The sweep is
idempotent, bounded by a limit, and it distinguishes itself in the log:
`sweep_publish` rather than `publish`, because the outcome is the same and the
accountability is not.

`dueSchedules()` is a read that answers what a sweep would publish, so the sweep's
proposal is a genuine dry run rather than a description of the request.

## The transition matrix

| From | publish | unpublish | schedule | archive |
| --- | --- | --- | --- | --- |
| absent / draft | yes | **no** | yes | yes |
| scheduled | yes | yes | yes | yes |
| published | yes | yes | yes | yes |
| archived | yes | **no** | **no** | yes |

The matrix is data rather than a chain of conditions, so the legal moves can be read
in one place. An illegal transition writes nothing — not the state row and not a log
row — and says `invalid_transition`.

## Publishing changes nothing but the head

A test writes a localized value, then publishes, unpublishes and archives, and asserts
the localized rows are byte-identical afterwards. That is the promise which makes an
immutable revision worth having, so it is asserted rather than assumed.

In the composed application the lifecycle also verifies that the revision exists for
the record: a head pointing at a revision nobody can read would be worse than no head
at all. The check is injected, so a package test can run without the record tables and
the runtime cannot skip it.

## Gates

`composer quality:gate` passes, PHPStan level 5, five new test files.
