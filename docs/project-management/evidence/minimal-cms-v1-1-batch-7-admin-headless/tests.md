# Tests

MariaDbDriverDetectionTest: both guards see MariaDB behind the mysql driver, keep MySQL and SQLite; fails without the fix. On MariaDB 13.0.2: all 60 root migrations run; the structure editors journey passes 11/11 at 1440 and 390 with restart readback and public-site probes; the root failure-semantics tests pass 9/9.
