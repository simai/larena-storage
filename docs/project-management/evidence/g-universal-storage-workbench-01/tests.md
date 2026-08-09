# Tests

M1 package-head verification:

- `composer validate --strict --no-check-publish`: PASS.
- PHP lint: PASS (96 PHP files).
- PHPStan: PASS (zero errors).
- `StorageWorkbenchTest.php`: PASS, including two unrelated generic models,
  foreign scope/direct ID denial, CAS conflict, additive/incompatible schema
  cases, signed-cursor tamper, deterministic typed pagination, history, atomic
  bulk failure and four byte-identical fresh PHP process reads.
- `StorageWorkbenchProviderBindingTest.php`: PASS.
- complete `composer quality:gate`: PASS; all predecessor Storage suites remain
  green and the optional MySQL-only predecessor test remains explicitly skipped.
- metadata, evidence and scope gates: PASS (23 changed files at this checkpoint).
