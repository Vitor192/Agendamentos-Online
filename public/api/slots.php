<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// GET /api/slots.php?date=YYYY-MM-DD&professional=1&services=1,3
// Devolve só os horários de início livres para esse profissional e esses serviços.
$date = parse_date((string)($_GET['date'] ?? ''));
if (!$date || !is_bookable_date($date)) {
    json_response(['error' => 'Data indisponível para agendamento.'], 422);
}
$prof = find_professional((int)($_GET['professional'] ?? 0));
if (!$prof) {
    json_response(['error' => 'Profissional inválido.'], 422);
}
$services = find_services(parse_service_ids($_GET['services'] ?? ''));
if (!$services) {
    json_response(['error' => 'Escolha ao menos um serviço válido.'], 422);
}

$duration = array_sum(array_column($services, 'minutes'));
json_response(['times' => available_starts($date->format('Y-m-d'), $prof['id'], $duration)]);
