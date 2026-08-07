# Smoke and restart evidence

The focused integration test uses only disposable file-backed SQLite databases. It creates data, disconnects the writer connection, launches a new PHP process against the same database file and verifies a scoped bounded list. It then copies the closed database bytes to another disposable path, launches another process and requires byte-for-byte-equal JSON result and continuation identity.

Dependency wiring for executor verification is explicit and read-only:

| Package | Revision |
| --- | --- |
| larena/access | `71fb85a5ce407720e1c5afee4294d2cd098bd596` |
| larena/audit | `cc6ba3ccf279eefdef3fa3973249629a3a100feb` |
| larena/core | `8c983ee3ff6e91b4320627a9a6c5ece427cfb111` |
| larena/dataview | `b84e964b4ed78e1ca08a46c88e7651b02744ee47` |
| larena/licensing | `52d1215a25369cca17d5170bbfcae82d1f6c86d2` |
| larena/property | `7773692a9e1cf60f641a050e4ebf99e1fe37c159` |
| larena/ui | `4ff429cb47b8ebbe232d683ee1333d3a27ad417e` |

No dependency source was changed.

Independent reproduction used `/tmp/larena-storage-s01-clean.rIAJii/packages/storage`, created with `git clone --no-local` at Storage revision `410ec36d1e983a5486fef5033ab5d7bb0729b641`; every dependency was separately cloned with `--no-local` and detached at the revision above. A fresh Composer install completed with 74 installs. The focused 21-scenario restart/metamorphic test, `composer validate --strict` and the full `composer run quality:gate` all exited `0`.

The first orchestration wrapper correctly created and verified all clones but then invoked Composer from `/tmp`, where no `composer.json` exists. That wrapper failed before tests. It was corrected by setting the exact clean Storage clone as working directory; the unchanged commands then passed as recorded above.
