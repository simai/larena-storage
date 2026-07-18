# Independent review

Status: `pass`

Independent inspection confirmed that invalid schema constraints are rejected
before a Storage transaction or success Audit event. The review then required a
Property compatibility correction: the existing registry interface must remain
source-compatible, historical `string@1` semantics must stay immutable, and
safe UTF-8 behavior must use a new type version.

Storage now consumes the separate validator capability and fails closed when it
is unavailable. The final independent rerun returned `PASS`, with `P0=0`,
`P1=0`, `P2=0`. It proved constraint preflight before a new schema transaction,
exact reads of immutable legacy scalar-invalid schemas, public-only projection,
and fail-closed create/CAS even when an invalid optional field is omitted. No
head, immutable version or success Audit state changed on rejection.
