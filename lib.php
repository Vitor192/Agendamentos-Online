<?php
declare(strict_types=1);

const WEEKDAYS_LONG  = [1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado', 7 => 'domingo'];
const WEEKDAYS_SHORT = [1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb', 7 => 'Dom'];

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
    $pdo->setAttribute(PDO::ATTR_TIMEOUT, 5);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            professional_id INTEGER NOT NULL,
            date            TEXT NOT NULL,          -- YYYY-MM-DD
            start_min       INTEGER NOT NULL,       -- minutos desde 00:00
            end_min         INTEGER NOT NULL,
            name            TEXT NOT NULL,
            phone           TEXT NOT NULL,          -- só dígitos, com 55
            email           TEXT NOT NULL,
            services        TEXT NOT NULL,          -- JSON com os nomes dos serviços
            total_cents     INTEGER NOT NULL,
            status          TEXT NOT NULL DEFAULT 'confirmed',
            created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_agenda ON bookings(professional_id, date, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_phone ON bookings(phone, status)');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (ip TEXT NOT NULL, at INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts ON login_attempts(ip, at)');
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

/* ---------- Datas e horas ---------- */

function parse_date(string $date): ?DateTimeImmutable
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return ($d && $d->format('Y-m-d') === $date) ? $d : null;
}

function hm_to_min(string $hm): int
{
    [$h, $m] = array_map('intval', explode(':', $hm));
    return $h * 60 + $m;
}

function min_to_hm(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function now_min(): int
{
    return (int)date('G') * 60 + (int)date('i');
}

/** Dia de trabalho, não passado e dentro da janela permitida. */
function is_bookable_date(DateTimeImmutable $d): bool
{
    $cfg = config();
    $today = new DateTimeImmutable('today');
    $limit = $today->modify('+' . $cfg['days_ahead'] . ' days');
    return in_array((int)$d->format('N'), $cfg['work_days'], true) && $d >= $today && $d <= $limit;
}

/** Dias que o cliente pode escolher, já com os rótulos em português. */
function bookable_dates(): array
{
    $cfg = config();
    $today = new DateTimeImmutable('today');
    $minService = min(array_column($cfg['services'], 'minutes'));
    $out = [];
    for ($i = 0; $i <= $cfg['days_ahead']; $i++) {
        $d = $today->modify("+$i days");
        $dow = (int)$d->format('N');
        if (!in_array($dow, $cfg['work_days'], true)) {
            continue;
        }
        // Hoje só entra se ainda couber algum atendimento.
        if ($i === 0 && now_min() + $minService > hm_to_min($cfg['close'])) {
            continue;
        }
        $dm = $d->format('d/m');
        $out[] = [
            'date'  => $d->format('Y-m-d'),
            'short' => $i === 0 ? 'Hoje' : WEEKDAYS_SHORT[$dow],
            'dm'    => $dm,
            'long'  => WEEKDAYS_LONG[$dow] . ', ' . $dm,
        ];
    }
    return $out;
}

/* ---------- Profissionais e serviços ---------- */

function find_professional(int $id): ?array
{
    foreach (config()['professionals'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** Devolve os serviços na ordem pedida; array vazio se faltar algum ou vier vazio. */
function find_services(array $ids): array
{
    $byId = [];
    foreach (config()['services'] as $s) {
        $byId[$s['id']] = $s;
    }
    $out = [];
    foreach ($ids as $id) {
        if (!isset($byId[$id])) {
            return [];
        }
        $out[] = $byId[$id];
    }
    return $out;
}

function parse_service_ids(mixed $raw): array
{
    if (is_string($raw)) {
        $raw = explode(',', $raw);
    }
    return array_values(array_unique(array_map('intval', (array)$raw)));
}

/* ---------- Disponibilidade ---------- */

/**
 * Horários de início livres para um profissional em uma data, considerando a
 * duração total do atendimento. Um horário só é livre se o atendimento inteiro
 * cabe no expediente, fora do almoço e sem encostar em outro agendamento.
 */
function available_starts(string $date, int $professionalId, int $duration): array
{
    $cfg = config();
    $open = hm_to_min($cfg['open']);
    $close = hm_to_min($cfg['close']);
    $step = max(5, (int)$cfg['slot_step']);
    $ls = $le = null;
    if ($cfg['lunch']) {
        $ls = hm_to_min($cfg['lunch'][0]);
        $le = hm_to_min($cfg['lunch'][1]);
    }

    $st = db()->prepare("SELECT start_min, end_min FROM bookings WHERE professional_id = ? AND date = ? AND status = 'confirmed'");
    $st->execute([$professionalId, $date]);
    $busy = array_map(fn($r) => [(int)$r[0], (int)$r[1]], $st->fetchAll(PDO::FETCH_NUM));

    $isToday = $date === date('Y-m-d');
    $now = now_min();

    $out = [];
    for ($t = $open; $t + $duration <= $close; $t += $step) {
        $end = $t + $duration;
        if ($ls !== null && $t < $le && $end > $ls) {
            continue;
        }
        if ($isToday && $t <= $now) {
            continue;
        }
        foreach ($busy as [$bs, $be]) {
            if ($t < $be && $end > $bs) {
                continue 2;
            }
        }
        $out[] = min_to_hm($t);
    }
    return $out;
}

/* ---------- Telefone ---------- */

/** Aceita DDD + número (10 ou 11 dígitos) ou já com 55. Devolve só dígitos com 55, ou null. */
function normalize_phone(string $raw): ?string
{
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10 || strlen($d) === 11) {
        return '55' . $d;
    }
    if ((strlen($d) === 12 || strlen($d) === 13) && str_starts_with($d, '55')) {
        return $d;
    }
    return null;
}

function format_phone_br(string $phone): string
{
    $d = str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    }
    return $phone;
}

/* ---------- WhatsApp ---------- */

/** Número do WhatsApp que recebe os avisos do profissional (cai no padrão da barbearia). */
function barber_number(?array $professional): string
{
    $n = (string)($professional['whatsapp'] ?? '');
    $n = preg_replace('/\D+/', '', $n);
    return $n !== '' ? $n : preg_replace('/\D+/', '', config()['barber_whatsapp']);
}

function brl(float $v): string
{
    return 'R$ ' . number_format($v, 2, ',', '.');
}

function confirmation_message(array $b): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $b['date']);
    return "✂️ *Novo agendamento - " . config()['shop_name'] . "*\n\n"
        . "👤 Cliente: {$b['name']}\n"
        . "💈 Profissional: {$b['professional']}\n"
        . "🧾 Serviços: " . implode(' + ', $b['services']) . "\n"
        . "📅 Data: " . $d->format('d/m/Y') . ' (' . WEEKDAYS_LONG[(int)$d->format('N')] . ")\n"
        . "🕒 Horário: {$b['time']} ({$b['minutes']} min)\n"
        . "💰 Total: " . brl($b['total']) . "\n"
        . "📞 Telefone: " . format_phone_br($b['phone'])
        . (($b['email'] ?? '') !== '' ? "\n✉️ E-mail: {$b['email']}" : '');
}

function whatsapp_link(string $number, string $message): string
{
    return 'https://wa.me/' . $number . '?text=' . rawurlencode($message);
}

/** Envia via WhatsApp Cloud API. Retorna true se aceito pela API. */
function send_whatsapp(string $number, string $message): bool
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
            'to'                => $number,
            'type'              => 'text',
            'text'              => ['body' => $message],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/* ---------- Comparação de nomes ---------- */

/** Minúsculas e sem acentos, para comparar nomes digitados de jeitos diferentes. */
function fold_text(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ]);
}

function first_name_fold(string $s): string
{
    $parts = preg_split('/\s+/', fold_text($s));
    return $parts[0] ?? '';
}

/* ---------- Área do dono: sessão e proteção ---------- */

const ADMIN_IDLE_SECONDS = 8 * 3600;   // desloga depois de 8h sem uso
const LOGIN_MAX_FAILS    = 5;          // erros de senha permitidos ...
const LOGIN_WINDOW       = 600;        // ... dentro destes segundos, por IP

function admin_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('barbearia_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function admin_configured(): bool
{
    return (string)config()['admin_password_hash'] !== '';
}

function is_admin(): bool
{
    admin_session_start();
    if (($_SESSION['admin'] ?? false) !== true) {
        return false;
    }
    if (time() - (int)($_SESSION['seen'] ?? 0) > ADMIN_IDLE_SECONDS) {
        admin_logout();
        return false;
    }
    $_SESSION['seen'] = time();
    return true;
}

function require_admin(): void
{
    if (!is_admin()) {
        json_response(['error' => 'Acesso restrito ao dono.'], 401);
    }
}

function admin_logout(): void
{
    admin_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'],
            'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

/** Só confia em REMOTE_ADDR: cabeçalhos como X-Forwarded-For podem ser forjados. */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'desconhecido');
}

function login_blocked(): bool
{
    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > ?');
    $st->execute([client_ip(), time() - LOGIN_WINDOW]);
    return (int)$st->fetchColumn() >= LOGIN_MAX_FAILS;
}

function login_failed(): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, ?)')->execute([client_ip(), time()]);
    $pdo->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 86400]);
}

function login_clear(): void
{
    db()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([client_ip()]);
}
