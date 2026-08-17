# Implementation summary

Two named fixtures define site/page and organization hierarchies through ordinary Storage structures. Each node carries a stable typed relation identity and an optional typed parent relation. The round-trip test writes and reads both trees through `StorageWorkbench` and rejects any database table whose name introduces a Content owner.
