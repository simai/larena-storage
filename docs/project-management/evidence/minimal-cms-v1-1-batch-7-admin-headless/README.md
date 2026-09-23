# Batch 7 wave A — Storage public read contracts over REST

`api.yaml` declares `storage.read.resolve_key` and `storage.read.published_projection` as anonymous GET operations under `/api/v1/site/`. Both carry no access scope, by owner decision: they return only published heads and public fields. `storage.read.projection_explain` keeps `storage.read.public`.
