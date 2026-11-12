<?php
declare(strict_types=1);
require __DIR__ . '/../../../lib.php';

// POST /api/admin/cancel.php  JSON: id, reason (chave de cancel_reasons)
// Só o dono logado cancela. O horário volta a ficar livre para novos clientes.
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}
// Pedidos de outros sites não conseguem enviar JSON sem autorização do navegador.
if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    json_response(['error' => 'Requisição inválida.'], 415);
}

$in = json_decode((string)file_get_contents('php://input'), true);
$id = is_array($in) ? (int)($in['id'] ?? 0) : 0;
$reason = is_array($in) ? (string)($in['reason'] ?? '') : '';
if ($id <= 0 || !isset(config()['cancel_reasons'][$reason])) {
    json_response(['error' => 'Escolha o motivo do cancelamento.'], 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT * FROM bookings WHERE id = ?');
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    json_response(['error' => 'Agendamento não encontrado.'], 404);
}
if ($row['status'] !== 'confirmed') {
    json_response(['error' => 'Esse agendamento já foi cancelado.'], 409);
}
$today = date('Y-m-d');
// Hoje o dono ainda pode cancelar (ex.: cliente que não apareceu); dias anteriores ficam só no histórico.
if ($row['date'] < $today) {
    json_response(['error' => 'Esse atendimento já passou e não pode ser cancelado.'], 422);
}

$up = $pdo->prepare(
    "UPDATE bookings SET status = 'cancelled', cancel_reason = ?, cancelled_at = datetime('now')
     WHERE id = ? AND status = 'confirmed'"
);
$up->execute([$reason, $id]);
if ($up->rowCount() === 0) {
    json_response(['error' => 'Esse agendamento já foi cancelado.'], 409);
}

$row['status'] = 'cancelled';
$row['cancel_reason'] = $reason;
json_response(['ok' => true, 'notify_link' => client_notify_link($row)]);
