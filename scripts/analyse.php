<?php

declare(strict_types=1);

$phpstan = 'vendor/bin/phpstan';
if (!is_file($phpstan)) {
    echo "PHPStan is not installed; skipping static analysis until composer install runs.\n";
    exit(0);
}
passthru($phpstan . ' analyse --configuration=phpstan.neon.dist --no-progress', $exitCode);
exit($exitCode);
