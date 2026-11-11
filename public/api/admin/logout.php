<?php
declare(strict_types=1);
require __DIR__ . '/../../../lib.php';

// POST /api/admin/logout.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}
admin_logout();
json_response(['ok' => true]);
