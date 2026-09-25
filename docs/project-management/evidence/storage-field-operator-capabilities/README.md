# Storage field operator capabilities

Date: 2026-09-25. Local package implementation and quality gate passed. Frontend integration, independent review and release acceptance are separate.

StorageWorkbench now exposes filterOperators(), a read-only map of field type to executable operator names. DatabaseStorageWorkbench returns the same rule table that validates listRecords filters. Relative periods today, week and month remain host transformations, not Storage operators.

The disposable integration creates one Storage structure containing all 12 built-in Property type keys, writes one record, queries through advertised operators, refuses a host-only operator, reopens the file-backed SQLite database and projects every stored value through Property. No migration, existing filter behavior or authorization rule changed.
