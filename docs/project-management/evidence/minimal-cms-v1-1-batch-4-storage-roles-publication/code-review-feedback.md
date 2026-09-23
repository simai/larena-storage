# Batch 4 wave A — review feedback

No independent review yet. What a reviewer should attack first:

1. **The `extend` wiring into the core registry.** `larena/core` composes its
   registry from a hard-coded provider list, so storage extends the resolved
   instance. It works and it keeps one catalogue, but the right fix is a tagged
   contributor port in core, and every further package will hit the same wall.
   This is a finding about Batch 3's wiring, surfaced by the first package that
   tried to use it.
2. **Conformance compares field types as strings.** `integer` must equal
   `integer`. There is no notion of an acceptable widening — a `string` field where
   the role wants `text` fails. A reviewer should decide whether Property's type
   registry should own that comparison.
3. **The optional scope resolver.** `DatabaseStructureRoleRegistry` accepts a null
   `ScopeRefResolver` so that a package test can run without the core scope tables,
   and treats null as "the caller has taken responsibility". A reviewer should
   confirm that is acceptable, or require the resolver and give the tests a real
   core schema.
4. **`binding_identity_too_long` is rejected, not truncated.** A truncated identity
   would collide with another binding and silently rebind a structure. The limit is
   190 characters and the composite is role_ref + schema_id + scope_ref; a reviewer
   should confirm the limit is generous enough for real schema names.

## Wave B

1. **The redaction rule is the part to attack.** Only ids the filter rejected are
   masked, plus the ids the caller supplied. A reviewer should look for a path where
   an id the caller may not read is neither in the filtered set nor supplied by the
   caller — the conservative fallback masks it, but the reasoning deserves a second
   pair of eyes, because this is the code that decides what a public reader learns
   about a private page.
2. **`relation_id` is rewritten on a move.** That keeps the frozen grammar true but
   means the primary key of a row is not stable across a move. A reviewer should
   confirm nothing outside this table stores a relation id.
3. **`detach` leaves the row with status `detached` and the child as its own
   parent.** That is a self-referencing edge, which is unusual. The alternative was
   deleting the edge, which would leave the child with no row at all and therefore
   no order and no path. A reviewer should decide which is less surprising.
4. **Reference targets are not validated.** The feature spec wants an unresolvable
   target refused before the record is written, which needs the record mutation
   path this wave does not touch. Recorded as not covered.

## Wave C

1. **Values are stored without validation.** The writer checks that a field is
   declared localized and that required fields are present, but it does not run the
   Property validation pipeline on the value itself — that pipeline lives in the
   record mutation path this wave does not touch. A reviewer should decide whether a
   localized write must go through it before wave E exposes these values to a
   reader.
2. **The localized flag lives only in the workbench structure descriptor.** Storage's
   own schema does not carry it, so `DatabaseLocalizedValues` trusts its caller's
   list of localized fields. A reviewer should decide whether that trust is
   acceptable or whether the schema normalizer must learn the key.
3. **`resolve()` reads the whole chain in one query.** For a very long chain and a
   very wide record that is a large `IN` set. A reviewer should sanity-check the
   shape against a realistic page before wave E starts reading through it.
4. **Absent versus empty is load-bearing.** `resolve()` omits a field it cannot find.
   Every consumer must treat a missing key as "not translated" rather than as an
   empty value, and wave E is the first consumer.

## Wave D

1. **The scheduled revision lives in the log, not in the state row.** `sweep()` reads
   the most recent `schedule` transition to learn which revision to publish. That keeps
   the state row honest — a scheduled record has no head — but it means the sweep
   depends on the log being complete. A reviewer should confirm that is acceptable, or
   ask for a `scheduled_revision` column.
2. **`archive` is allowed from `archived` and `publish` from `archived`.** Archiving
   twice is a no-op that still logs, and un-archiving is a plain publish. A reviewer
   should confirm both read as intended rather than as missing guards.
3. **The revision check is injected and may be absent.** A package test leaves it
   unbound. A reviewer should decide whether the runtime should refuse to construct
   without one.
4. **Nothing emits a domain event.** The feature spec allows one for automation; no
   consumer exists, so none is emitted. A reviewer should confirm the order.
5. **`previous_published_revision` survives an archive.** After archiving, the column
   still names the head that was live. That is deliberate — it is how "what was live
   before we took this down" is answered — but it means the column is not "the head
   before the current one" in every state.

## Wave E

1. **`resolveKey` scans the projection.** It reads up to the budget of published heads
   and compares the key field in PHP rather than querying it, because the key field's
   name is declared per schema and its value may be localized. That is correct and it is
   O(published records in the scope). A reviewer should decide when this needs an index
   or a materialized key table.
2. **The same is true of `SlugUniquenessGuard`.** It asks the projection, which keeps
   one definition of "published" but pays the same scan on every check.
3. **A role reference resolves to the first active binding.** If a role is bound to two
   structures in one scope, `resolveKey` silently picks one. A reviewer should decide
   whether that should be `ambiguous_role_binding`.
4. **The two defects the smoke found were both invisible to the unit tests**, because the
   tests and the fixture agreed with each other and disagreed with the migration. A
   reviewer should look for other fixtures in this package with the same problem.
5. **Nothing caches the projection.** Every read rebuilds it. That is the correct default
   for this batch — correctness before speed — but it is worth a measurement before the
   Admin surfaces start reading it per request.
