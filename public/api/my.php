<?php
declare(strict_types=1);
require __DIR__ . '/../../lib.php';

// GET /api/my.php?phone=...&name=...
// Devolve os próximos agendamentos de quem informa o mesmo WhatsApp E o mesmo
// primeiro nome usados na reserva. Nunca devolve e-mail nem telefone.
$phone = normalize_phone((string)($_GET['phone'] ?? ''));
$name  = trim((string)($_GET['name'] ?? ''));
if ($phone === null || mb_strlen($name) < 3) {
    json_response(['error' => 'Informe seu nome e WhatsApp.'], 422);
}

$today = date('Y-m-d');
$st = db()->prepare(
    "SELECT * FROM bookings
     WHERE phone = ? AND status = 'confirmed' AND (date > ? OR (date = ? AND end_min > ?))
     ORDER BY date, start_min LIMIT 30"
);
$st->execute([$phone, $today, $today, now_min()]);

$wanted = first_name_fold($name);
$out = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    if (first_name_fold((string)$r['name']) !== $wanted) {
        continue;
    }
    $d = parse_date($r['date']);
    $out[] = [
        'id'           => (int)$r['id'],
        'date'         => $r['date'],
        'weekday'      => WEEKDAYS_LONG[(int)$d->format('N')],
        'dm'           => $d->format('d/m'),
        'time'         => min_to_hm((int)$r['start_min']),
        'minutes'      => (int)$r['end_min'] - (int)$r['start_min'],
        'professional' => find_professional((int)$r['professional_id'])['name'] ?? '-',
        'services'     => json_decode((string)$r['services'], true) ?: [],
        'total'        => $r['total_cents'] / 100,
    ];
}
json_response(['bookings' => $out]);
