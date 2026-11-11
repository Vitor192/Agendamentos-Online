<?php
declare(strict_types=1);
require __DIR__ . '/../../../lib.php';

// POST /api/admin/login.php  JSON: password
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}
if (!admin_configured()) {
    json_response(['error' => 'A senha do dono ainda não foi configurada.'], 503);
}
if (login_blocked()) {
    json_response(['error' => 'Muitas tentativas. Aguarde 10 minutos e tente de novo.'], 429);
}

$in = json_decode((string)file_get_contents('php://input'), true);
$password = is_array($in) ? (string)($in['password'] ?? '') : '';

if ($password === '' || !password_verify($password, (string)config()['admin_password_hash'])) {
    login_failed();
    usleep(500000); // atrasa quem tenta adivinhar
    json_response(['error' => 'Senha incorreta.'], 401);
}

admin_session_start();
session_regenerate_id(true);
$_SESSION['admin'] = true;
$_SESSION['seen']  = time();
login_clear();
json_response(['ok' => true]);
