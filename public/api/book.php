<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// POST /api/book.php  (JSON: date, time, name, phone, email)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['error' => 'Requisição inválida.'], 400);
}

$dateStr = trim((string)($in['date'] ?? ''));
$time    = trim((string)($in['time'] ?? ''));
$name    = trim((string)($in['name'] ?? ''));
$phone   = preg_replace('/\D+/', '', (string)($in['phone'] ?? ''));
$email   = trim((string)($in['email'] ?? ''));

$errors = [];
$date = parse_date($dateStr);
if (!$date || !is_bookable_date($date)) {
    $errors['date'] = 'Escolha um dia útil (segunda a sexta) dentro do período permitido.';
}
if (!in_array($time, day_slots(), true)) {
    $errors['time'] = 'Horário inválido.';
} elseif ($date && $dateStr === date('Y-m-d') && $time <= date('H:i')) {
    $errors['time'] = 'Esse horário já passou.';
}
if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
    $errors['name'] = 'Informe seu nome completo.';
}
if (strlen($phone) < 10 || strlen($phone) > 13) {
    $errors['phone'] = 'Informe um telefone válido com DDD.';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120) {
    $errors['email'] = 'Informe um e-mail válido.';
}
if ($errors) {
    json_response(['error' => 'Verifique os dados informados.', 'fields' => $errors], 422);
}

try {
    $st = db()->prepare(
        'INSERT INTO appointments (date, time, name, phone, email) VALUES (?, ?, ?, ?, ?)'
    );
    $st->execute([$dateStr, $time, $name, $phone, $email]);
} catch (PDOException $e) {
    // 19 = violação de UNIQUE no SQLite: outro cliente reservou primeiro.
    if (($e->errorInfo[1] ?? 0) === 19) {
        json_response(['error' => 'Esse horário acabou de ser reservado por outro cliente. Escolha outro.'], 409);
    }
    error_log($e->getMessage());
    json_response(['error' => 'Erro ao salvar o agendamento.'], 500);
}

$appointment = ['date' => $dateStr, 'time' => $time, 'name' => $name, 'phone' => $phone, 'email' => $email];
$message = confirmation_message($appointment);

json_response([
    'ok'            => true,
    'appointment'   => $appointment,
    'whatsapp_sent' => send_whatsapp($message),
    // Alternativa quando a API do WhatsApp não está configurada.
    'whatsapp_link' => whatsapp_link($message),
], 201);
