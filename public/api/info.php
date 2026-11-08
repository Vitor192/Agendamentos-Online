<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// Dados públicos que o chat precisa: nome, profissionais, serviços e dias disponíveis.
$cfg = config();
json_response([
    'shop_name'     => $cfg['shop_name'],
    'professionals' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], $cfg['professionals']),
    'services'      => array_map(fn($s) => [
        'id' => $s['id'], 'name' => $s['name'], 'minutes' => $s['minutes'], 'price' => $s['price'],
    ], $cfg['services']),
    'dates'         => bookable_dates(),
]);
