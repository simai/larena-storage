# Smoke

Status: `passed_static_only`

Smoke targets:

- Composer metadata validates.
- Autoload generation succeeds.
- Contract files load through Composer autoload.
- Contract tests pass.
- Scope checker accepts only launch-record files and evidence path.

Result:

All smoke targets passed through the package-local quality gate. Runtime storage
persistence was not started and remains outside this batch.
