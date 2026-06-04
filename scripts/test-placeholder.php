<?php

declare(strict_types=1);

$tests = [
    __DIR__ . '/../tests/Unit/StorageSchemaContractTest.php',
    __DIR__ . '/../tests/Unit/StorageFailsClosedTest.php',
];

foreach ($tests as $test) {
    require $test;
}

echo "Larena Storage contract tests passed.\n";
