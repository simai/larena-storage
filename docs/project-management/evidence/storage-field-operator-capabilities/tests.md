# Checks

- StorageWorkbenchFieldPresentationTest passed on disposable file-backed SQLite: one structure and record with string, text, integer, number, boolean, date, datetime, choice, choices, user, file and relation; full typed edit with expected revision and stale-edit rejection; read after fresh connection; exact large decimal before and after edit; three owner-supplied reference labels; raw relation denied without resolver; string contains and number between execute; today is rejected.
- Composer quality:gate passed with PHP 8.4: preflight, metadata, lint, static analysis, package tests, evidence and scope checks.
- Property quality:gate passed independently with the new projection unit test.

No larena.test or working database was used.
