<?php
declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        date_default_timezone_set($cfg['timezone']);
    }
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $path = config()['db_path'];
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS appointments (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            date        TEXT NOT NULL,
            time        TEXT NOT NULL,
            name        TEXT NOT NULL,
            phone       TEXT NOT NULL,
            email       TEXT NOT NULL,
            created_at  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ');
    // Garante que dois clientes nunca fiquem com o mesmo dia + horário,
    // mesmo que enviem o pedido exatamente ao mesmo tempo.
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_date_time ON appointments(date, time)');
    return $pdo;
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function parse_date(string $date): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $d : null;
}

/** Dia útil (seg-sex), não passado e dentro da janela permitida. */
function is_bookable_date(DateTimeImmutable $d): bool
{
    $today = new DateTimeImmutable('today');
    $limit = $today->modify('+' . config()['days_ahead'] . ' days');
    $dow = (int)$d->format('N'); // 1 = segunda ... 7 = domingo
    return $dow <= 5 && $d >= $today && $d <= $limit;
}

/** Todos os horários do dia segundo a configuração (ex.: ["08:00","09:00",...]). */
function day_slots(): array
{
    $cfg = config();
    $step = $cfg['slot_minutes'] * 60;
    $start = strtotime('1970-01-01 ' . $cfg['open'] . ' UTC');
    $end = strtotime('1970-01-01 ' . $cfg['close'] . ' UTC');
    $lunch = $cfg['lunch'];
    $ls = $lunch ? strtotime('1970-01-01 ' . $lunch[0] . ' UTC') : null;
    $le = $lunch ? strtotime('1970-01-01 ' . $lunch[1] . ' UTC') : null;

    $slots = [];
    for ($t = $start; $t + $step <= $end; $t += $step) {
        if ($lunch && $t < $le && $t + $step > $ls) {
            continue;
        }
        $slots[] = gmdate('H:i', $t);
    }
    return $slots;
}

function booked_times(string $date): array
{
    $st = db()->prepare('SELECT time FROM appointments WHERE date = ?');
    $st->execute([$date]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/** Monta o texto enviado ao barbeiro. */
function confirmation_message(array $a): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $a['date']);
    return "✂️ *Novo agendamento - " . config()['shop_name'] . "*\n\n"
        . "👤 Cliente: {$a['name']}\n"
        . "📅 Data: " . $d->format('d/m/Y') . "\n"
        . "🕒 Horário: {$a['time']}\n"
        . "📞 Telefone: {$a['phone']}\n"
        . "✉️ E-mail: {$a['email']}";
}

function whatsapp_link(string $message): string
{
    return 'https://wa.me/' . config()['barber_whatsapp'] . '?text=' . rawurlencode($message);
}

/** Envia via WhatsApp Cloud API. Retorna true se aceito pela API. */
function send_whatsapp(string $message): bool
{
    $wa = config()['whatsapp_cloud'];
    if ($wa['token'] === '' || $wa['phone_number_id'] === '') {
        return false;
    }
    $ch = curl_init("https://graph.facebook.com/v20.0/{$wa['phone_number_id']}/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $wa['token'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => config()['barber_whatsapp'],
            'type'              => 'text',
            'text'              => ['body' => $message],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}
