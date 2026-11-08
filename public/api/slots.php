<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// GET /api/slots.php?date=YYYY-MM-DD
// Devolve todos os horários do dia e se cada um está disponível.
$date = parse_date((string)($_GET['date'] ?? ''));
if (!$date) {
    json_response(['error' => 'Data inválida.'], 400);
}
if (!is_bookable_date($date)) {
    json_response(['error' => 'Data indisponível para agendamento.'], 422);
}

$ymd    = $date->format('Y-m-d');
$booked = booked_times($ymd);
$now    = new DateTimeImmutable();
$isToday = $ymd === $now->format('Y-m-d');

$slots = [];
foreach (day_slots() as $time) {
    $past = $isToday && $time <= $now->format('H:i');
    $slots[] = [
        'time'      => $time,
        'available' => !$past && !in_array($time, $booked, true),
    ];
}

json_response(['date' => $ymd, 'slots' => $slots]);
