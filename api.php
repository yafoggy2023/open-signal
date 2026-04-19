<?php
/**
 * API бэкенд — Открытый сигнал
 * Независимая платформа для сообщений
 */

// ── НАСТРОЙКИ ────────────────────────────────────────
// Креды БД и путь к mysqldump — в config.local.php (не коммитится в git).
// Шаблон: config.local.example.php
$__config = __DIR__ . '/config.local.php';
if (!file_exists($__config)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'config.local.php не найден. Скопируйте config.local.example.php в config.local.php и впишите реальные значения.'
    ]);
    exit;
}
require_once $__config;

define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('MAX_FILE_SIZE', 50 * 1024 * 1024); // 50 МБ
define('SESSION_TIMEOUT', 30 * 60); // 30 минут

// ── ЗАГОЛОВКИ ─────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // Локально
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/lib_security.php';

// ── TELEGRAM NOTIFY ───────────────────────────────────
define('BOT_TOKEN_FILE', __DIR__ . '/bot_token.txt');
function notify_bot_operators($pdo, $appealId, $subject, $category, $priority) {
    $tokenFile = BOT_TOKEN_FILE;
    if (!file_exists($tokenFile)) return;
    $token = trim(file_get_contents($tokenFile));
    if (!$token || $token === 'ВСТАВЬ_ТОКЕН_СЮДА') return;

    $pri = ['low'=>'🟢','medium'=>'🟡','high'=>'🟠','critical'=>'🔴'];
    $p = $pri[$priority] ?? '';
    $text = "🔔 <b>Новое сообщение (сайт)</b>\n\n📋 <b>$appealId</b>\n📌 " . htmlspecialchars($subject) . "\n📂 " . htmlspecialchars($category) . "\n⚡ $p";

    $ops = $pdo->query("SELECT chat_id FROM bot_users WHERE role = 'operator' AND notify = 1")->fetchAll();
    foreach ($ops as $op) {
        $kb = json_encode(['inline_keyboard' => [[['text' => '📋 Открыть', 'callback_data' => 'view_' . $appealId]]]]);
        @file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query([
            'chat_id' => $op['chat_id'], 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $kb
        ]));
    }
}

// ── АВТОСВЯЗКА ПОВТОРНЫХ ОБРАЩЕНИЙ ───────────────────
function auto_link_appeals($pdo, $appealId, $contactJson, $senderChatId) {
    $found = [];
    // По sender_chat_id (Telegram)
    if ($senderChatId) {
        $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE sender_chat_id = ? AND appeal_id != ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$senderChatId, $appealId]);
        foreach ($stmt->fetchAll() as $r) $found[$r['appeal_id']] = true;
    }
    // По email/телефону/telegram из contact_json
    if ($contactJson) {
        $c = is_string($contactJson) ? json_decode($contactJson, true) : $contactJson;
        if (!empty($c['email'])) {
            $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE contact_json LIKE ? AND appeal_id != ? LIMIT 10");
            $stmt->execute(['%"email":"' . addcslashes($c['email'], '%_') . '"%', $appealId]);
            foreach ($stmt->fetchAll() as $r) $found[$r['appeal_id']] = true;
        }
        if (!empty($c['phone'])) {
            $phone = preg_replace('/[^0-9]/', '', $c['phone']);
            if (strlen($phone) >= 7) {
                $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE REPLACE(REPLACE(REPLACE(REPLACE(contact_json,' ',''),'-',''),'(',''),')','') LIKE ? AND appeal_id != ? LIMIT 10");
                $stmt->execute(['%' . $phone . '%', $appealId]);
                foreach ($stmt->fetchAll() as $r) $found[$r['appeal_id']] = true;
            }
        }
        if (!empty($c['telegram'])) {
            $tg = ltrim($c['telegram'], '@');
            $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE contact_json LIKE ? AND appeal_id != ? LIMIT 10");
            $stmt->execute(['%' . $tg . '%', $appealId]);
            foreach ($stmt->fetchAll() as $r) $found[$r['appeal_id']] = true;
        }
    }
    // Создаём связи
    foreach (array_keys($found) as $linkedId) {
        $check = $pdo->prepare("SELECT id FROM linked_appeals WHERE (appeal_id_a = ? AND appeal_id_b = ?) OR (appeal_id_a = ? AND appeal_id_b = ?)");
        $check->execute([$appealId, $linkedId, $linkedId, $appealId]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO linked_appeals (appeal_id_a, appeal_id_b) VALUES (?, ?)")->execute([$appealId, $linkedId]);
        }
    }
    return count($found);
}

// ── СЕССИЯ ────────────────────────────────────────────
session_start();

// Таймаут сессии (пропускаем если включено «Запомнить меня»)
if (isset($_SESSION['last_activity']) && empty($_SESSION['remember']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    session_destroy();
    session_start();
}
if (isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

// ── БД ───────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log("DB connection failed: " . $e->getMessage());
    json_error('Ошибка подключения к базе данных', 500);
}

// ── HELPERS ───────────────────────────────────────────
function json_ok($data = []) {
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function require_auth() {
    if (empty($_SESSION['user_id'])) {
        json_error('Необходима авторизация', 401);
    }
}

function require_super() {
    require_auth();
    if ($_SESSION['user_role'] !== 'super') {
        json_error('Недостаточно прав', 403);
    }
}

function get_body() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function log_action($pdo, $action, $appeal_id, $detail) {
    if (empty($_SESSION['user_id'])) return;
    $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, appeal_id, detail, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$_SESSION['user_id'], $action, $appeal_id, $detail]);
}

function sanitize($str) {
    return htmlspecialchars(strip_tags(trim($str)), ENT_QUOTES, 'UTF-8');
}

function check_login_attempts($pdo, $login) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM login_attempts WHERE login = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
    $stmt->execute([$login]);
    $row = $stmt->fetch();
    return (int)$row['cnt'];
}

function record_login_attempt($pdo, $login, $success) {
    $stmt = $pdo->prepare("INSERT INTO login_attempts (login, success, attempt_time, ip) VALUES (?, ?, NOW(), ?)");
    $stmt->execute([$login, $success ? 1 : 0, $_SERVER['REMOTE_ADDR'] ?? '']);
}

// ── РОУТЕР ────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? (get_body()['action'] ?? '');

switch ($action) {

    // ════════════════════════════════════════════════
    // AUTH
    // ════════════════════════════════════════════════
    case 'login':
        $body = get_body();
        $login = sanitize($body['login'] ?? '');
        $pass  = $body['pass'] ?? '';

        if (!$login || !$pass) json_error('Введите логин и пароль');

        // Проверка блокировки
        if (check_login_attempts($pdo, $login) >= 5) {
            json_error('Аккаунт заблокирован на 10 минут из-за неудачных попыток входа');
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE login = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            record_login_attempt($pdo, $login, false);
            json_error('Неверный логин или пароль');
        }

        record_login_attempt($pdo, $login, true);

        // Обновить last_active
        $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = ?")->execute([$user['id']]);

        $_SESSION['user_id']   = $user['id'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_login']= $user['login'];
        $_SESSION['last_activity'] = time();

        if (!empty($body['remember'])) {
            $lifetime = 30 * 24 * 3600;
            ini_set('session.gc_maxlifetime', $lifetime);
            $p = session_get_cookie_params();
            setcookie(session_name(), session_id(), [
                'expires'  => time() + $lifetime,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            $_SESSION['remember'] = true;
        }

        json_ok([
            'id'        => $user['id'],
            'login'     => $user['login'],
            'role'      => $user['role'],
            'last'      => $user['last_name'],
            'first'     => $user['first_name'],
            'mid'       => $user['middle_name'],
        ]);

    case 'logout':
        session_destroy();
        json_ok();

    case 'check_session':
        if (!empty($_SESSION['user_id'])) {
            $stmt = $pdo->prepare("SELECT id, login, role, last_name, first_name, middle_name FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            json_ok($user ?: null);
        }
        json_ok(null);

    // ════════════════════════════════════════════════
    // ОБРАЩЕНИЯ — публичная подача
    // ════════════════════════════════════════════════
    case 'submit_appeal':
        $is_anon   = !empty($_POST['anon']) && $_POST['anon'] === '1';
        $subject   = sanitize($_POST['subject'] ?? '');
        $category  = sanitize($_POST['category'] ?? '');
        $priority  = sanitize($_POST['priority'] ?? 'low');
        $message   = sanitize($_POST['message'] ?? '');
        $organ     = sanitize($_POST['organ'] ?? '');
        $location  = sanitize($_POST['location'] ?? '');
        $eventDate = sanitize($_POST['event_date'] ?? '');

        if (!$subject || !$category || !$message) json_error('Заполните обязательные поля');
        if (strlen($subject) < 5) json_error('Тема слишком короткая');
        if (strlen($message) < 20) json_error('Опишите ситуацию подробнее');

        // Защита от ботов: проверка времени заполнения формы
        $fill_time = (int)($_POST['fill_time'] ?? 0);
        if ($fill_time > 0 && $fill_time < 5) {
            json_error('Форма заполнена слишком быстро. Попробуйте ещё раз.');
        }

        // Rate limit по IP
        $rl_ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $rl_ip = trim(explode(',', $rl_ip)[0]);
        if (!check_submit_rate_limit($pdo, $rl_ip)) {
            json_error('Превышен лимит сообщений с вашего IP. Попробуйте позже.');
        }
        record_submit_attempt($pdo, $rl_ip);

        // Анализ на спам
        $spam = spam_analyze($subject, $message);
        if ($spam['score'] >= 80) {
            json_error('Сообщение распознано как спам. Если это ошибка — переформулируйте текст.');
        }

        // Контакт (если не анонимно)
        $contact_json = null;
        if (!$is_anon) {
            $contact = [
                'last'     => sanitize($_POST['last'] ?? ''),
                'first'    => sanitize($_POST['first'] ?? ''),
                'mid'      => sanitize($_POST['mid'] ?? ''),
                'email'    => sanitize($_POST['email'] ?? ''),
                'phone'    => sanitize($_POST['phone'] ?? ''),
                'telegram' => sanitize($_POST['telegram'] ?? ''),
                'addr'     => sanitize($_POST['addr'] ?? ''),
            ];
            $contact_json = json_encode($contact, JSON_UNESCAPED_UNICODE);
        }

        // IP и устройство
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = trim(explode(',', $ip)[0]); // Первый IP если через прокси
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        // Получить геоданные
        $geo_country = ''; $geo_region = ''; $geo_city = ''; $geo_isp = '';
        $geo_proxy = 0; $geo_hosting = 0;
        if ($ip && !in_array($ip, ['127.0.0.1', '::1'])) {
            $geo_url = "http://ip-api.com/json/{$ip}?lang=ru&fields=status,country,regionName,city,isp,proxy,hosting";
            $geo_ctx = stream_context_create(['http' => ['timeout' => 3]]);
            $geo_res = @file_get_contents($geo_url, false, $geo_ctx);
            if ($geo_res) {
                $geo = json_decode($geo_res, true);
                if (($geo['status'] ?? '') === 'success') {
                    $geo_country = $geo['country'] ?? '';
                    $geo_region  = $geo['regionName'] ?? '';
                    $geo_city    = $geo['city'] ?? '';
                    $geo_isp     = $geo['isp'] ?? '';
                    $geo_proxy   = ($geo['proxy'] ?? false) ? 1 : 0;
                    $geo_hosting = ($geo['hosting'] ?? false) ? 1 : 0;
                }
            }
        }

        // Парсинг user agent
        $device_info = '';
        if ($user_agent) {
            if (preg_match('/iPhone|iPad|iPod/i', $user_agent)) $device_info = 'iOS';
            elseif (preg_match('/Android/i', $user_agent)) $device_info = 'Android';
            elseif (preg_match('/Windows/i', $user_agent)) $device_info = 'Windows';
            elseif (preg_match('/Mac OS/i', $user_agent)) $device_info = 'macOS';
            elseif (preg_match('/Linux/i', $user_agent)) $device_info = 'Linux';
            if (preg_match('/Chrome\/([\d.]+)/i', $user_agent, $m)) $device_info .= ' / Chrome '.$m[1];
            elseif (preg_match('/Firefox\/([\d.]+)/i', $user_agent, $m)) $device_info .= ' / Firefox '.$m[1];
            elseif (preg_match('/Safari\/([\d.]+)/i', $user_agent, $m)) $device_info .= ' / Safari';
        }

        // Генерация ID
        $year = date('Y');
        $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM appeals WHERE YEAR(created_at) = $year");
        $count = (int)$stmt->fetch()['cnt'];
        $appeal_id = 'АПО-' . $year . '-' . str_pad($count + 1, 6, '0', STR_PAD_LEFT);

        // Сохранение обращения
        $stmt = $pdo->prepare("
            INSERT INTO appeals
            (appeal_id, subject, category, priority, status, is_anon, contact_json, organ, location, event_date, message,
             ip_address, user_agent, device_info, geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
             spam_score, spam_flags, source, created_at)
            VALUES (?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'site', NOW())
        ");
        $stmt->execute([$appeal_id, $subject, $category, $priority, $is_anon ? 1 : 0, $contact_json, $organ, $location, $eventDate ?: null, $message,
                        $ip, $user_agent, $device_info, $geo_country, $geo_region, $geo_city, $geo_isp, $geo_proxy, $geo_hosting,
                        $spam['score'], json_encode($spam['flags'], JSON_UNESCAPED_UNICODE)]);
        $db_id = $pdo->lastInsertId();

        // Загрузка файлов
        $uploaded_files = [];
        if (!empty($_FILES['files'])) {
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
            $appeal_dir = UPLOAD_DIR . $appeal_id . '/';
            if (!is_dir($appeal_dir)) mkdir($appeal_dir, 0755, true);

            $files = $_FILES['files'];
            $file_count = is_array($files['name']) ? count($files['name']) : 1;

            for ($i = 0; $i < min($file_count, 10); $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $size = is_array($files['size']) ? $files['size'][$i] : $files['size'];
                $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];

                if ($err !== UPLOAD_ERR_OK) continue;
                if ($size > MAX_FILE_SIZE) continue;

                // Безопасное имя
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $allowed = ['jpg','jpeg','png','gif','webp','pdf','doc','docx','xls','xlsx','txt','zip','rar','mp3','mp4','avi','mov'];
                if (!in_array($ext, $allowed)) continue;

                $safe_name = preg_replace('/[^a-zA-ZА-Яа-яёЁ0-9._\-\s]/u', '_', $name);
                $safe_name = trim($safe_name);
                $dest = $appeal_dir . $safe_name;
                if (move_uploaded_file($tmp, $dest)) {
                    // Антивирусное сканирование
                    $av = clamav_scan($dest);
                    // Если найдена угроза — удаляем
                    if ($av['status'] === 'infected') {
                        @unlink($dest);
                        continue;
                    }
                    $uploaded_files[] = $safe_name;
                    $pdo->prepare("INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$db_id, $safe_name, $size, $av['status'], $av['detail']]);
                }
            }
        }

        // Автосвязка повторных обращений
        auto_link_appeals($pdo, $appeal_id, $contact_json, null);

        // Уведомить операторов в Telegram
        notify_bot_operators($pdo, $appeal_id, $subject, $category, $priority);

        json_ok(['appeal_id' => $appeal_id]);

    // ════════════════════════════════════════════════
    // ОБРАЩЕНИЯ — получение (для админов)
    // ════════════════════════════════════════════════
    case 'get_appeals':
        require_auth();

        $where = ['1=1'];
        $params = [];

        if (!empty($_GET['status']))   { $where[] = 'a.status = ?';    $params[] = $_GET['status']; }
        if (!empty($_GET['category'])) { $where[] = 'a.category = ?';  $params[] = $_GET['category']; }
        if (!empty($_GET['priority'])) { $where[] = 'a.priority = ?';  $params[] = $_GET['priority']; }
        if (!empty($_GET['assigned'])) {
            if ($_GET['assigned'] === 'none') { $where[] = 'a.assigned_to IS NULL'; }
            else { $where[] = 'a.assigned_to = ?'; $params[] = $_GET['assigned']; }
        }
        if (!empty($_GET['source']))   { $where[] = 'a.source = ?';    $params[] = $_GET['source']; }
        if (!empty($_GET['date_from'])) { $where[] = 'DATE(a.created_at) >= ?'; $params[] = $_GET['date_from']; }
        if (!empty($_GET['date_to']))   { $where[] = 'DATE(a.created_at) <= ?'; $params[] = $_GET['date_to']; }
        if (!empty($_GET['q'])) {
            $q = '%' . $_GET['q'] . '%';
            $where[] = '(a.appeal_id LIKE ? OR a.subject LIKE ? OR a.message LIKE ?)';
            $params[] = $q; $params[] = $q; $params[] = $q;
        }

        $sql = "SELECT a.*, u.last_name as assigned_last, u.first_name as assigned_first
                FROM appeals a
                LEFT JOIN users u ON a.assigned_to = u.id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC LIMIT 500";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $appeals = $stmt->fetchAll();

        // Добавить файлы и комментарии
        foreach ($appeals as &$ap) {
            $ap['contact'] = $ap['contact_json'] ? json_decode($ap['contact_json'], true) : null;
            unset($ap['contact_json']);
            $ap['is_anon'] = (bool)$ap['is_anon'];

            // Файлы
            $fs = $pdo->prepare("SELECT filename, filesize, av_status, av_detail FROM appeal_files WHERE appeal_db_id = ?");
            $fs->execute([$ap['id']]);
            $ap['files_full'] = $fs->fetchAll();
            $ap['files'] = array_column($ap['files_full'], 'filename');

            // Комментарии
            $cs = $pdo->prepare("SELECT c.*, u.last_name, u.first_name FROM comments c JOIN users u ON c.user_id = u.id WHERE c.appeal_db_id = ? ORDER BY c.created_at");
            $cs->execute([$ap['id']]);
            $ap['comments'] = $cs->fetchAll();

            // Связанные обращения
            $ls = $pdo->prepare("SELECT appeal_id_b as linked_id FROM linked_appeals WHERE appeal_id_a = ? UNION SELECT appeal_id_a FROM linked_appeals WHERE appeal_id_b = ?");
            $ls->execute([$ap['appeal_id'], $ap['appeal_id']]);
            $ap['linked_appeals'] = array_column($ls->fetchAll(), 'linked_id');
        }

        json_ok($appeals);

    case 'get_appeal':
        require_auth();
        $id = sanitize($_GET['id'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$id]);
        $ap = $stmt->fetch();
        if (!$ap) json_error('Обращение не найдено', 404);

        $ap['contact'] = $ap['contact_json'] ? json_decode($ap['contact_json'], true) : null;
        unset($ap['contact_json']);
        $ap['is_anon'] = (bool)$ap['is_anon'];
        // Для telegram-заявок: статус бана отправителя
        if ($ap['sender_chat_id']) {
            $bs = $pdo->prepare("SELECT banned, language_code FROM bot_users WHERE chat_id = ?");
            $bs->execute([$ap['sender_chat_id']]);
            $bu = $bs->fetch();
            $ap['sender_banned'] = $bu ? (int)$bu['banned'] : 0;
            $ap['sender_language'] = $bu ? ($bu['language_code'] ?? '') : '';
        }
        json_ok($ap);

    // ════════════════════════════════════════════════
    // ОБРАЩЕНИЯ — изменения
    // ════════════════════════════════════════════════
    case 'update_appeal':
        require_auth();
        $body = get_body();
        $appeal_id = sanitize($body['appeal_id'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appeal_id]);
        $ap = $stmt->fetch();
        if (!$ap) json_error('Обращение не найдено', 404);

        $fields = [];
        $params = [];

        if (isset($body['status'])) {
            $newVal = sanitize($body['status']);
            log_field_change($pdo, $ap['id'], 'status', $ap['status'], $newVal);
            $fields[] = 'status = ?'; $params[] = $newVal;
            if (in_array($body['status'], ['done','rejected'])) {
                $fields[] = 'closed_by = ?'; $params[] = $_SESSION['user_id'];
                $fields[] = 'closed_at = NOW()';
            }
            log_action($pdo, 'status_change', $appeal_id, 'Статус → ' . $body['status']);
        }
        if (isset($body['note'])) {
            $newVal = sanitize($body['note']);
            log_field_change($pdo, $ap['id'], 'note', $ap['note'], $newVal);
            $fields[] = 'note = ?'; $params[] = $newVal;
            log_action($pdo, 'note', $appeal_id, 'Примечание обновлено');
        }
        if (isset($body['assigned_to'])) {
            $newVal = $body['assigned_to'] ?: null;
            log_field_change($pdo, $ap['id'], 'assigned_to', $ap['assigned_to'], $newVal);
            $fields[] = 'assigned_to = ?'; $params[] = $newVal;
            log_action($pdo, 'assign', $appeal_id, 'Назначен ответственный');
        }
        if (isset($body['subject'])) {
            $editable = [
                'subject'  => sanitize($body['subject']),
                'category' => sanitize($body['category'] ?? $ap['category']),
                'organ'    => sanitize($body['organ'] ?? ''),
                'location' => sanitize($body['location'] ?? ''),
                'priority' => sanitize($body['priority'] ?? $ap['priority']),
                'message'  => sanitize($body['message'] ?? $ap['message']),
                'note'     => sanitize($body['note'] ?? ''),
            ];
            foreach ($editable as $f => $v) {
                log_field_change($pdo, $ap['id'], $f, $ap[$f], $v);
                $fields[] = "$f = ?"; $params[] = $v;
            }
            log_action($pdo, 'edit', $appeal_id, 'Обращение отредактировано');
        }

        if ($fields) {
            $params[] = $appeal_id;
            $pdo->prepare("UPDATE appeals SET " . implode(', ', $fields) . " WHERE appeal_id = ?")->execute($params);
        }

        json_ok();

    case 'delete_appeal':
        require_auth();
        $body = get_body();
        $appeal_id = sanitize($body['appeal_id'] ?? '');

        $stmt = $pdo->prepare("SELECT id FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appeal_id]);
        $ap = $stmt->fetch();
        if (!$ap) json_error('Не найдено', 404);

        log_action($pdo, 'delete', $appeal_id, 'Обращение удалено');
        $pdo->prepare("DELETE FROM appeals WHERE appeal_id = ?")->execute([$appeal_id]);

        // Удалить файлы
        $dir = UPLOAD_DIR . $appeal_id . '/';
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '*'));
            rmdir($dir);
        }
        json_ok();

    // ════════════════════════════════════════════════
    // ФАЙЛЫ
    // ════════════════════════════════════════════════
    case 'get_file':
        // Временный вывод ошибок для отладки
        @ini_set('display_errors', 1);
        if (empty($_SESSION['user_id'])) json_error('Нет доступа', 403);

        $appeal_id = trim($_GET['appeal_id'] ?? '');
        $filename  = basename(trim($_GET['filename'] ?? ''));
        if (!$appeal_id || !$filename) json_error('Параметры не указаны');

        // Получить числовой ID обращения из БД
        $stmt = $pdo->prepare("SELECT id FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appeal_id]);
        $ap = $stmt->fetch();
        if (!$ap) json_error('Обращение не найдено', 404);

        $db_id = $ap['id'];

        // Ищем файл в трёх возможных местах (исторически сложилось, что разные
        // источники клали файлы в разные пути):
        //   1) uploads/<appeal_id>/<filename>  — веб-форма, демо-данные, новый bot.php
        //   2) uploads/<filename>              — старые Telegram-загрузки (плоско в корне)
        //   3) uploads/<db_id>/<filename>      — никогда не использовалось, оставлено на всякий
        $candidates = [
            UPLOAD_DIR . $appeal_id . '/' . $filename,
            UPLOAD_DIR . $filename,
            UPLOAD_DIR . $db_id . '/' . $filename,
        ];
        $path = null;
        foreach ($candidates as $c) {
            if (file_exists($c)) { $path = $c; break; }
        }
        if ($path === null) {
            json_error('Файл не найден', 404);
        }

        $mime = mime_content_type($path) ?: 'application/octet-stream';

        // Лог доступа к файлу
        $pdo->prepare("INSERT INTO file_access_log (user_id, appeal_id, filename, accessed_at) VALUES (?, ?, ?, NOW())")
            ->execute([$_SESSION['user_id'], $appeal_id, $filename]);

        // ФИО для водяного знака
        $u = $pdo->prepare("SELECT last_name, first_name FROM users WHERE id = ?");
        $u->execute([$_SESSION['user_id']]);
        $usr = $u->fetch();
        $watermark_text = ($usr['last_name'] ?? '') . ' ' . mb_substr($usr['first_name'] ?? '', 0, 1) . '. · ' . $appeal_id;

        // Очистить все буферы вывода
        while (ob_get_level()) ob_end_clean();

        // Водяной знак для изображений
        if (preg_match('~^image/(jpeg|png|gif)~i', $mime)) {
            $marked = apply_image_watermark($path, $watermark_text);
            if ($marked !== false) {
                header('Content-Type: ' . $mime);
                header('Content-Disposition: inline; filename="WM_' . $filename . '"');
                header('Content-Length: ' . strlen($marked));
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store');
                echo $marked;
                exit;
            }
        }

        // Для остальных файлов — имя со штампом ФИО (защита от анонимных утечек)
        $stamped = 'WM_' . preg_replace('/[^a-zA-Z0-9_-]/', '', translit_simple($watermark_text)) . '_' . $filename;

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $stamped . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;

    // ════════════════════════════════════════════════
    // КОММЕНТАРИИ
    // ════════════════════════════════════════════════
    case 'add_comment':
        require_auth();
        $body = get_body();
        $appeal_id = sanitize($body['appeal_id'] ?? '');
        $text = sanitize($body['text'] ?? '');
        if (!$appeal_id || !$text) json_error('Пустой комментарий');

        $stmt = $pdo->prepare("SELECT id FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appeal_id]);
        $ap = $stmt->fetch();
        if (!$ap) json_error('Обращение не найдено', 404);

        $pdo->prepare("INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES (?, ?, ?, NOW())")
            ->execute([$ap['id'], $_SESSION['user_id'], $text]);
        $comment_id = $pdo->lastInsertId();

        // Парсинг @упоминаний
        $mentions = [];
        if (preg_match_all('/@([A-Za-z0-9_]+)/', $text, $m)) {
            foreach (array_unique($m[1]) as $login) {
                $u = $pdo->prepare("SELECT id FROM users WHERE login = ? AND is_active = 1");
                $u->execute([$login]);
                $uid = $u->fetchColumn();
                if ($uid && $uid != $_SESSION['user_id']) {
                    $pdo->prepare("INSERT INTO comment_mentions (comment_id, user_id) VALUES (?, ?)")
                        ->execute([$comment_id, $uid]);
                    $mentions[] = $login;
                }
            }
        }

        log_action($pdo, 'chat', $appeal_id, 'Комментарий: "' . mb_substr($text, 0, 40) . '"');
        json_ok(['ts' => date('d.m.Y H:i'), 'mentions' => $mentions]);

    case 'get_history':
        require_auth();
        $appeal_id = sanitize($_GET['appeal_id'] ?? '');
        $stmt = $pdo->prepare("
            SELECT h.*, u.last_name, u.first_name, u.login
            FROM appeal_history h
            JOIN appeals a ON h.appeal_db_id = a.id
            LEFT JOIN users u ON h.user_id = u.id
            WHERE a.appeal_id = ?
            ORDER BY h.created_at DESC LIMIT 200
        ");
        $stmt->execute([$appeal_id]);
        json_ok($stmt->fetchAll());

    case 'get_mentions':
        require_auth();
        $stmt = $pdo->prepare("
            SELECT cm.id, cm.is_read, c.text, c.created_at, a.appeal_id, a.subject,
                   u.last_name, u.first_name
            FROM comment_mentions cm
            JOIN comments c ON cm.comment_id = c.id
            JOIN appeals a ON c.appeal_db_id = a.id
            JOIN users u ON c.user_id = u.id
            WHERE cm.user_id = ?
            ORDER BY c.created_at DESC LIMIT 50
        ");
        $stmt->execute([$_SESSION['user_id']]);
        json_ok($stmt->fetchAll());

    case 'mark_mentions_read':
        require_auth();
        $pdo->prepare("UPDATE comment_mentions SET is_read = 1 WHERE user_id = ?")
            ->execute([$_SESSION['user_id']]);
        json_ok();

    // ════════════════════════════════════════════════
    // СВЯЗАННЫЕ ОБРАЩЕНИЯ
    // ════════════════════════════════════════════════
    case 'link_appeals':
        require_auth();
        $body = get_body();
        $a = sanitize($body['id_a'] ?? '');
        $b = sanitize($body['id_b'] ?? '');
        if (!$a || !$b || $a === $b) json_error('Неверные ID');

        $check = $pdo->prepare("SELECT id FROM linked_appeals WHERE (appeal_id_a = ? AND appeal_id_b = ?) OR (appeal_id_a = ? AND appeal_id_b = ?)");
        $check->execute([$a,$b,$b,$a]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO linked_appeals (appeal_id_a, appeal_id_b) VALUES (?,?)")->execute([$a,$b]);
        }
        log_action($pdo, 'edit', $a, 'Привязано обращение ' . $b);
        json_ok();

    case 'unlink_appeals':
        require_auth();
        $body = get_body();
        $a = sanitize($body['id_a'] ?? '');
        $b = sanitize($body['id_b'] ?? '');
        $pdo->prepare("DELETE FROM linked_appeals WHERE (appeal_id_a = ? AND appeal_id_b = ?) OR (appeal_id_a = ? AND appeal_id_b = ?)")->execute([$a,$b,$b,$a]);
        log_action($pdo, 'edit', $a, 'Отвязано обращение ' . $b);
        json_ok();

    // ════════════════════════════════════════════════
    // ПОЛЬЗОВАТЕЛИ (только суперадмин)
    // ════════════════════════════════════════════════
    case 'get_users':
        require_auth();
        $stmt = $pdo->query("SELECT u.id, u.login, u.role, u.last_name, u.first_name, u.middle_name, u.created_at, u.last_active, u.is_active, b.chat_id AS tg_chat_id, b.username AS tg_username FROM users u LEFT JOIN bot_users b ON b.user_id = u.id AND b.role = 'operator' ORDER BY u.created_at");
        json_ok($stmt->fetchAll());

    case 'create_user':
        require_super();
        $body = get_body();
        $login = sanitize($body['login'] ?? '');
        $pass  = $body['pass'] ?? '';
        $role  = in_array($body['role'] ?? '', ['super','duty']) ? $body['role'] : 'duty';
        $last  = sanitize($body['last'] ?? '');
        $first = sanitize($body['first'] ?? '');
        $mid   = sanitize($body['mid'] ?? '');

        if (!$login || !$pass || !$last || !$first) json_error('Заполните обязательные поля');
        if (strlen($pass) < 6) json_error('Пароль минимум 6 символов');

        $check = $pdo->prepare("SELECT id FROM users WHERE login = ?");
        $check->execute([$login]);
        if ($check->fetch()) json_error('Логин уже занят');

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (login, password_hash, role, last_name, first_name, middle_name, created_at, is_active) VALUES (?,?,?,?,?,?,NOW(),1)")
            ->execute([$login, $hash, $role, $last, $first, $mid]);

        log_action($pdo, 'edit', '—', 'Добавлен сотрудник ' . $last . ' ' . $first);
        json_ok(['id' => $pdo->lastInsertId()]);

    case 'update_user':
        require_super();
        $body = get_body();
        $uid  = (int)($body['id'] ?? 0);
        if (!$uid) json_error('ID не указан');

        $fields = []; $params = [];
        if (!empty($body['last']))  { $fields[] = 'last_name = ?';   $params[] = sanitize($body['last']); }
        if (!empty($body['first'])) { $fields[] = 'first_name = ?';  $params[] = sanitize($body['first']); }
        if (isset($body['mid']))    { $fields[] = 'middle_name = ?'; $params[] = sanitize($body['mid']); }
        if (!empty($body['login'])) { $fields[] = 'login = ?';       $params[] = sanitize($body['login']); }
        if (!empty($body['role']))  { $fields[] = 'role = ?';        $params[] = sanitize($body['role']); }
        if (!empty($body['pass']))  {
            if (strlen($body['pass']) < 6) json_error('Пароль минимум 6 символов');
            $fields[] = 'password_hash = ?';
            $params[] = password_hash($body['pass'], PASSWORD_BCRYPT);
        }

        if ($fields) {
            $params[] = $uid;
            $pdo->prepare("UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
        }
        log_action($pdo, 'edit', '—', 'Данные сотрудника ID' . $uid . ' обновлены');
        json_ok();

    case 'delete_user':
        require_super();
        $body = get_body();
        $uid = (int)($body['id'] ?? 0);
        if ($uid === (int)$_SESSION['user_id']) json_error('Нельзя удалить себя');
        $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$uid]);
        log_action($pdo, 'delete', '—', 'Сотрудник ID' . $uid . ' деактивирован');
        json_ok();

    case 'link_telegram':
        require_super();
        $body = get_body();
        $uid = (int)($body['user_id'] ?? 0);
        $chatId = trim($body['chat_id'] ?? '');
        if (!$uid || !$chatId) json_error('Укажите ID пользователя и Telegram ID');
        if (!preg_match('/^-?\d+$/', $chatId)) json_error('Некорректный Telegram ID');

        // Проверяем, не привязан ли уже этот chat_id к другому пользователю
        $existing = $pdo->prepare("SELECT user_id FROM bot_users WHERE chat_id = ? AND user_id IS NOT NULL AND user_id != ?");
        $existing->execute([$chatId, $uid]);
        if ($existing->fetch()) json_error('Этот Telegram ID уже привязан к другому оператору');

        // Обновляем или создаём запись в bot_users
        $check = $pdo->prepare("SELECT id FROM bot_users WHERE chat_id = ?");
        $check->execute([$chatId]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE bot_users SET role = 'operator', user_id = ?, notify = 1 WHERE chat_id = ?")
                ->execute([$uid, $chatId]);
        } else {
            $pdo->prepare("INSERT INTO bot_users (chat_id, role, user_id, notify) VALUES (?, 'operator', ?, 1)")
                ->execute([$chatId, $uid]);
        }

        log_action($pdo, 'edit', '—', "Telegram ID $chatId привязан к сотруднику ID$uid");
        json_ok();

    case 'unlink_telegram':
        require_super();
        $body = get_body();
        $uid = (int)($body['user_id'] ?? 0);
        if (!$uid) json_error('ID пользователя не указан');

        $pdo->prepare("UPDATE bot_users SET role = 'sender', user_id = NULL WHERE user_id = ?")
            ->execute([$uid]);

        log_action($pdo, 'edit', '—', "Telegram отвязан от сотрудника ID$uid");
        json_ok();

    case 'get_telegram_link':
        require_auth();
        $uid = (int)($_GET['user_id'] ?? 0);
        if (!$uid) json_error('ID не указан');
        $stmt = $pdo->prepare("SELECT chat_id, username FROM bot_users WHERE user_id = ? AND role = 'operator' LIMIT 1");
        $stmt->execute([$uid]);
        $link = $stmt->fetch();
        json_ok($link ?: null);

    // ════════════════════════════════════════════════
    // ЖУРНАЛ И СТАТИСТИКА
    // ════════════════════════════════════════════════
    case 'get_activity':
        require_super();
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $stmt = $pdo->prepare("
            SELECT l.*, u.last_name, u.first_name
            FROM activity_log l
            LEFT JOIN users u ON l.user_id = u.id
            ORDER BY l.created_at DESC LIMIT ?
        ");
        $stmt->execute([$limit]);
        json_ok($stmt->fetchAll());

    case 'get_stats':
        require_auth();
        $total    = $pdo->query("SELECT COUNT(*) FROM appeals")->fetchColumn();
        $new      = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status='new'")->fetchColumn();
        $process  = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status IN ('process','review')")->fetchColumn();
        $done     = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status='done'")->fetchColumn();
        $by_cat   = $pdo->query("SELECT category, COUNT(*) as cnt FROM appeals GROUP BY category ORDER BY cnt DESC")->fetchAll();
        $from_site = $pdo->query("SELECT COUNT(*) FROM appeals WHERE source='site' OR source IS NULL")->fetchColumn();
        $from_tg   = $pdo->query("SELECT COUNT(*) FROM appeals WHERE source='telegram'")->fetchColumn();
        json_ok(compact('total','new','process','done','by_cat','from_site','from_tg'));

    case 'get_login_log':
        require_super();
        $stmt = $pdo->query("SELECT * FROM login_attempts ORDER BY attempt_time DESC LIMIT 200");
        json_ok($stmt->fetchAll());

    case 'get_geo':
    // Получить геоданные по IP через ip-api.com
    $ip = sanitize($_GET['ip'] ?? '');
    if (!$ip) json_error('IP не указан');

    // Проверить кэш в БД
    $cached = $pdo->prepare("SELECT * FROM ip_cache WHERE ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $cached->execute([$ip]);
    $row = $cached->fetch();
    if ($row) {
        json_ok(json_decode($row['data'], true));
    }

    // Запрос к ip-api.com (бесплатно, 45 запросов/мин)
    $url = "http://ip-api.com/json/{$ip}?lang=ru&fields=status,country,regionName,city,isp,org,proxy,hosting,query";
    $ctx = stream_context_create(['http' => ['timeout' => 5]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) json_error('Геосервис недоступен');

    $geo = json_decode($res, true);

    // Сохранить в кэш
    $pdo->prepare("INSERT INTO ip_cache (ip, data, created_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE data=VALUES(data), created_at=NOW()")
        ->execute([$ip, json_encode($geo, JSON_UNESCAPED_UNICODE)]);

    json_ok($geo);

case 'check_duplicates':
    require_auth();
    $body = get_body();
    $appeal_id = sanitize($body['appeal_id'] ?? '');

    $stmt = $pdo->prepare("SELECT ip_address, message, subject, contact_json FROM appeals WHERE appeal_id = ?");
    $stmt->execute([$appeal_id]);
    $ap = $stmt->fetch();
    if (!$ap) json_error('Не найдено', 404);

    $results = [];

    // 1. Обращения с того же IP
    if ($ap['ip_address']) {
        $stmt2 = $pdo->prepare("SELECT appeal_id, subject, created_at, status FROM appeals WHERE ip_address = ? AND appeal_id != ? ORDER BY created_at DESC LIMIT 10");
        $stmt2->execute([$ap['ip_address'], $appeal_id]);
        $results['same_ip'] = $stmt2->fetchAll();
    }

    // 2. Обращения с тем же Telegram
    $results['same_telegram'] = [];
    if (!empty($ap['contact_json'])) {
        $contact_data = json_decode($ap['contact_json'], true);
        $tg = $contact_data['telegram'] ?? '';
        if ($tg) {
            $tg_clean = ltrim($tg, '@');
            $stmt_tg = $pdo->prepare("SELECT appeal_id, subject, created_at, status FROM appeals WHERE contact_json LIKE ? AND appeal_id != ? ORDER BY created_at DESC LIMIT 10");
            $stmt_tg->execute(['%' . addslashes($tg_clean) . '%', $appeal_id]);
            $results['same_telegram'] = $stmt_tg->fetchAll();
        }
    }

    // 3. Похожие по тексту (простое совпадение слов)
    $words = array_filter(explode(' ', $ap['subject']), function($w){ return mb_strlen($w) > 4; });
    $similar = [];
    if ($words) {
        $likes = array_map(function($w){ return "subject LIKE '%".addslashes($w)."%'"; }, array_slice($words, 0, 5));
        $sql = "SELECT appeal_id, subject, created_at, status FROM appeals WHERE appeal_id != ? AND (" . implode(' OR ', $likes) . ") LIMIT 5";
        $stmt3 = $pdo->prepare($sql);
        $stmt3->execute([$appeal_id]);
        $similar = $stmt3->fetchAll();
    }
    $results['similar'] = $similar;

    // 3. Счётчик сообщений с этого IP за 24 часа
    $ip_count = 0;
    if ($ap['ip_address']) {
        $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt4->execute([$ap['ip_address']]);
        $ip_count = (int)$stmt4->fetchColumn();
    }
    $results['ip_count_24h'] = $ip_count;

    json_ok($results);

case 'get_user_stats':
    require_auth();
    $rows = $pdo->query("
        SELECT u.id,
            (SELECT COUNT(*) FROM activity_log al WHERE al.user_id = u.id) as actions,
            (SELECT COUNT(*) FROM appeals a WHERE a.closed_by = u.id AND a.status = 'done') as closed,
            (SELECT COUNT(*) FROM appeals a WHERE a.closed_by = u.id AND a.status = 'rejected') as rejected
        FROM users u ORDER BY u.id
    ")->fetchAll();
    json_ok($rows);

case 'get_scoring':
    require_auth();
    $id = sanitize($_GET['appeal_id'] ?? '');
    $stmt = $pdo->prepare("SELECT * FROM appeals WHERE appeal_id = ?");
    $stmt->execute([$id]);
    $ap = $stmt->fetch();
    if (!$ap) json_error('Не найдено', 404);

    $score = 100; // Начинаем с 100 (максимум доверия)
    $flags = [];

    // Короткое сообщение
    $msgLen = mb_strlen($ap['message']);
    if ($msgLen < 50)  { $score -= 30; $flags[] = ['warn', 'Очень короткое сообщение ('.$msgLen.' символов)']; }
    elseif ($msgLen < 150) { $score -= 15; $flags[] = ['warn', 'Короткое сообщение ('.$msgLen.' символов)']; }
    else { $flags[] = ['ok', 'Достаточный объём текста ('.$msgLen.' символов)']; }

    // Анонимность
    if ($ap['is_anon']) { $score -= 10; $flags[] = ['info', 'Анонимное сообщение']; }
    else { $flags[] = ['ok', 'Указаны контактные данные']; }

    // Частота с одного IP
    if ($ap['ip_address']) {
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt2->execute([$ap['ip_address']]);
        $cnt = (int)$stmt2->fetchColumn();
        if ($cnt >= 5) { $score -= 40; $flags[] = ['danger', $cnt.' сообщений с этого IP за 24 часа']; }
        elseif ($cnt >= 3) { $score -= 20; $flags[] = ['warn', $cnt.' сообщений с этого IP за 24 часа']; }
        else { $flags[] = ['ok', 'Нормальная частота сообщений с IP']; }
    }

    // Геоданные
    if ($ap['geo_country'] && !in_array($ap['geo_country'], ['Russia', 'Россия', 'RU', 'Россия (Федерация)'])) {
        $score -= 25;
        $flags[] = ['warn', 'IP из другой страны: '.$ap['geo_country']];
    } elseif ($ap['geo_city']) {
        $flags[] = ['ok', 'Местоположение: '.$ap['geo_city'].($ap['geo_region'] ? ', '.$ap['geo_region'] : '')];
    }

    // VPN/прокси
    if ($ap['geo_proxy']) { $score -= 30; $flags[] = ['danger', 'Обнаружен VPN или прокси-сервер']; }
    if ($ap['geo_hosting']) { $score -= 20; $flags[] = ['warn', 'IP принадлежит хостинг-провайдеру']; }

    // Устройство
    if ($ap['device_info']) {
        $flags[] = ['info', 'Устройство: '.$ap['device_info']];
    }

    $score = max(0, min(100, $score));
    $level = $score >= 70 ? 'high' : ($score >= 40 ? 'medium' : 'low');

    json_ok(['score' => $score, 'level' => $level, 'flags' => $flags]);

    case 'toggle_ban':
        require_auth();
        if ($_SESSION['role'] !== 'super') json_error('Только администратор');
        $body = get_body();
        $chat_id = intval($body['chat_id'] ?? $_POST['chat_id'] ?? 0);
        if (!$chat_id) json_error('Не указан chat_id');
        $s = $pdo->prepare("SELECT banned FROM bot_users WHERE chat_id = ?");
        $s->execute([$chat_id]);
        $current = $s->fetchColumn();
        if ($current === false) json_error('Пользователь не найден');
        $newVal = (int)$current === 1 ? 0 : 1;
        $pdo->prepare("UPDATE bot_users SET banned = ? WHERE chat_id = ?")->execute([$newVal, $chat_id]);
        json_ok(['banned' => $newVal]);

    default:
        json_error('Неизвестное действие', 404);
}
