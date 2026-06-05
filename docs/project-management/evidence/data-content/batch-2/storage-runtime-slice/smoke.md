# Smoke

Status: `passed_package_runtime_smoke`

Smoke targets:

- Composer autoload loads storage runtime classes.
- In-memory runtime registers a schema.
- Runtime creates a record through a validation-gated mutation.
- Runtime lists records through a guarded query.
- Runtime redacts hidden field values in returned projections.
- Runtime deletes an in-memory record.
- Runtime returns no records after deletion.

Out-of-scope smoke targets:

- Laravel database persistence;
- migrations;
- admin UI;
- SitePack import/export;
- encryption key resolution;
- production query adapters.
