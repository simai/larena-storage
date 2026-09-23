# Batch 7 wave A — Storage public read contracts over REST

`api.yaml` declares `storage.read.resolve_key` and `storage.read.published_projection` as anonymous GET operations under `/api/v1/site/`. Both carry no access scope, by owner decision: they return only published heads and public fields. `storage.read.projection_explain` keeps `storage.read.public`.

## Wave D2

Record writes had no registry operation, and the Access codes Storage's operations name were declared in access.yaml but never registered, so every check denied. Storage now declares storage.record.create and storage.record.update over the versioned storage contract, registers its handler references in core's catalog, and registers the role, relation, locale, publication and public-read codes.
