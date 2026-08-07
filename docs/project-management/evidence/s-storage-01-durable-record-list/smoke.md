# Smoke and restart evidence

The corrected focused integration test uses disposable file-backed SQLite only. It creates two unrelated schemas and alpha/beta records whose tenant key is `protected`, disconnects the writer, launches a new PHP process against the same bytes, then repeats against a byte-identical copy in another disposable path. Payload and continuation identity remain equal; protected scope values remain absent.

Exact read-only dependency revisions:

| Package | Revision |
| --- | --- |
| larena/access | `71fb85a5ce407720e1c5afee4294d2cd098bd596` |
| larena/audit | `cc6ba3ccf279eefdef3fa3973249629a3a100feb` |
| larena/core | `8c983ee3ff6e91b4320627a9a6c5ece427cfb111` |
| larena/dataview | `b84e964b4ed78e1ca08a46c88e7651b02744ee47` |
| larena/licensing | `52d1215a25369cca17d5170bbfcae82d1f6c86d2` |
| larena/property | `7773692a9e1cf60f641a050e4ebf99e1fe37c159` |
| larena/ui | `4ff429cb47b8ebbe232d683ee1333d3a27ad417e` |

No dependency source is changed.

Executor-owned `--no-local` assembly: `/tmp/larena-storage-s01-correction.ou1b3O/packages/storage`, exact implementation commit `5de3c1709663dde50395012384a6745ba199f9f9`, tree `7c5a28d5f35bb717ebe93b1f121f2e28dfe19816`. All seven dependencies were independently cloned and detached at the table revisions. Fresh Composer install performed 74 installs. Focused 30-scenario list test, 7-scenario real-provider/container test, strict Composer validation and full quality gate all exited `0`; checkout remained clean.

Exact commands in that assembly (all exit `0`):

```text
/opt/homebrew/opt/php@8.3/bin/php /Applications/ServBay/bin/composer install --no-interaction --prefer-dist
/opt/homebrew/opt/php@8.3/bin/php tests/Integration/VersionedStorageRecordListTest.php
/opt/homebrew/opt/php@8.3/bin/php tests/Integration/VersionedStorageRecordListProviderBindingTest.php
/opt/homebrew/opt/php@8.3/bin/php /Applications/ServBay/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /opt/homebrew/opt/php@8.3/bin/php /Applications/ServBay/bin/composer run quality:gate
git status --porcelain
```

The final evidence successor is bound by `verification.json` to its containing Git commit/tree, avoiding a circular self-hash. A new no-local clone of that exact containing commit is required after the evidence commit and is reported in the executor handoff. Neither clone is described as an independent auditor review.
