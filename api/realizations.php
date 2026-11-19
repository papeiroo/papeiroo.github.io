<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
header('Cache-Control: public, max-age=60');
$items = array_values(array_filter(store_read('realizations', []), fn($r) => empty($r['hidden'])));
json_out($items);
