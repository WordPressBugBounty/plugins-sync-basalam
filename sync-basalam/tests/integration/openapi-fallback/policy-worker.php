<?php

require __DIR__ . '/bootstrap.php';

use SyncBasalam\Services\Api\OpenApiFallbackPolicy;

if ($argv[1] === 'read') {
    echo json_encode((new OpenApiFallbackPolicy())->isActive());
    exit;
}

// Synchronize independent PHP processes, each with its own DB connection/cache.
$deadline = microtime(true) + 10;
while (!is_file($argv[1])) {
    if (microtime(true) > $deadline) exit(2);
    usleep(1000);
}
(new OpenApiFallbackPolicy())->recordConnectionFailure();
echo "ok";
