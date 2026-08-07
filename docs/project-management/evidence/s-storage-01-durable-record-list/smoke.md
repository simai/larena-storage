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

No dependency source is changed. A fresh executor-owned `--no-local` assembly and exact final containing-commit verification will be recorded after commits exist. This is executor reproduction, not an independent auditor review.
