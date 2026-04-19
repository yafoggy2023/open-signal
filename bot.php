<?php
/**
 * Telegram-бот — Открытый сигнал
 * Webhook-обработчик
 */

// ── НАСТРОЙКИ ─────────────────────────────────────────
// Токен читается из bot_token.txt (не коммитится в git)
$__tokenFile = __DIR__ . '/bot_token.txt';
if (!file_exists($__tokenFile)) {
    die("bot_token.txt not found. Create it with your Telegram bot token.\n");
}
define('BOT_TOKEN', trim(file_get_contents($__tokenFile)));

// Креды БД — в config.local.php (не коммитится в git). Шаблон: config.local.example.php
$__config = __DIR__ . '/config.local.php';
if (!file_exists($__config)) {
    die("config.local.php не найден. Скопируйте config.local.example.php в config.local.php и впишите реальные значения.\n");
}
require_once $__config;
require_once __DIR__ . '/lib_security.php';

define('SITE_URL', 'http://localhost/fsb');
// URL страницы выбора на карте (HTTPS обязателен для Telegram WebApp!)
// Разместите map_picker.html на GitHub Pages или любом HTTPS-хостинге
define('MAP_PICKER_URL', 'https://yafoggy2023.github.io/open-signal/map_picker.html');

// ── ПОДКЛЮЧЕНИЕ К БД ──────────────────────────────────
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (Exception $e) {
    error_log("Bot DB error: " . $e->getMessage());
    exit;
}

// ── TELEGRAM API ──────────────────────────────────────
function tg($method, $params = [], $timeout = 10) {
    $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/$method");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_DNS_CACHE_TIMEOUT => 300,
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true);
}

function send($chatId, $text, $keyboard = null, $parse = 'HTML') {
    $p = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => $parse];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    return tg('sendMessage', $p);
}

function editMsg($chatId, $msgId, $text, $keyboard = null) {
    $p = ['chat_id' => $chatId, 'message_id' => $msgId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $p['reply_markup'] = $keyboard;
    return tg('editMessageText', $p);
}

function inlineKb($rows) {
    return ['inline_keyboard' => $rows];
}

function btn($text, $data) {
    return ['text' => $text, 'callback_data' => $data];
}

function removeKb() {
    return ['remove_keyboard' => true];
}

function replyKb($buttons, $resize = true, $oneTime = false) {
    return ['keyboard' => $buttons, 'resize_keyboard' => $resize, 'one_time_keyboard' => $oneTime];
}

function senderMenu() {
    return replyKb([
        [['text' => '📨 Отправить сообщение'], ['text' => '🔍 Мои сообщения']]
    ]);
}

function operatorMenu() {
    return replyKb([
        [['text' => '🆕 Новые'], ['text' => '📋 Активные'], ['text' => '📊 Статистика']],
        [['text' => '📨 Отправить сообщение'], ['text' => '🔔 Уведомления']]
    ]);
}

// ── СОСТОЯНИЕ ДИАЛОГА ──────────────────────────────────
function getState($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT state, data FROM bot_states WHERE chat_id = ?");
    $s->execute([$chatId]);
    $r = $s->fetch();
    return $r ? ['state' => $r['state'], 'data' => json_decode($r['data'], true) ?: []] : ['state' => '', 'data' => []];
}

function setState($chatId, $state, $data = []) {
    global $pdo;
    $pdo->prepare("INSERT INTO bot_states (chat_id, state, data) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE state=VALUES(state), data=VALUES(data)")
        ->execute([$chatId, $state, json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function clearState($chatId) {
    global $pdo;
    $pdo->prepare("DELETE FROM bot_states WHERE chat_id = ?")->execute([$chatId]);
}

// ── ПРОВЕРКИ БЕЗОПАСНОСТИ ──────────────────────────────
function isBotBanned($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT banned FROM bot_users WHERE chat_id = ?");
    $s->execute([$chatId]);
    $r = $s->fetchColumn();
    return (int)$r === 1;
}

function checkBotRateLimit($chatId) {
    global $pdo;
    // Чистим старые записи
    $pdo->prepare("DELETE FROM bot_submit_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->execute();
    // За последний час — не больше 3
    $s = $pdo->prepare("SELECT COUNT(*) FROM bot_submit_attempts WHERE chat_id = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $s->execute([$chatId]);
    if ((int)$s->fetchColumn() >= 3) return 'hour';
    // За сутки — не больше 10
    $s = $pdo->prepare("SELECT COUNT(*) FROM bot_submit_attempts WHERE chat_id = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $s->execute([$chatId]);
    if ((int)$s->fetchColumn() >= 10) return 'day';
    return false;
}

function recordBotSubmit($chatId) {
    global $pdo;
    $pdo->prepare("INSERT INTO bot_submit_attempts (chat_id, attempt_time) VALUES (?, NOW())")->execute([$chatId]);
}

function checkDuplicate($chatId, $subject, $message) {
    global $pdo;
    $s = $pdo->prepare("SELECT subject, message FROM appeals WHERE sender_chat_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY created_at DESC LIMIT 5");
    $s->execute([$chatId]);
    foreach ($s->fetchAll() as $r) {
        similar_text(mb_strtolower($subject), mb_strtolower($r['subject']), $subjPct);
        similar_text(mb_strtolower(mb_substr($message, 0, 300)), mb_strtolower(mb_substr($r['message'], 0, 300)), $msgPct);
        if ($subjPct > 80 && $msgPct > 70) return true;
    }
    return false;
}

function validatePhone($phone) {
    $clean = preg_replace('/[\s\-\(\)]/', '', $phone);
    return (bool)preg_match('/^(\+?\d{10,15})$/', $clean);
}

function validateEmail($email) {
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function checkUniqueWords($text) {
    $words = preg_split('/\s+/u', mb_strtolower($text));
    $words = array_filter($words, function($w) { return mb_strlen($w) > 2; });
    if (count($words) < 5) return true; // слишком короткий — пропускаем
    $unique = count(array_unique($words));
    return ($unique / count($words)) > 0.2; // минимум 20% уникальных слов
}

// ── ПОЛЬЗОВАТЕЛЬ БОТА ──────────────────────────────────
function getBotUser($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM bot_users WHERE chat_id = ?");
    $s->execute([$chatId]);
    return $s->fetch();
}

function ensureBotUser($chatId, $username = null, $langCode = null) {
    global $pdo;
    $u = getBotUser($chatId);
    if (!$u) {
        $pdo->prepare("INSERT INTO bot_users (chat_id, username, language_code) VALUES (?, ?, ?)")->execute([$chatId, $username, $langCode]);
        return getBotUser($chatId);
    }
    $updates = [];
    $params = [];
    if ($username && $u['username'] !== $username) { $updates[] = 'username = ?'; $params[] = $username; }
    if ($langCode && ($u['language_code'] ?? '') !== $langCode) { $updates[] = 'language_code = ?'; $params[] = $langCode; }
    if ($updates) {
        $params[] = $chatId;
        $pdo->prepare("UPDATE bot_users SET " . implode(', ', $updates) . " WHERE chat_id = ?")->execute($params);
    }
    return $u;
}

// ── ГЕНЕРАТОР ID ───────────────────────────────────────
function genAppealId() {
    global $pdo;
    $year = date('Y');
    $last = $pdo->query("SELECT appeal_id FROM appeals WHERE appeal_id LIKE 'АПО-$year-%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    $num = $last ? (int)substr($last, -6) + 1 : 1;
    return sprintf("АПО-%s-%06d", $year, $num);
}

// ── УВЕДОМЛЕНИЕ ОПЕРАТОРОВ ─────────────────────────────
function notifyOperators($text) {
    global $pdo;
    $ops = $pdo->query("SELECT chat_id FROM bot_users WHERE role = 'operator' AND notify = 1")->fetchAll();
    foreach ($ops as $op) {
        send($op['chat_id'], $text);
    }
}

// ── СКАЧИВАНИЕ ФАЙЛОВ ИЗ TELEGRAM ─────────────────────
function downloadTgFile($fileId, $fileName, $appealDbId) {
    global $pdo;
    $resp = tg('getFile', ['file_id' => $fileId]);
    if (empty($resp['ok']) || empty($resp['result']['file_path'])) return false;

    $filePath = $resp['result']['file_path'];
    $fileSize = $resp['result']['file_size'] ?? 0;
    $url = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/$filePath";

    // Определяем appeal_id (текстовый) — файлы кладём в папку uploads/<appeal_id>/,
    // как это делает веб-форма. Так get_file в api.php единообразно их находит.
    $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE id = ?");
    $stmt->execute([$appealDbId]);
    $row = $stmt->fetch();
    if (!$row) return false;
    $appealId = $row['appeal_id'];

    // Безопасное имя файла. Префикс time() сохраняем — он защищает от коллизий
    // при нескольких вложениях с одинаковыми именами в одном обращении.
    $safeName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ._\-]/u', '_', $fileName);
    $saveName = time() . '_' . $safeName;

    $appealDir = __DIR__ . '/uploads/' . $appealId . '/';
    if (!is_dir($appealDir)) {
        mkdir($appealDir, 0755, true);
    }
    $savePath = $appealDir . $saveName;

    $content = @file_get_contents($url);
    if ($content === false) return false;

    file_put_contents($savePath, $content);

    // ClamAV-сканирование
    $avStatus = 'clean';
    $avDetail = null;
    if (function_exists('clamav_scan')) {
        $scan = clamav_scan($savePath);
        $avStatus = $scan['status'];
        $avDetail = $scan['detail'] ?? null;
        if ($avStatus === 'infected') {
            @unlink($savePath);
            return 'infected';
        }
    }

    // Сохраняем в БД
    $pdo->prepare("INSERT INTO appeal_files (appeal_db_id, filename, filesize, uploaded_at, av_status, av_detail) VALUES (?, ?, ?, NOW(), ?, ?)")
        ->execute([$appealDbId, $saveName, $fileSize, $avStatus, $avDetail]);

    return true;
}

// ── АВТОСВЯЗКА ПОВТОРНЫХ ──────────────────────────────
function autoLinkAppeals($appealId, $contactJson, $senderChatId) {
    global $pdo;
    $found = [];
    if ($senderChatId) {
        $stmt = $pdo->prepare("SELECT appeal_id FROM appeals WHERE sender_chat_id = ? AND appeal_id != ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$senderChatId, $appealId]);
        foreach ($stmt->fetchAll() as $r) $found[$r['appeal_id']] = true;
    }
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
    foreach (array_keys($found) as $linkedId) {
        $check = $pdo->prepare("SELECT id FROM linked_appeals WHERE (appeal_id_a = ? AND appeal_id_b = ?) OR (appeal_id_a = ? AND appeal_id_b = ?)");
        $check->execute([$appealId, $linkedId, $linkedId, $appealId]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO linked_appeals (appeal_id_a, appeal_id_b) VALUES (?, ?)")->execute([$appealId, $linkedId]);
        }
    }
    return count($found);
}

// ── ПРОГРЕСС-БАР ──────────────────────────────────────
function stepBar($state, $data = []) {
    $isAnon = isset($data['is_anon']) ? (bool)$data['is_anon'] : null;
    $steps = ['await_subject','await_category','await_priority','await_organ',
              'await_location','await_event_date','await_anon'];
    if ($isAnon === false) {
        array_push($steps, 'await_contact_name','await_contact_phone',
                           'await_contact_email','await_contact_telegram');
    }
    array_push($steps, 'await_message','await_files','await_confirm');
    $pos = array_search($state, $steps);
    if ($pos === false) return '';
    $n = $pos + 1; $total = count($steps);
    return "⏳ <i>Шаг $n из $total</i>  " . str_repeat('▰',$n) . str_repeat('▱',$total-$n) . "\n\n";
}

// ── ИСТОРИЯ ДЛЯ КНОПКИ «НАЗАД» ────────────────────────
function pushHistory(&$data, $fromState) {
    $h = $data['__history'] ?? [];
    $h[] = ['state' => $fromState, 'data' => $data];
    if (count($h) > 12) array_shift($h);
    $data['__history'] = $h;
}

function popHistory($data) {
    $h = $data['__history'] ?? [];
    if (!$h) return null;
    return $h[count($h) - 1];
}

// ── ПОКАЗАТЬ ШАГ ЗАНОВО (для кнопки «Назад») ──────────
function askStep($chatId, $state, $data) {
    global $CATEGORIES, $PRIORITIES;
    $bar = stepBar($state, $data);
    $backRow = [['text' => '← Назад']];
    switch ($state) {
        case 'await_subject':
            send($chatId, $bar . "📝 <b>Введите тему сообщения</b> (одной строкой):",
                replyKb([[['text' => '❌ Отмена']]], true, false));
            break;
        case 'await_category':
            $rows = [];
            foreach ($CATEGORIES as $k => $v) $rows[] = [btn($v, "cat_$k")];
            $rows[] = [btn('← Назад', 'go_back')];
            send($chatId, $bar . "📂 <b>Выберите категорию:</b>", inlineKb($rows));
            break;
        case 'await_priority':
            $rows = [];
            foreach ($PRIORITIES as $k => $v) $rows[] = [btn($v, "pri_$k")];
            $rows[] = [btn('← Назад', 'go_back')];
            send($chatId, $bar . "📌 <b>Выберите срочность:</b>", inlineKb($rows));
            break;
        case 'await_organ':
            send($chatId, $bar . "🏢 <b>Укажите орган/организацию</b>\n\nНа кого направлено сообщение?\nНапример: Администрация г. Москвы\n\nВведите текстом или нажмите «Пропустить».",
                replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']], $backRow], true, false));
            break;
        case 'await_location':
            $locKb = ['keyboard' => [
                [['text' => '🗺 Указать на карте', 'web_app' => ['url' => MAP_PICKER_URL]]],
                [['text' => '📍 Отправить геолокацию', 'request_location' => true]],
                [['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']],
                $backRow
            ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
            send($chatId, $bar . "📍 <b>Место события</b>\n\nГде произошло? Укажите адрес, город или регион.\n\n• <b>«🗺 Указать на карте»</b> — откроется карта\n• <b>«📍 Отправить геолокацию»</b> — текущее местоположение\n• Или введите адрес текстом", $locKb);
            break;
        case 'await_event_date':
            send($chatId, $bar . "📅 <b>Дата события</b>\n\nКогда произошло? Укажите дату.\nНапример: 05.03.2026 или март 2026\n\nВведите текстом или нажмите «Пропустить».",
                replyKb([[['text' => '📅 Сегодня'], ['text' => '⏭ Пропустить']], [['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            break;
        case 'await_anon':
            send($chatId, $bar . "👤 <b>Как отправить сообщение?</b>\n\n🔒 <b>Анонимно</b> — ваши личные данные не сохраняются.\n\n👤 <b>С указанием данных</b> — ФИО и контакты нужны для связи с вами.",
                inlineKb([[btn('🔒 Анонимно', 'anon_yes')], [btn('👤 Указать свои данные', 'anon_no')], [btn('← Назад', 'go_back')]]));
            break;
        case 'await_contact_name':
            send($chatId, $bar . "👤 <b>Введите ФИО:</b>\n\nНапример: Иванов Иван Иванович",
                replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            break;
        case 'await_contact_phone':
            $phoneKb = ['keyboard' => [
                [['text' => '📞 Отправить мой номер', 'request_contact' => true]],
                [['text' => '⏭ Пропустить'], ['text' => '← Назад']]
            ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
            send($chatId, $bar . "📞 <b>Номер телефона</b>\n\nНажмите кнопку ниже, чтобы отправить свой номер, или введите вручную:", $phoneKb);
            break;
        case 'await_contact_email':
            send($chatId, $bar . "📧 <b>Введите email</b> или нажмите «Пропустить»:",
                replyKb([[['text' => '⏭ Пропустить'], ['text' => '← Назад']]], true, false));
            break;
        case 'await_contact_telegram':
            $autoTg = $data['contact_telegram_auto'] ?? '';
            $buttons = [];
            if ($autoTg) $buttons[] = [btn("✅ Отправить $autoTg", 'tg_use_auto')];
            $buttons[] = [btn('⏭ Пропустить', 'tg_skip')];
            $buttons[] = [btn('← Назад', 'go_back')];
            $mt = $bar . "💬 <b>Telegram для связи</b>\n\n" . ($autoTg ? "Ваш аккаунт: <b>$autoTg</b>\nНажмите кнопку или введите другой @username:" : "Введите ваш @username или нажмите «Пропустить»:");
            send($chatId, $mt, inlineKb($buttons));
            break;
        case 'await_message':
            send($chatId, $bar . "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.",
                replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            break;
        case 'await_files':
            $cnt = count($data['files'] ?? []);
            $hint = $cnt > 0 ? "Уже прикреплено: $cnt/5. Отправьте ещё или нажмите Готово." : "Можно отправить несколько. Когда закончите, нажмите кнопку ниже.";
            send($chatId, $bar . "📎 <b>Прикрепите файлы, фото или видео</b> (до 5 шт.)\n\n$hint",
                inlineKb([[btn('✅ Отправить без файлов', 'skip_files'), btn('✅ Готово', 'done_files')], [btn('← Назад', 'go_back')]]));
            break;
    }
}

// ── КАТЕГОРИИ ──────────────────────────────────────────
$CATEGORIES = [
    // Коррупция и власть
    'corruption'     => '💰 Коррупция',
    'bribery'        => '🤝 Взяточничество',
    'abuse'          => '⚖️ Злоупотребление полномочиями',
    'embezzlement'   => '🏦 Растрата/хищение бюджета',
    // Мошенничество
    'fraud'          => '🎭 Мошенничество',
    'cyber'          => '💻 Киберпреступления',
    'docs'           => '📄 Подделка документов',
    // Безопасность и насилие
    'safety'         => '🚨 Общественная безопасность',
    'personal'       => '🤕 Преступления против личности',
    'domestic'       => '🏠 Домашнее насилие',
    'trafficking'    => '🔗 Торговля людьми',
    // Экология и ЖКХ
    'ecology'        => '🌿 Экологические нарушения',
    'housing'        => '🏗 Нарушения в ЖКХ',
    // Права и дискриминация
    'labor'          => '👷 Нарушения трудовых прав',
    'discrimination' => '🚫 Дискриминация',
    'consumer'       => '🛒 Права потребителей',
    // Прочее
    'other'          => '📝 Иные нарушения'
];

$PRIORITIES = [
    'low' => '🟢 Обычная',
    'medium' => '🟡 Важная',
    'high' => '🟠 Срочная',
    'critical' => '🔴 Критичная'
];

$STATUS_LABELS = [
    'new' => '🆕 Новое',
    'process' => '🔄 В работе',
    'review' => '🔍 На проверке',
    'done' => '✅ Закрыто',
    'rejected' => '❌ Отклонено'
];

// ═══════════════════════════════════════════════════════
// ОБРАБОТКА ВХОДЯЩЕГО UPDATE
// ═══════════════════════════════════════════════════════

function processUpdate($update) {
global $pdo, $CATEGORIES, $PRIORITIES, $STATUS_LABELS;

// ── CALLBACK QUERY (inline-кнопки) ─────────────────────
if (isset($update['callback_query'])) {
    $cb = $update['callback_query'];
    $chatId = $cb['message']['chat']['id'];
    $msgId = $cb['message']['message_id'];
    $data = $cb['data'];
    $user = ensureBotUser($chatId, $cb['from']['username'] ?? null, $cb['from']['language_code'] ?? null);

    tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

    // Бан-лист
    if (isBotBanned($chatId)) { return; }

    // Согласие на обработку
    if ($data === 'consent_yes' || $data === 'consent_no') {
        if ($data === 'consent_no') {
            clearState($chatId);
            $menu = getBotUser($chatId)['role'] === 'operator' ? operatorMenu() : senderMenu();
            editMsg($chatId, $msgId, "🚫 Отправка отменена.", null);
            send($chatId, "📋 Главное меню:", $menu);
            return;
        }
        setState($chatId, 'await_subject');
        editMsg($chatId, $msgId, "✅ Условия приняты.", null);
        send($chatId, stepBar('await_subject', []) . "📝 <b>Введите тему сообщения</b> (одной строкой):",
            replyKb([[['text' => '❌ Отмена']]], true, false));
        return;
    }

    // Telegram для связи — inline кнопки
    if ($data === 'tg_use_auto' || $data === 'tg_skip') {
        $st = getState($chatId);
        if ($st['state'] === 'await_contact_telegram') {
            $d = $st['data'];
            if ($data === 'tg_use_auto') {
                $d['contact_telegram'] = $d['contact_telegram_auto'] ?? '';
                editMsg($chatId, $msgId, "✅ Telegram: <b>" . ($d['contact_telegram'] ?: '—') . "</b>", null);
            } else {
                $d['contact_telegram'] = '';
                editMsg($chatId, $msgId, "⏭ Telegram пропущен.", null);
            }
            $hasAny = !empty($d['contact_phone']) || !empty($d['contact_email']) || !empty($d['contact_telegram']);
            if (!$hasAny) {
                send($chatId, "⚠ Вы пропустили все контакты. Укажите хотя бы один способ связи (телефон, email или Telegram), иначе мы не сможем с вами связаться.\n\n📞 <b>Введите номер телефона:</b>",
                    replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
                setState($chatId, 'await_contact_phone', $d);
            } else {
                pushHistory($d, 'await_contact_telegram');
                setState($chatId, 'await_message', $d);
                send($chatId, stepBar('await_message', $d) . "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.",
                    replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            }
        }
        return;
    }

    // Выбор категории при создании сообщения
    if (strpos($data, 'cat_') === 0) {
        $cat = substr($data, 4);
        $st = getState($chatId);
        if ($st['state'] === 'await_category') {
            $d = $st['data'];
            pushHistory($d, 'await_category');
            $d['category'] = $CATEGORIES[$cat] ?? $cat;
            setState($chatId, 'await_priority', $d);
            $rows = [];
            foreach ($PRIORITIES as $k => $v) $rows[] = [btn($v, "pri_$k")];
            $rows[] = [btn('← Назад', 'go_back')];
            editMsg($chatId, $msgId, stepBar('await_priority', $d) . "📌 <b>Выберите срочность:</b>", inlineKb($rows));
        }
        return;
    }

    // Выбор приоритета
    if (strpos($data, 'pri_') === 0) {
        $pri = substr($data, 4);
        $st = getState($chatId);
        if ($st['state'] === 'await_priority') {
            $d = $st['data'];
            pushHistory($d, 'await_priority');
            $d['priority'] = $pri;
            setState($chatId, 'await_organ', $d);
            editMsg($chatId, $msgId, stepBar('await_organ', $d) . "🏢 <b>Укажите орган/организацию</b>\n\nНа кого направлено сообщение?\nНапример: Администрация г. Москвы\n\nВведите текстом или нажмите «Пропустить».", null);
            send($chatId, "⬇️", replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']], [['text' => '← Назад']]], true, false));
        }
        return;
    }

    // Выбор анонимности
    if (strpos($data, 'anon_') === 0 ) {
        $st = getState($chatId);
        if ($st['state'] === 'await_anon') {
            $d = $st['data'];
            if ($data === 'anon_yes') {
                pushHistory($d, 'await_anon');
                $d['is_anon'] = 1;
                setState($chatId, 'await_message', $d);
                editMsg($chatId, $msgId, stepBar('await_message', $d) . "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.", null);
                send($chatId, "⬇️", replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            } else {
                pushHistory($d, 'await_anon');
                $d['is_anon'] = 0;
                setState($chatId, 'await_contact_name', $d);
                editMsg($chatId, $msgId, "👤 Вы выбрали: <b>указать данные</b>", null);
                send($chatId, stepBar('await_contact_name', $d) . "👤 <b>Введите ФИО:</b>\n\nНапример: Иванов Иван Иванович\n\n<i>Указывая данные, вы даёте согласие на их обработку в рамках платформы «Открытый сигнал».</i>",
                    replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            }
        }
        return;
    }

    // Пропустить файлы / Готово — показать карточку подтверждения
    if ($data === 'skip_files' || $data === 'done_files') {
        $st = getState($chatId);
        if ($st['state'] === 'await_files') {
            $d = $st['data'];
            pushHistory($d, 'await_files');
            setState($chatId, 'await_confirm', $d);
            $isAnon = !empty($d['is_anon']);
            $anonStr = $isAnon ? '🔒 Анонимно' : '👤 ' . htmlspecialchars($d['contact_name'] ?? 'Контакты указаны');
            $filesCount = count($d['files'] ?? []);
            $filesStr = $filesCount > 0 ? "📎 $filesCount вложений" : 'Без вложений';
            $card = "📋 <b>Проверьте перед отправкой:</b>\n\n"
                . "📌 <b>Тема:</b> " . htmlspecialchars($d['subject']) . "\n"
                . "📂 <b>Категория:</b> " . htmlspecialchars($d['category']) . "\n"
                . "⚡ <b>Срочность:</b> " . ($PRIORITIES[$d['priority']] ?? $d['priority']) . "\n";
            if (!empty($d['organ']))      $card .= "🏢 <b>Орган:</b> " . htmlspecialchars($d['organ']) . "\n";
            if (!empty($d['location']))   $card .= "📍 <b>Место:</b> " . htmlspecialchars($d['location']) . "\n";
            if (!empty($d['event_date_raw']) || !empty($d['event_date']))
                $card .= "📅 <b>Дата:</b> " . htmlspecialchars($d['event_date_raw'] ?? $d['event_date']) . "\n";
            $msgPreview = mb_substr($d['message_text'] ?? '', 0, 300);
            if (mb_strlen($d['message_text'] ?? '') > 300) $msgPreview .= '...';
            $card .= "👤 <b>От:</b> $anonStr\n"
                . "📝 <b>Текст:</b> " . htmlspecialchars($msgPreview) . "\n"
                . "$filesStr\n\n"
                . "Всё верно?";
            editMsg($chatId, $msgId, $card, inlineKb([
                [btn('✅ Отправить', 'confirm_yes')],
                [btn('✏️ Изменить с первого шага', 'confirm_edit')],
                [btn('← Назад к файлам', 'go_back')]
            ]));
        }
        return;
    }

    // Подтверждение — отправить
    if ($data === 'confirm_yes') {
        $st = getState($chatId);
        if ($st['state'] === 'await_confirm') {
            $d = $st['data'];
            $isAnon = !empty($d['is_anon']) ? 1 : 0;
            $contactJson = null;
            $senderUsername = $user['username'] ?? '';
            if (!$isAnon) {
                $contact = [];
                if (!empty($d['contact_name'])) {
                    $parts = explode(' ', $d['contact_name'], 3);
                    $contact['last'] = $parts[0] ?? '';
                    $contact['first'] = $parts[1] ?? '';
                    $contact['middle'] = $parts[2] ?? '';
                }
                if (!empty($d['contact_phone'])) $contact['phone'] = $d['contact_phone'];
                if (!empty($d['contact_email'])) $contact['email'] = $d['contact_email'];
                if (!empty($d['contact_telegram'])) $contact['telegram'] = $d['contact_telegram'];
                if ($senderUsername) $contact['telegram_sender'] = '@' . $senderUsername;
                $contactJson = json_encode($contact, JSON_UNESCAPED_UNICODE);
            } else {
                if ($senderUsername) {
                    $contactJson = json_encode(['telegram_sender' => '@' . $senderUsername], JSON_UNESCAPED_UNICODE);
                }
            }
            $msgText = $d['message_text'] ?? '';

            if (checkDuplicate($chatId, $d['subject'], $msgText)) {
                clearState($chatId);
                $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
                editMsg($chatId, $msgId, "⚠ <b>Похожее сообщение уже отправлено</b>\n\nВы недавно отправляли сообщение с похожей темой и текстом. Повторная отправка отклонена.", null);
                send($chatId, "📋 Главное меню:", $menu); return;
            }
            if (!checkUniqueWords($msgText)) {
                clearState($chatId);
                $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
                editMsg($chatId, $msgId, "⚠ <b>Текст содержит слишком много повторов</b>\n\nПожалуйста, опишите ситуацию своими словами.", null);
                send($chatId, "📋 Главное меню:", $menu); return;
            }
            $spam = spam_analyze($d['subject'], $msgText);
            $spamScore = $spam['score'];
            $spamFlags = !empty($spam['flags']) ? implode('; ', $spam['flags']) : null;
            if ($spamScore >= 60) {
                clearState($chatId);
                $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
                editMsg($chatId, $msgId, "⚠ <b>Сообщение отклонено системой защиты</b>\n\nТекст содержит признаки спама. Если это ошибка, попробуйте переформулировать.", null);
                send($chatId, "📋 Главное меню:", $menu); return;
            }
            recordBotSubmit($chatId);

            $appealId = genAppealId();
            $stmt = $pdo->prepare("INSERT INTO appeals (appeal_id, subject, category, priority, organ, location, event_date, status, is_anon, contact_json, message, sender_chat_id, source, spam_score, spam_flags, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, 'telegram', ?, ?, NOW())");
            $stmt->execute([$appealId, $d['subject'], $d['category'], $d['priority'], $d['organ'] ?? null, $d['location'] ?? null, $d['event_date'] ?? null, $isAnon, $contactJson, $msgText, $chatId, $spamScore, $spamFlags]);
            $appealDbId = $pdo->lastInsertId();

            $linkedCount = autoLinkAppeals($appealId, $contactJson, $chatId);

            $files = $d['files'] ?? [];
            $savedFiles = 0; $infectedFiles = 0;
            foreach ($files as $f) {
                $saved = downloadTgFile($f['file_id'], $f['file_name'], $appealDbId);
                if ($saved === 'infected') $infectedFiles++;
                elseif ($saved) $savedFiles++;
            }
            clearState($chatId);

            $contactInfo = $isAnon ? '🔒 Анонимно' : '👤 ' . ($d['contact_name'] ?? '');
            $fileInfo = $savedFiles > 0 ? "\n📎 Файлов: $savedFiles" : '';
            if ($infectedFiles > 0) $fileInfo .= "\n⚠ Заражённых файлов удалено: $infectedFiles";
            $linkInfo = $linkedCount > 0 ? "\n🔗 Повторное обращение ($linkedCount связ.)" : '';
            $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
            $organInfo = !empty($d['organ']) ? "\n🏛 Орган: " . htmlspecialchars($d['organ']) : '';
            $locationInfo = !empty($d['location']) ? "\n📍 Место: " . htmlspecialchars($d['location']) : '';
            $dateInfo = !empty($d['event_date']) ? "\n📅 Дата события: " . $d['event_date'] : (!empty($d['event_date_raw']) ? "\n📅 Дата события: " . htmlspecialchars($d['event_date_raw']) : '');

            $confirmKb = null;
            if (!empty($d['location'])) {
                $mapUrl = 'https://yandex.ru/maps/?text=' . urlencode($d['location']);
                $confirmKb = inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]);
            }
            editMsg($chatId, $msgId, "✅ <b>Сообщение отправлено!</b>\n\n"
                . "📋 Номер: <code>$appealId</code>\n"
                . "📌 Тема: " . htmlspecialchars($d['subject']) . "\n"
                . "📂 Категория: " . htmlspecialchars($CATEGORIES[$d['category']] ?? $d['category']) . "\n"
                . "⚡ Приоритет: " . ($PRIORITIES[$d['priority']] ?? $d['priority'])
                . "$organInfo$locationInfo$dateInfo\n"
                . "$contactInfo$fileInfo\n\n"
                . "<i>Данная платформа не является государственным ресурсом.</i>", $confirmKb);
            send($chatId, "📋 Главное меню:", $menu);

            $notify = "🔔 <b>Новое сообщение!</b>\n\n📋 <b>$appealId</b>\n📌 " . htmlspecialchars($d['subject'])
                . "\n📂 " . htmlspecialchars($CATEGORIES[$d['category']] ?? $d['category'])
                . "\n⚡ " . ($PRIORITIES[$d['priority']] ?? $d['priority'])
                . "\n$contactInfo$fileInfo$linkInfo\n\n"
                . mb_substr(htmlspecialchars($msgText), 0, 200) . (mb_strlen($msgText) > 200 ? '...' : '');
            $ops = $pdo->query("SELECT chat_id FROM bot_users WHERE role = 'operator' AND notify = 1")->fetchAll();
            foreach ($ops as $op) {
                if ($op['chat_id'] != $chatId)
                    send($op['chat_id'], $notify, inlineKb([[btn("📋 Открыть", "view_$appealId")]]));
            }
        }
        return;
    }

    // Подтверждение — редактировать с первого шага
    if ($data === 'confirm_edit') {
        $st = getState($chatId);
        if ($st['state'] === 'await_confirm') {
            $d = $st['data'];
            $d['__history'] = [];
            setState($chatId, 'await_subject', $d);
            editMsg($chatId, $msgId, "✏️ Редактируем с первого шага.", null);
            send($chatId, stepBar('await_subject', $d) . "📝 <b>Введите тему сообщения</b> (одной строкой):",
                replyKb([[['text' => '❌ Отмена']]], true, false));
        }
        return;
    }

    // Кнопка «← Назад»
    if ($data === 'go_back') {
        $st = getState($chatId);
        $prev = popHistory($st['data']);
        if ($prev) {
            setState($chatId, $prev['state'], $prev['data']);
            askStep($chatId, $prev['state'], $prev['data']);
        }
        return;
    }

    // Черновик — продолжить / начать заново
    if ($data === 'draft_resume') {
        $st = getState($chatId);
        askStep($chatId, $st['state'], $st['data']);
        return;
    }
    if ($data === 'draft_discard') {
        clearState($chatId);
        editMsg($chatId, $msgId, "🗑 Черновик удалён.", null);
        setState($chatId, 'await_consent');
        send($chatId, "📨 <b>Новое сообщение</b>\n\nПеред отправкой ознакомьтесь с условиями:\n\n"
            . "• Данная платформа <b>не является</b> государственным ресурсом.\n"
            . "• Если вы укажете персональные данные, они будут использованы <b>исключительно</b> для связи с вами.\n"
            . "• Платформа не передаёт данные третьим лицам без вашего согласия.\n\n"
            . "Нажмите <b>«Согласен»</b>, чтобы продолжить.",
            inlineKb([[btn('✅ Согласен, продолжить', 'consent_yes')], [btn('❌ Отмена', 'consent_no')]]));
        return;
    }

    // Оператор: смена статуса
    if (strpos($data, 'status_') === 0 && $user['role'] === 'operator') {
        $parts = explode('_', $data, 3);
        $newStatus = $parts[1];
        $appealId = $parts[2];

        $stmt = $pdo->prepare("UPDATE appeals SET status = ? WHERE appeal_id = ?");
        $stmt->execute([$newStatus, $appealId]);

        // Логируем
        $uid = $user['user_id'];
        if ($uid) {
            $pdo->prepare("INSERT INTO activity_log (user_id, action, appeal_id, detail, created_at) VALUES (?, 'status_change', ?, ?, NOW())")
                ->execute([$uid, $appealId, "→ $newStatus (через бот)"]);
        }

        editMsg($chatId, $msgId, "✅ Статус <b>$appealId</b> изменён на: <b>" . ($STATUS_LABELS[$newStatus] ?? $newStatus) . "</b>");

        // Уведомить отправителя
        $ap = $pdo->prepare("SELECT sender_chat_id FROM appeals WHERE appeal_id = ?");
        $ap->execute([$appealId]);
        $senderChat = $ap->fetchColumn();
        if ($senderChat) {
            send($senderChat, "📋 Статус вашего сообщения <b>$appealId</b> изменён:\n\n" . ($STATUS_LABELS[$newStatus] ?? $newStatus));
        }
        return;
    }

    // Оператор: просмотр сообщения
    if (strpos($data, 'view_') === 0 && $user['role'] === 'operator') {
        $appealId = substr($data, 5);
        $stmt = $pdo->prepare("SELECT * FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appealId]);
        $a = $stmt->fetch();
        if (!$a) { send($chatId, "❌ Сообщение не найдено."); return; }

        $contact = '🔒 Анонимно';
        if (!$a['is_anon'] && $a['contact_json']) {
            $c = json_decode($a['contact_json'], true);
            $parts = [];
            if (!empty($c['last'])) $parts[] = $c['last'] . ' ' . ($c['first'] ?? '');
            if (!empty($c['phone'])) $parts[] = "📞 " . $c['phone'];
            if (!empty($c['email'])) $parts[] = "📧 " . $c['email'];
            if (!empty($c['telegram'])) $parts[] = "💬 " . $c['telegram'];
            $contact = implode("\n", $parts);
        }

        $text = "📋 <b>$appealId</b>\n"
            . "━━━━━━━━━━━━━━━━\n"
            . "📌 <b>Тема:</b> " . htmlspecialchars($a['subject']) . "\n"
            . "📂 <b>Категория:</b> " . htmlspecialchars($CATEGORIES[$a['category']] ?? $a['category']) . "\n"
            . "⚡ <b>Приоритет:</b> " . ($PRIORITIES[$a['priority']] ?? $a['priority']) . "\n"
            . "📊 <b>Статус:</b> " . ($STATUS_LABELS[$a['status']] ?? $a['status']) . "\n"
            . "📅 <b>Дата:</b> " . $a['created_at'] . "\n"
            . "━━━━━━━━━━━━━━━━\n"
            . "👤 <b>Заявитель:</b>\n$contact\n"
            . "━━━━━━━━━━━━━━━━\n"
            . "📝 <b>Текст:</b>\n" . htmlspecialchars(mb_substr($a['message'], 0, 800));

        if (mb_strlen($a['message']) > 800) $text .= "\n<i>...текст обрезан</i>";

        $location = $a['location'] ? "\n📍 <b>Место:</b> " . htmlspecialchars($a['location']) : '';
        $text .= $location;

        // Кнопки смены статуса
        $buttons = [];
        $statuses = ['new' => '🆕 Новое', 'process' => '🔄 В работу', 'review' => '🔍 Проверка', 'done' => '✅ Закрыть', 'rejected' => '❌ Отклонить'];
        $row = [];
        foreach ($statuses as $sk => $sv) {
            if ($sk === $a['status']) continue;
            $row[] = btn($sv, "status_{$sk}_{$appealId}");
            if (count($row) === 3) { $buttons[] = $row; $row = []; }
        }
        if ($row) $buttons[] = $row;
        $buttons[] = [btn("💬 Комментарий", "comment_$appealId")];

        send($chatId, $text, inlineKb($buttons));
        return;
    }

    // Просмотр статуса (для отправителя)
    if (strpos($data, 'check_') === 0) {
        $appealId = substr($data, 6);
        $stmt = $pdo->prepare("SELECT appeal_id, subject, category, priority, status, created_at FROM appeals WHERE appeal_id = ?");
        $stmt->execute([$appealId]);
        $a = $stmt->fetch();
        if (!$a) { send($chatId, "❌ Не найдено."); return; }
        send($chatId, "📋 <b>{$a['appeal_id']}</b>\n\n"
            . "📌 <b>Тема:</b> " . htmlspecialchars($a['subject']) . "\n"
            . "📂 <b>Категория:</b> " . htmlspecialchars($CATEGORIES[$a['category']] ?? $a['category']) . "\n"
            . "⚡ <b>Приоритет:</b> " . ($PRIORITIES[$a['priority']] ?? $a['priority']) . "\n"
            . "📊 <b>Статус:</b> " . ($STATUS_LABELS[$a['status']] ?? $a['status']) . "\n"
            . "📅 <b>Дата:</b> " . $a['created_at']);
        return;
    }

    // Оператор: начать комментарий
    if (strpos($data, 'comment_') === 0 && $user['role'] === 'operator') {
        $appealId = substr($data, 8);
        setState($chatId, 'await_comment', ['appeal_id' => $appealId]);
        send($chatId, "💬 Напишите комментарий к <b>$appealId</b>:");
        return;
    }

    return;
}

// ── ТЕКСТОВОЕ СООБЩЕНИЕ ────────────────────────────────
if (!isset($update['message'])) return;
$msg = $update['message'];
$chatId = $msg['chat']['id'];
$text = trim($msg['text'] ?? '');
$username = $msg['from']['username'] ?? null;
$langCode = $msg['from']['language_code'] ?? null;
$user = ensureBotUser($chatId, $username, $langCode);
$st = getState($chatId);

// Бан-лист
if (isBotBanned($chatId)) {
    send($chatId, "🚫 Ваш аккаунт заблокирован на платформе.");
    return;
}

// ── КОМАНДЫ ────────────────────────────────────────────

if ($text === '/start' || $text === '🏠 Меню') {
    clearState($chatId);
    $isOp = $user['role'] === 'operator';
    $welcome = "👋 <b>Открытый сигнал</b>\n"
        . "Независимая платформа для приёма сообщений\n\n";
    if ($isOp) {
        $welcome .= "🟢 Вы авторизованы как <b>оператор</b>.\nИспользуйте кнопки ниже.";
    } else {
        $welcome .= "Данный бот <b>не является</b> официальным ресурсом государственного органа и не осуществляет приём обращений в порядке ФЗ №59.\n\n"
            . "📌 Платформа не гарантирует юридически значимых последствий.\n"
            . "📌 Информация не направляется в гос. органы автоматически.\n"
            . "📌 Отправитель несёт ответственность за достоверность сведений.\n\n"
            . "Продолжая использование, вы подтверждаете, что ознакомлены с данными условиями.";
    }
    send($chatId, $welcome, $isOp ? operatorMenu() : senderMenu());
    return;
}

if ($text === '/cancel' || $text === '❌ Отмена') {
    clearState($chatId);
    $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
    send($chatId, "🚫 Действие отменено.", $menu);
    return;
}

if ($text === '← Назад') {
    $prev = popHistory($st['data']);
    if ($prev) {
        setState($chatId, $prev['state'], $prev['data']);
        askStep($chatId, $prev['state'], $prev['data']);
    } else {
        send($chatId, "Это первый шаг.", replyKb([[['text' => '❌ Отмена']]]));
    }
    return;
}

// ── КНОПКИ МЕНЮ ──────────────────────────────────────
if ($text === '📨 Отправить сообщение') { $text = '/send'; }
if ($text === '🔍 Мои сообщения')       { $text = '/status'; }
if ($text === '🆕 Новые')               { $text = '/new'; }
if ($text === '📋 Активные')            { $text = '/list'; }
if ($text === '🔔 Уведомления')         { $text = '/notify'; }
if ($text === '📊 Статистика' && $user['role'] === 'operator') {
    $r = $pdo->query("
        SELECT
            COUNT(*) AS total,
            SUM(status='new') AS new_cnt,
            SUM(status IN ('process','review')) AS proc_cnt,
            SUM(status='done') AS done_cnt,
            SUM(status='rejected') AS rej_cnt
        FROM appeals
    ")->fetch();
    send($chatId, "📊 <b>Статистика платформы</b>\n\n"
        . "📋 Всего: <b>{$r['total']}</b>\n"
        . "🆕 Новые: <b>{$r['new_cnt']}</b>\n"
        . "🔄 В работе: <b>{$r['proc_cnt']}</b>\n"
        . "✅ Закрыто: <b>{$r['done_cnt']}</b>\n"
        . "❌ Отклонено: <b>{$r['rej_cnt']}</b>", operatorMenu());
    return;
}

// ── /myid — показать chat_id для привязки ─────────────
if ($text === '/myid') {
    send($chatId, "🆔 Ваш Telegram ID:\n\n<code>$chatId</code>\n\nСообщите этот ID администратору, чтобы он привязал ваш аккаунт в админ-панели сайта.", $user['role'] === 'operator' ? operatorMenu() : senderMenu());
    return;
}

// ── /send — создание сообщения ─────────────────────────
if ($text === '/send') {
    // Rate-limit
    $rl = checkBotRateLimit($chatId);
    if ($rl === 'hour') { send($chatId, "⚠ Вы отправили слишком много сообщений. Попробуйте через час."); return; }
    if ($rl === 'day')  { send($chatId, "⚠ Достигнут дневной лимит сообщений. Попробуйте завтра."); return; }

    // Проверяем черновик
    $draftStates = ['await_subject','await_category','await_priority','await_organ','await_location',
                    'await_event_date','await_anon','await_contact_name','await_contact_phone',
                    'await_contact_email','await_contact_telegram','await_message','await_files'];
    if (in_array($st['state'], $draftStates) && !empty($st['data'])) {
        $subjectPreview = !empty($st['data']['subject']) ? "\n📌 Тема: <b>" . htmlspecialchars($st['data']['subject']) . "</b>" : '';
        send($chatId, "📝 <b>У вас есть незавершённый черновик</b>$subjectPreview\n\nПродолжить с места остановки или начать заново?",
            inlineKb([[btn('▶️ Продолжить черновик', 'draft_resume')], [btn('🗑 Начать заново', 'draft_discard')]]));
        return;
    }

    setState($chatId, 'await_consent');
    send($chatId, "📨 <b>Новое сообщение</b>\n\n"
        . "Перед отправкой ознакомьтесь с условиями:\n\n"
        . "• Данная платформа <b>не является</b> государственным ресурсом.\n"
        . "• Отправленные сведения обрабатываются операторами платформы.\n"
        . "• Если вы укажете персональные данные, они будут использованы <b>исключительно</b> для связи с вами и выяснения обстоятельств.\n"
        . "• Платформа не передаёт данные третьим лицам без вашего согласия.\n\n"
        . "Нажмите <b>«Согласен»</b>, чтобы продолжить.",
        inlineKb([[btn('✅ Согласен, продолжить', 'consent_yes')], [btn('❌ Отмена', 'consent_no')]]));
    return;
}

if ($st['state'] === 'await_subject') {
    if (mb_strlen($text) < 5) { send($chatId, "⚠ Тема слишком короткая. Минимум 5 символов."); return; }
    if (mb_strlen($text) > 200) { send($chatId, "⚠ Тема слишком длинная. Максимум 200 символов."); return; }
    $d = $st['data'];
    pushHistory($d, 'await_subject');
    $d['subject'] = $text;
    setState($chatId, 'await_category', $d);
    $rows = [];
    foreach ($CATEGORIES as $k => $v) $rows[] = [btn($v, "cat_$k")];
    $rows[] = [btn('← Назад', 'go_back')];
    send($chatId, stepBar('await_category', $d) . "📂 <b>Выберите категорию:</b>", inlineKb($rows));
    return;
}

// ── Орган, Место, Дата события ────────────────────────
if ($st['state'] === 'await_organ') {
    $d = $st['data'];
    pushHistory($d, 'await_organ');
    $d['organ'] = ($text === '⏭ Пропустить') ? '' : $text;
    setState($chatId, 'await_location', $d);
    $locKb = ['keyboard' => [
        [['text' => '🗺 Указать на карте', 'web_app' => ['url' => MAP_PICKER_URL]]],
        [['text' => '📍 Отправить геолокацию', 'request_location' => true]],
        [['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']],
        [['text' => '← Назад']]
    ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    send($chatId, stepBar('await_location', $d) . "📍 <b>Место события</b>\n\nГде произошло? Укажите адрес, город или регион.\n\n"
        . "• <b>«🗺 Указать на карте»</b> — откроется карта, выберите точку\n"
        . "• <b>«📍 Отправить геолокацию»</b> — отправить текущее местоположение\n"
        . "• Или введите адрес текстом\n"
        . "• Или нажмите «Пропустить»", $locKb);
    return;
}

if ($st['state'] === 'await_location') {
    $d = $st['data'];

    // Выбор на карте через WebApp
    if (isset($msg['web_app_data'])) {
        $webData = json_decode($msg['web_app_data']['data'], true);
        if ($webData && !empty($webData['address'])) {
            $d['location'] = $webData['address'];
            $d['location_lat'] = $webData['lat'] ?? null;
            $d['location_lon'] = $webData['lon'] ?? null;
            $lat = $webData['lat']; $lon = $webData['lon'];
            send($chatId, "📍 Место выбрано на карте:\n<b>" . htmlspecialchars($webData['address'], ENT_QUOTES, 'UTF-8') . "</b>",
                inlineKb([[['text' => '🗺 Показать на карте', 'url' => "https://yandex.ru/maps/?pt=$lon,$lat&z=16&l=map"]]]));
            pushHistory($d, 'await_location');
            setState($chatId, 'await_event_date', $d);
            send($chatId, stepBar('await_event_date', $d) . "📅 <b>Дата события</b>\n\nКогда произошло? Укажите дату.\nНапример: 05.03.2026 или март 2026\n\nВведите текстом или нажмите «Пропустить».",
                replyKb([[['text' => '📅 Сегодня'], ['text' => '⏭ Пропустить']], [['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
            return;
        }
    }

    // Геолокация через кнопку
    if (isset($msg['location'])) {
        $lat = $msg['location']['latitude'];
        $lon = $msg['location']['longitude'];
        $geoUrl = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1&accept-language=ru";
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: OpenSignalBot/1.0\r\n", 'timeout' => 5]]);
        $geoJson = @file_get_contents($geoUrl, false, $ctx);
        $address = '';
        if ($geoJson) { $geo = json_decode($geoJson, true); $address = $geo['display_name'] ?? ''; }
        if (!$address) $address = "$lat, $lon";
        $d['location'] = $address;
        $d['location_lat'] = $lat;
        $d['location_lon'] = $lon;
        $mapUrl = "https://yandex.ru/maps/?pt=$lon,$lat&z=16&l=map";
        send($chatId, "📍 Место определено:\n<b>" . htmlspecialchars($address, ENT_QUOTES, 'UTF-8') . "</b>",
            inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]));
    } elseif ($text === '⏭ Пропустить') {
        $d['location'] = '';
    } else {
        $d['location'] = $text;
        $mapUrl = 'https://yandex.ru/maps/?text=' . urlencode($text);
        send($chatId, "📍 Место: <b>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</b>",
            inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]));
    }
    pushHistory($d, 'await_location');
    setState($chatId, 'await_event_date', $d);
    send($chatId, stepBar('await_event_date', $d) . "📅 <b>Дата события</b>\n\nКогда произошло? Укажите дату.\nНапример: 05.03.2026 или март 2026\n\nВведите текстом или нажмите «Пропустить».",
        replyKb([[['text' => '📅 Сегодня'], ['text' => '⏭ Пропустить']], [['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
    return;
}

if ($st['state'] === 'await_event_date') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить') {
        $d['event_date'] = null;
    } elseif ($text === '📅 Сегодня') {
        $d['event_date'] = date('Y-m-d');
        $d['event_date_raw'] = date('d.m.Y');
    } else {
        $parsed = null;
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $text, $m)) {
            $parsed = $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $parsed = $text;
        }
        $d['event_date'] = $parsed;
        $d['event_date_raw'] = $text;
    }
    pushHistory($d, 'await_event_date');
    setState($chatId, 'await_anon', $d);
    send($chatId, stepBar('await_anon', $d) . "👤 <b>Как отправить сообщение?</b>\n\n"
        . "🔒 <b>Анонимно</b> — ваши личные данные не сохраняются. Мы не сможем связаться с вами для уточнения деталей.\n\n"
        . "👤 <b>С указанием данных</b> — ФИО и контакты нужны для связи с вами и выяснения обстоятельств. "
        . "Данные используются исключительно в рамках платформы и не передаются третьим лицам.",
        inlineKb([
            [btn('🔒 Анонимно', 'anon_yes')],
            [btn('👤 Указать свои данные', 'anon_no')],
            [btn('← Назад', 'go_back')]
        ]));
    return;
}

// ── Контактные данные (не анонимно) ───────────────────
if ($st['state'] === 'await_contact_name') {
    if ($text === '⏭ Пропустить') { send($chatId, "⚠ ФИО обязательно при указании данных. Введите ФИО (минимум 3 символа)."); return; }
    if (mb_strlen($text) < 3) { send($chatId, "⚠ Введите ФИО (минимум 3 символа)."); return; }
    $d = $st['data'];
    pushHistory($d, 'await_contact_name');
    $d['contact_name'] = $text;
    setState($chatId, 'await_contact_phone', $d);
    $phoneKb = ['keyboard' => [
        [['text' => '📞 Отправить мой номер', 'request_contact' => true]],
        [['text' => '⏭ Пропустить'], ['text' => '← Назад']]
    ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    send($chatId, stepBar('await_contact_phone', $d) . "📞 <b>Номер телефона</b>\n\nНажмите кнопку ниже, чтобы отправить свой номер, или введите вручную:", $phoneKb);
    return;
}

if ($st['state'] === 'await_contact_phone') {
    if (isset($msg['contact'])) {
        $d = $st['data'];
        pushHistory($d, 'await_contact_phone');
        $d['contact_phone'] = '+' . $msg['contact']['phone_number'];
        setState($chatId, 'await_contact_email', $d);
        send($chatId, "✅ Номер принят: <b>" . $d['contact_phone'] . "</b>\n\n" . stepBar('await_contact_email', $d) . "📧 <b>Введите email</b> или нажмите «Пропустить»:",
            replyKb([[['text' => '⏭ Пропустить'], ['text' => '← Назад']]], true, false));
        return;
    }
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_phone'] = '';
    } else {
        if (!validatePhone($text)) {
            send($chatId, "⚠ Некорректный номер телефона. Введите в формате +7XXXXXXXXXX или нажмите «Пропустить».");
            return;
        }
        $d['contact_phone'] = $text;
    }
    pushHistory($d, 'await_contact_phone');
    setState($chatId, 'await_contact_email', $d);
    send($chatId, stepBar('await_contact_email', $d) . "📧 <b>Введите email</b> или нажмите «Пропустить»:",
        replyKb([[['text' => '⏭ Пропустить'], ['text' => '← Назад']]], true, false));
    return;
}

if ($st['state'] === 'await_contact_email') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_email'] = '';
    } else {
        if (!validateEmail($text)) {
            send($chatId, "⚠ Некорректный email. Введите в формате example@mail.ru или нажмите «Пропустить».");
            return;
        }
        $d['contact_email'] = $text;
    }
    $autoTg = $username ? '@' . $username : '';
    $d['contact_telegram_auto'] = $autoTg;
    pushHistory($d, 'await_contact_email');
    setState($chatId, 'await_contact_telegram', $d);
    $buttons = [];
    if ($autoTg) $buttons[] = [btn("✅ Отправить $autoTg", 'tg_use_auto')];
    $buttons[] = [btn('⏭ Пропустить', 'tg_skip')];
    $buttons[] = [btn('← Назад', 'go_back')];
    $msg_text = stepBar('await_contact_telegram', $d) . "💬 <b>Telegram для связи</b>\n\n";
    $msg_text .= $autoTg ? "Ваш аккаунт: <b>$autoTg</b>\nНажмите кнопку, чтобы подтвердить, или введите другой @username:" : "Введите ваш @username или нажмите «Пропустить»:";
    send($chatId, $msg_text, inlineKb($buttons));
    return;
}

if ($st['state'] === 'await_contact_telegram') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_telegram'] = '';
    } else {
        $d['contact_telegram'] = $text;
    }
    $hasPhone = !empty($d['contact_phone']);
    $hasEmail = !empty($d['contact_email']);
    $hasTg    = !empty($d['contact_telegram']);
    if (!$hasPhone && !$hasEmail && !$hasTg) {
        send($chatId, "⚠ Вы пропустили все контакты. Укажите хотя бы один способ связи (телефон, email или Telegram), иначе мы не сможем с вами связаться.\n\n📞 <b>Введите номер телефона:</b>",
            replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
        setState($chatId, 'await_contact_phone', $d);
        return;
    }
    pushHistory($d, 'await_contact_telegram');
    setState($chatId, 'await_message', $d);
    send($chatId, stepBar('await_message', $d) . "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.",
        replyKb([[['text' => '❌ Отмена'], ['text' => '← Назад']]], true, false));
    return;
}

// ── Текст сообщения ───────────────────────────────────
if ($st['state'] === 'await_message') {
    if (mb_strlen($text) < 20) { send($chatId, "⚠ Текст слишком короткий. Минимум 20 символов."); return; }
    $d = $st['data'];
    pushHistory($d, 'await_message');
    $d['message_text'] = $text;
    $d['files'] = [];
    setState($chatId, 'await_files', $d);
    send($chatId, stepBar('await_files', $d) . "📎 <b>Прикрепите файлы, фото или видео</b> (до 5 шт.)\n\nМожно отправить несколько. Когда закончите, нажмите кнопку ниже.",
        inlineKb([[btn('✅ Отправить без файлов', 'skip_files'), btn('✅ Готово', 'done_files')], [btn('← Назад', 'go_back')]]));
    return;
}

// ── Приём файлов/фото/видео ───────────────────────────
if ($st['state'] === 'await_files') {
    $fileId = null;
    $fileName = null;
    $fileType = null;

    if (isset($msg['photo'])) {
        $photo = end($msg['photo']);
        $fileId = $photo['file_id'];
        $fileName = 'photo_' . time() . '_' . rand(100,999) . '.jpg';
        $fileType = '🖼';
    } elseif (isset($msg['video'])) {
        $fileId = $msg['video']['file_id'];
        $fileName = $msg['video']['file_name'] ?? ('video_' . time() . '_' . rand(100,999) . '.mp4');
        $fileType = '🎬';
    } elseif (isset($msg['document'])) {
        $fileId = $msg['document']['file_id'];
        $fileName = $msg['document']['file_name'] ?? ('file_' . time());
        $fileType = '📄';
    } elseif (isset($msg['voice'])) {
        $fileId = $msg['voice']['file_id'];
        $fileName = 'voice_' . time() . '_' . rand(100,999) . '.ogg';
        $fileType = '🎤';
    } elseif (isset($msg['audio'])) {
        $fileId = $msg['audio']['file_id'];
        $fileName = $msg['audio']['file_name'] ?? ('audio_' . time() . '_' . rand(100,999) . '.mp3');
        $fileType = '🎵';
    } elseif (isset($msg['video_note'])) {
        $fileId = $msg['video_note']['file_id'];
        $fileName = 'videonote_' . time() . '_' . rand(100,999) . '.mp4';
        $fileType = '⏺';
    }

    if ($fileId) {
        $d = $st['data'];
        $files = $d['files'] ?? [];
        if (count($files) >= 5) {
            send($chatId, "⚠ Максимум 5 файлов. Нажмите <b>Готово</b> для отправки.",
                inlineKb([[btn('✅ Готово, отправить', 'done_files')]]));
            return;
        }
        $files[] = ['file_id' => $fileId, 'file_name' => $fileName];
        $d['files'] = $files;
        setState($chatId, 'await_files', $d);
        $cnt = count($files);
        send($chatId, "$fileType Принято ($cnt/5). Отправьте ещё или нажмите <b>Готово</b>.",
            inlineKb([[btn('✅ Готово, отправить', 'done_files')], [btn('← Назад', 'go_back')]]));
        return;
    }

    if ($text && $text !== '❌ Отмена') {
        send($chatId, "⚠ Сейчас ожидаются файлы, фото или видео. Отправьте файл или нажмите кнопку.",
            inlineKb([[btn('✅ Отправить без файлов', 'skip_files'), btn('✅ Готово', 'done_files')], [btn('← Назад', 'go_back')]]));
        return;
    }
}

// ── /status — проверка статуса ─────────────────────────
if ($text === '/status') {
    // Показать сообщения этого пользователя
    $stmt = $pdo->prepare("SELECT appeal_id, subject, status, created_at FROM appeals WHERE sender_chat_id = ? ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$chatId]);
    $list = $stmt->fetchAll();

    $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
    if (!$list) {
        send($chatId, "📭 У вас пока нет отправленных сообщений.", $menu);
        return;
    }

    $t = "📋 <b>Ваши сообщения:</b>\n\n";
    $buttons = [];
    foreach ($list as $a) {
        $s = $STATUS_LABELS[$a['status']] ?? $a['status'];
        $t .= "• <code>{$a['appeal_id']}</code> — $s\n  {$a['subject']}\n  📅 " . substr($a['created_at'], 0, 10) . "\n\n";
        $buttons[] = [btn("🔍 " . $a['appeal_id'], "check_" . $a['appeal_id'])];
    }
    send($chatId, $t, inlineKb(array_slice($buttons, 0, 10)));
    return;
}

// /check АПО-...
if (strpos($text, '/check ') === 0) {
    $id = trim(substr($text, 7));
    $stmt = $pdo->prepare("SELECT appeal_id, subject, category, priority, status, created_at FROM appeals WHERE appeal_id = ?");
    $stmt->execute([$id]);
    $a = $stmt->fetch();
    if (!$a) { send($chatId, "❌ Сообщение <code>$id</code> не найдено."); return; }

    send($chatId, "📋 <b>{$a['appeal_id']}</b>\n\n"
        . "📌 <b>Тема:</b> " . htmlspecialchars($a['subject']) . "\n"
        . "📂 <b>Категория:</b> " . htmlspecialchars($CATEGORIES[$a['category']] ?? $a['category']) . "\n"
        . "⚡ <b>Приоритет:</b> " . ($PRIORITIES[$a['priority']] ?? $a['priority']) . "\n"
        . "📊 <b>Статус:</b> " . ($STATUS_LABELS[$a['status']] ?? $a['status']) . "\n"
        . "📅 <b>Дата:</b> " . $a['created_at']);
    return;
}

// ── ОПЕРАТОРСКИЕ КОМАНДЫ ───────────────────────────────

if ($text === '/new' && $user['role'] === 'operator') {
    $stmt = $pdo->query("SELECT appeal_id, subject, priority, created_at FROM appeals WHERE status = 'new' ORDER BY created_at DESC LIMIT 15");
    $list = $stmt->fetchAll();
    if (!$list) { send($chatId, "📭 Новых сообщений нет.", operatorMenu()); return; }

    $t = "🆕 <b>Новые сообщения (" . count($list) . "):</b>\n\n";
    $buttons = [];
    foreach ($list as $a) {
        $pri = $PRIORITIES[$a['priority']] ?? '';
        $t .= "• <code>{$a['appeal_id']}</code> $pri\n  " . htmlspecialchars(mb_substr($a['subject'], 0, 50)) . "\n  📅 " . substr($a['created_at'], 0, 10) . "\n\n";
        $buttons[] = [btn("📋 " . $a['appeal_id'], "view_" . $a['appeal_id'])];
    }
    send($chatId, $t, inlineKb(array_slice($buttons, 0, 10)));
    return;
}

if ($text === '/list' && $user['role'] === 'operator') {
    $stmt = $pdo->query("SELECT appeal_id, subject, status, priority, created_at FROM appeals WHERE status NOT IN ('done','rejected') ORDER BY FIELD(priority,'critical','high','medium','low'), created_at DESC LIMIT 20");
    $list = $stmt->fetchAll();
    if (!$list) { send($chatId, "📭 Активных сообщений нет.", operatorMenu()); return; }

    $t = "📋 <b>Активные сообщения (" . count($list) . "):</b>\n\n";
    $buttons = [];
    foreach ($list as $a) {
        $s = $STATUS_LABELS[$a['status']] ?? $a['status'];
        $pri = $PRIORITIES[$a['priority']] ?? '';
        $t .= "• <code>{$a['appeal_id']}</code> $s $pri\n  " . htmlspecialchars(mb_substr($a['subject'], 0, 50)) . "\n\n";
        $buttons[] = [btn("📋 " . $a['appeal_id'], "view_" . $a['appeal_id'])];
    }
    send($chatId, $t, inlineKb(array_slice($buttons, 0, 10)));
    return;
}

if ($text === '/notify' && $user['role'] === 'operator') {
    $current = $user['notify'];
    $new = $current ? 0 : 1;
    $pdo->prepare("UPDATE bot_users SET notify = ? WHERE chat_id = ?")->execute([$new, $chatId]);
    send($chatId, $new ? "🔔 Уведомления <b>включены</b>." : "🔕 Уведомления <b>выключены</b>.", operatorMenu());
    return;
}


// ── КОММЕНТАРИЙ ОПЕРАТОРА ──────────────────────────────
if ($st['state'] === 'await_comment' && $user['role'] === 'operator') {
    $appealId = $st['data']['appeal_id'] ?? '';
    $uid = $user['user_id'];

    if ($uid && $appealId) {
        $pdo->prepare("INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES ((SELECT id FROM appeals WHERE appeal_id = ?), ?, ?, NOW())")
            ->execute([$appealId, $uid, $text]);

        if ($uid) {
            $pdo->prepare("INSERT INTO activity_log (user_id, action, appeal_id, detail, created_at) VALUES (?, 'chat', ?, ?, NOW())")
                ->execute([$uid, $appealId, mb_substr($text, 0, 100)]);
        }
    }

    clearState($chatId);
    send($chatId, "💬 Комментарий добавлен к <b>$appealId</b>.", operatorMenu());

    // Уведомить отправителя
    $ap = $pdo->prepare("SELECT sender_chat_id FROM appeals WHERE appeal_id = ?");
    $ap->execute([$appealId]);
    $senderChat = $ap->fetchColumn();
    if ($senderChat) {
        send($senderChat, "💬 Новый комментарий к вашему сообщению <b>$appealId</b>:\n\n" . htmlspecialchars(mb_substr($text, 0, 500)));
    }
    return;
}

// ── НЕИЗВЕСТНАЯ КОМАНДА / СВОБОДНЫЙ ТЕКСТ ──────────────
$menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
send($chatId, "❓ Не понимаю. Используйте кнопки ниже или /start", $menu);

} // end processUpdate()

// ── WEBHOOK MODE (когда вызывается напрямую) ──────────
if (!defined('BOT_POLL_MODE')) {
    // Секрет читается из bot_webhook_secret.txt (не коммитится)
    $__secretFile = __DIR__ . '/bot_webhook_secret.txt';
    $expectedSecret = file_exists($__secretFile) ? trim(file_get_contents($__secretFile)) : '';
    $gotSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if ($expectedSecret === '' || !hash_equals($expectedSecret, $gotSecret)) {
        http_response_code(403);
        exit('forbidden');
    }

    // Читаем input ДО любых заголовков
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);

    // Отвечаем Telegram 200 OK
    http_response_code(200);
    header('Content-Type: text/plain');
    echo 'ok';

    if ($update) {
        try { processUpdate($update); }
        catch (Exception $e) { error_log('Bot webhook error: ' . $e->getMessage()); }
    }
}
