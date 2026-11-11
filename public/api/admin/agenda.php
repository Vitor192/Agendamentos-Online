<?php
declare(strict_types=1);
require __DIR__ . '/../../../lib.php';

// GET /api/admin/agenda.php?date=YYYY-MM-DD   (sem date = hoje)
// Só o dono logado vê a agenda, com telefone e e-mail dos clientes.
// Cancelados aparecem na lista (com o link de aviso), mas não entram nos totais.
require_admin();

$date = parse_date((string)($_GET['date'] ?? date('Y-m-d')));
if (!$date) {
    json_response(['error' => 'Data inválida.'], 422);
}
$ymd = $date->format('Y-m-d');

$st = db()->prepare('SELECT * FROM bookings WHERE date = ? ORDER BY start_min, professional_id');
$st->execute([$ymd]);

$out = [];
$revenue = 0.0;
$count = 0;
$cancelled = 0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $pro = find_professional((int)$r['professional_id']);
    $total = $r['total_cents'] / 100;
    $isCancelled = $r['status'] === 'cancelled';
    if ($isCancelled) {
        $cancelled++;
    } else {
        $count++;
        $revenue += $total;
    }
    $out[] = [
        'id'              => (int)$r['id'],
        'status'          => $isCancelled ? 'cancelled' : 'confirmed',
        'start_min'       => (int)$r['start_min'],
        'end_min'         => (int)$r['end_min'],
        'time'            => min_to_hm((int)$r['start_min']),
        'end_time'        => min_to_hm((int)$r['end_min']),
        'minutes'         => (int)$r['end_min'] - (int)$r['start_min'],
        'name'            => $r['name'],
        'phone'           => $r['phone'],
        'email'           => $r['email'],
        'professional_id' => (int)$r['professional_id'],
        'professional'    => $pro['name'] ?? '-',
        'services'        => json_decode((string)$r['services'], true) ?: [],
        'total'           => $total,
        'cancel_reason'   => $isCancelled ? cancel_reason_label($r['cancel_reason'] ?? null) : null,
        'notify_link'     => $isCancelled ? client_notify_link($r) : null,
    ];
}

$reasons = [];
foreach (config()['cancel_reasons'] as $key => $r) {
    $reasons[] = ['key' => $key, 'label' => $r['label']];
}

$dow = (int)$date->format('N');
json_response([
    'date'           => $ymd,
    'today'          => date('Y-m-d'),
    'long'           => WEEKDAYS_LONG[$dow] . ', ' . $date->format('d/m/Y'),
    'now_min'        => $ymd === date('Y-m-d') ? now_min() : null,
    'professionals'  => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], config()['professionals']),
    'cancel_reasons' => $reasons,
    'bookings'       => $out,
    'totals'         => ['count' => $count, 'revenue' => $revenue, 'cancelled' => $cancelled],
]);
