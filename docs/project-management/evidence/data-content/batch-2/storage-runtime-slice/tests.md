# Tests

Status: `passed`

Executed command:

```bash
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer run quality:gate
```

Observed passing checks:

- `validate-larena-package`: launch context is valid.
- PHP lint checked scripts, tools, `src` and `tests` with no syntax errors.
- PHPStan analysed scripts, tools, `src` and `tests` with no errors.
- `StorageSchemaContractTest passed.`
- `StorageFailsClosedTest passed.`
- `InMemoryStorageRuntimeTest passed.`
- `Larena Storage tests passed.`

Semantic checks:

- valid schema registration succeeds;
- missing schema query fails closed;
- missing access scope query fails closed;
- empty create payload blocks mutation;
- valid create mutation is allowed;
- filtered query returns the created record;
- hidden field projection is redacted;
- delete mutation removes the in-memory record.

- Evidence contract passed for the current repository state.
- Scope check passed for launch allowed files and evidence path.
- `git diff --check` passed.
