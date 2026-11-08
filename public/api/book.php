<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// POST /api/book.php
// JSON: name, phone, email, professional_id, service_ids[], date, time
// Duração e preço são sempre calculados aqui, nunca confiados ao navegador.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Método não permitido.'], 405);
}
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_response(['error' => 'Requisição inválida.'], 400);
}

$dateStr  = trim((string)($in['date'] ?? ''));
$time     = trim((string)($in['time'] ?? ''));
$name     = trim((string)($in['name'] ?? ''));
$phone    = normalize_phone((string)($in['phone'] ?? ''));
$email    = trim((string)($in['email'] ?? ''));
$prof     = find_professional((int)($in['professional_id'] ?? 0));
$services = find_services(parse_service_ids($in['service_ids'] ?? []));

$errors = [];
$date = parse_date($dateStr);
if (!$date || !is_bookable_date($date)) {
    $errors['date'] = 'Escolha um dia de atendimento dentro do período permitido.';
}
if (!$prof) {
    $errors['professional_id'] = 'Profissional inválido.';
}
if (!$services) {
    $errors['service_ids'] = 'Escolha ao menos um serviço.';
}
if (mb_strlen($name) < 3 || mb_strlen($name) > 80) {
    $errors['name'] = 'Informe seu nome completo.';
}
if ($phone === null) {
    $errors['phone'] = 'Informe um telefone válido com DDD.';
}
// E-mail é opcional; se vier preenchido, precisa ser válido.
if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120)) {
    $errors['email'] = 'Informe um e-mail válido.';
}
if ($errors) {
    json_response(['error' => 'Verifique os dados informados.', 'fields' => $errors], 422);
}

$minutes = (int)array_sum(array_column($services, 'minutes'));
$total   = (float)array_sum(array_column($services, 'price'));

// O horário precisa estar entre os livres (expediente, almoço, sem sobreposição, não passado).
if (!in_array($time, available_starts($dateStr, $prof['id'], $minutes), true)) {
    json_response(['error' => 'Esse horário não está mais disponível. Escolha outro.'], 409);
}

$start = hm_to_min($time);
$end   = $start + $minutes;
$names = array_column($services, 'name');

$pdo = db();
try {
    // BEGIN IMMEDIATE trava a escrita: dois pedidos simultâneos para o mesmo horário
    // passam um de cada vez, e o segundo enxerga o agendamento do primeiro.
    $pdo->exec('BEGIN IMMEDIATE');
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM bookings
         WHERE professional_id = ? AND date = ? AND status = 'confirmed'
           AND start_min < ? AND end_min > ?"
    );
    $st->execute([$prof['id'], $dateStr, $end, $start]);
    if ((int)$st->fetchColumn() > 0) {
        $pdo->exec('ROLLBACK');
        json_response(['error' => 'Esse horário acabou de ser reservado por outro cliente. Escolha outro.'], 409);
    }
    $pdo->prepare(
        'INSERT INTO bookings (professional_id, date, start_min, end_min, name, phone, email, services, total_cents)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $prof['id'], $dateStr, $start, $end, $name, $phone, $email,
        json_encode($names, JSON_UNESCAPED_UNICODE), (int)round($total * 100),
    ]);
    $id = (int)$pdo->lastInsertId();
    $pdo->exec('COMMIT');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->exec('ROLLBACK');
    }
    error_log($e->getMessage());
    json_response(['error' => 'Erro ao salvar o agendamento.'], 500);
}

$booking = [
    'id' => $id, 'date' => $dateStr, 'time' => $time, 'minutes' => $minutes,
    'name' => $name, 'phone' => $phone, 'email' => $email,
    'professional' => $prof['name'], 'services' => $names, 'total' => $total,
];
$message = confirmation_message($booking);
$number  = barber_number($prof);

json_response([
    'ok'            => true,
    'booking'       => $booking,
    'whatsapp_sent' => send_whatsapp($number, $message),
    // Alternativa quando a API do WhatsApp não está configurada.
    'whatsapp_link' => whatsapp_link($number, $message),
], 201);
