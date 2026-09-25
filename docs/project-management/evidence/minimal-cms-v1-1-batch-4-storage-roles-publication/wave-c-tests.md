# Batch 4 wave C — tests

| Test | What it proves |
| --- | --- |
| `LocalizedValuesTest` | A write reports what it wrote; a read is ordered and marked exact; two locales coexist on one revision without touching each other; a new revision leaves the old translation readable; a structured value round trips through the JSON column; an empty string and a null are present values; a locale with nothing is an empty read rather than an error; `explain` names the stored locales and carries no values; the content hash covers the encoded value |
| `LocaleFallbackTest` | An exact hit; a fallback naming its source locale while remembering what was requested; a locale with nothing falling back for everything; the chain walked in order so reversing it changes the winner; a single-locale chain not falling back; **an absent field absent rather than null, and an empty value present** — the two halves of the same contract; a field subset; four malformed locales refused |
| `LocaleCoverageTest` | Present and missing named per locale; completeness per record; coverage per revision so a new revision starts empty while the old one stays complete; an undeclared locale not reported even when rows exist; a schema with no localized fields trivially complete; the report carrying no values |
| `LocalizedValuesFailsClosedTest` | Immutability refusing a rewrite and **a direct insert bypassing the class refused by the unique index**; an undeclared field refused with nothing written; a required field missing in a locale refused unless partial locales are allowed, and the permitted gap still reported; an invalid revision and three malformed locales; `schema_missing` on all three read paths |
| `WorkbenchLocalizedFieldKeyTest` | The eight-key shape still accepted, the nine-key shape accepted, anything else still refused, a non-boolean flag refused, an omitted key meaning not localized, and the flag deliberately kept out of the storage schema with the reason written where the next reader will look |

The behavioural compatibility proof for the workbench is
`tests/Integration/StorageWorkbenchTest.php`, which defines structures with the
eight-key shape and runs in the same suite. It is what caught the storage-schema
mistake on the first attempt.
