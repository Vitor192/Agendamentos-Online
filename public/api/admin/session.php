<?php
declare(strict_types=1);
require __DIR__ . '/../../../lib.php';

// Estado do acesso: a página usa isto para decidir entre login, aviso de configuração e agenda.
// Não revela nada além do nome da barbearia e se a sessão está ativa.
json_response([
    'configured' => admin_configured(),
    'logged'     => admin_configured() && is_admin(),
    'shop_name'  => config()['shop_name'],
    'today'      => date('Y-m-d'),
]);
