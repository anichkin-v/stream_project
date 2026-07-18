<?php

declare(strict_types=1);

['config' => $config, 'pdo' => $pdo] = require dirname(__DIR__) . '/src/bootstrap.php';

echo "База данных готова.\n";
