# Storage admin section: structure and record lifecycle

Date: 2026-09-27. Stage 1 of the owner-approved Storage admin section plan (larena-specs `docs/architecture/storage-admin-section.md`). Local package implementation and quality gate passed. Access presets, the admin section, browser acceptance and independent review are separate.

A structure can be archived and restored; while archived it keeps its records, is listed only on request and accepts no record writes or edits. An archived structure can be deleted permanently with all of its records. Archived records can be deleted permanently one by one or in bulk. A field can be hidden; hiding is presentation only and keeps the field and its values.
