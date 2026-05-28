<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*Test.php') as $testFile) {
    require $testFile;
}

exit(run_tests());
