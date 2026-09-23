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
