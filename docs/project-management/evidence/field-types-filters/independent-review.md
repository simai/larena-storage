# Independent review

Status: pending. The launch context stays `coding_started` with `review_completed=false` until an independent reviewer signs off.

Author self-review covered:

- Byte compatibility: the version-3 descriptor subset reproduces the previous registry fingerprint; the diff from it only adds `choice@1`, `choices@1`, `datetime@1` and `user@1`.
- PHP integer-key coercion for numeric-looking option values (for example `"1"`): the normalized `choices` list is converted back to strings and tested.
- Fail-closed paths: unknown operators, unsupported operator/type pairs, malformed filter shapes, invalid values, reversed `between` ranges, empty text fragments and oversized `in` lists all reject before any row is scanned.
- Determinism: canonical JSON sorts option object keys and keeps option order; the normalized filter set is part of the signed continuation hash.
- Memory: scan rows are iterated from a cursor and only matching records are retained; the 5000-row scan test stays under a 128 MiB peak-growth budget.

A delegated review agent was started but did not return findings before the batch closed.
