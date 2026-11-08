<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// Dados públicos que o frontend precisa para montar o calendário.
$cfg = config();
json_response([
    'shop_name'  => $cfg['shop_name'],
    'days_ahead' => $cfg['days_ahead'],
    'today'      => date('Y-m-d'),
]);
