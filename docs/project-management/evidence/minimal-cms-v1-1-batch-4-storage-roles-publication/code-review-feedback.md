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
