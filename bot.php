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
define('DB_HOST', 'localhost');
define('DB_NAME', 'fsb_portal');
define('DB_USER', 'root');
define('DB_PASS', '');
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
        CURLOPT_TIMEOUT => $timeout
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

// ── ПОЛЬЗОВАТЕЛЬ БОТА ──────────────────────────────────
function getBotUser($chatId) {
    global $pdo;
    $s = $pdo->prepare("SELECT * FROM bot_users WHERE chat_id = ?");
    $s->execute([$chatId]);
    return $s->fetch();
}

function ensureBotUser($chatId, $username = null) {
    global $pdo;
    $u = getBotUser($chatId);
    if (!$u) {
        $pdo->prepare("INSERT INTO bot_users (chat_id, username) VALUES (?, ?)")->execute([$chatId, $username]);
        return getBotUser($chatId);
    }
    if ($username && $u['username'] !== $username) {
        $pdo->prepare("UPDATE bot_users SET username = ? WHERE chat_id = ?")->execute([$username, $chatId]);
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

    // Безопасное имя файла
    $safeName = preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ._\-]/u', '_', $fileName);
    $saveName = $appealDbId . '_' . time() . '_' . $safeName;
    $savePath = __DIR__ . '/uploads/' . $saveName;

    // Создаём папку если нет
    if (!is_dir(__DIR__ . '/uploads/')) {
        mkdir(__DIR__ . '/uploads/', 0755, true);
    }

    $content = @file_get_contents($url);
    if ($content === false) return false;

    file_put_contents($savePath, $content);

    // Сохраняем в БД
    $pdo->prepare("INSERT INTO appeal_files (appeal_db_id, filename, filesize, uploaded_at, av_status) VALUES (?, ?, ?, NOW(), 'clean')")
        ->execute([$appealDbId, $saveName, $fileSize]);

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

// ── КАТЕГОРИИ ──────────────────────────────────────────
$CATEGORIES = [
    'corruption' => 'Коррупция',
    'fraud' => 'Мошенничество',
    'abuse' => 'Злоупотребление полномочиями',
    'cyber' => 'Киберпреступления',
    'safety' => 'Общественная безопасность',
    'personal' => 'Против личности',
    'docs' => 'Подделка документов',
    'other' => 'Иные нарушения'
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
    $user = ensureBotUser($chatId, $cb['from']['username'] ?? null);

    tg('answerCallbackQuery', ['callback_query_id' => $cb['id']]);

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
        editMsg($chatId, $msgId, "✅ Условия приняты.\n\n📝 <b>Введите тему сообщения</b> (одной строкой):", null);
        send($chatId, "Для отмены нажмите кнопку ниже.", replyKb([[['text' => '❌ Отмена']]], true, false));
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
            setState($chatId, 'await_message', $d);
            $cancelKb = replyKb([[['text' => '❌ Отмена']]], true, false);
            send($chatId, "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.", $cancelKb);
        }
        return;
    }

    // Выбор категории при создании сообщения
    if (strpos($data, 'cat_') === 0) {
        $cat = substr($data, 4);
        $st = getState($chatId);
        if ($st['state'] === 'await_category') {
            $d = $st['data'];
            $d['category'] = $CATEGORIES[$cat] ?? $cat;
            setState($chatId, 'await_priority', $d);
            $rows = [];
            foreach ($PRIORITIES as $k => $v) {
                $rows[] = [btn($v, "pri_$k")];
            }
            editMsg($chatId, $msgId, "📌 <b>Выберите срочность:</b>", inlineKb($rows));
        }
        return;
    }

    // Выбор приоритета
    if (strpos($data, 'pri_') === 0) {
        $pri = substr($data, 4);
        $st = getState($chatId);
        if ($st['state'] === 'await_priority') {
            $d = $st['data'];
            $d['priority'] = $pri;
            setState($chatId, 'await_organ', $d);
            editMsg($chatId, $msgId, "🏢 <b>Укажите орган/организацию</b>\n\nНа кого направлено сообщение?\nНапример: Администрация г. Москвы\n\nВведите текстом или нажмите «Пропустить».", null);
            send($chatId, "⬇️", replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]], true, false));
        }
        return;
    }

    // Выбор анонимности
    if (strpos($data, 'anon_') === 0 ) {
        $st = getState($chatId);
        if ($st['state'] === 'await_anon') {
            $d = $st['data'];
            if ($data === 'anon_yes') {
                $d['is_anon'] = 1;
                setState($chatId, 'await_message', $d);
                editMsg($chatId, $msgId, "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.", null);
            } else {
                $d['is_anon'] = 0;
                setState($chatId, 'await_contact_name', $d);
                editMsg($chatId, $msgId, "👤 <b>Введите ФИО:</b>\n\nНапример: Иванов Иван Иванович\n\n<i>Указывая данные, вы даёте согласие на их обработку в рамках платформы «Открытый сигнал».</i>", null);
            }
        }
        return;
    }

    // Пропустить файлы / Готово — сохранить сообщение
    if ($data === 'skip_files' || $data === 'done_files') {
        $st = getState($chatId);
        if ($st['state'] === 'await_files') {
            $d = $st['data'];
            $appealId = genAppealId();
            $isAnon = !empty($d['is_anon']) ? 1 : 0;
            $contactJson = null;
            // Определяем username отправителя
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
                // Автоматически сохраняем аккаунт отправителя
                if ($senderUsername) $contact['telegram_sender'] = '@' . $senderUsername;
                $contactJson = json_encode($contact, JSON_UNESCAPED_UNICODE);
            } else {
                // Даже для анонимного — сохраняем username отправителя (виден только операторам)
                if ($senderUsername) {
                    $contactJson = json_encode(['telegram_sender' => '@' . $senderUsername], JSON_UNESCAPED_UNICODE);
                }
            }

            $msgText = $d['message_text'] ?? '';
            $stmt = $pdo->prepare("INSERT INTO appeals (appeal_id, subject, category, priority, organ, location, event_date, status, is_anon, contact_json, message, sender_chat_id, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, 'telegram', NOW())");
            $stmt->execute([$appealId, $d['subject'], $d['category'], $d['priority'], $d['organ'] ?? null, $d['location'] ?? null, $d['event_date'] ?? null, $isAnon, $contactJson, $msgText, $chatId]);
            $appealDbId = $pdo->lastInsertId();

            // Автосвязка повторных обращений
            $linkedCount = autoLinkAppeals($appealId, $contactJson, $chatId);

            // Скачиваем и сохраняем файлы
            $files = $d['files'] ?? [];
            $savedFiles = 0;
            foreach ($files as $f) {
                $saved = downloadTgFile($f['file_id'], $f['file_name'], $appealDbId);
                if ($saved) $savedFiles++;
            }

            clearState($chatId);

            $contactInfo = $isAnon ? '🔒 Анонимно' : '👤 ' . ($d['contact_name'] ?? '');
            $fileInfo = $savedFiles > 0 ? "\n📎 Файлов: $savedFiles" : '';
            $linkInfo = $linkedCount > 0 ? "\n🔗 Повторное обращение ($linkedCount связ.)" : '';

            $menu = $user['role'] === 'operator' ? operatorMenu() : senderMenu();
            $organInfo = !empty($d['organ']) ? "\n🏛 Орган: " . htmlspecialchars($d['organ']) : '';
            $locationInfo = !empty($d['location']) ? "\n📍 Место: " . htmlspecialchars($d['location']) : '';
            $dateInfo = !empty($d['event_date']) ? "\n📅 Дата события: " . $d['event_date'] : (!empty($d['event_date_raw']) ? "\n📅 Дата события: " . htmlspecialchars($d['event_date_raw']) : '');

            // Кнопка карты если есть место
            $confirmKb = null;
            if (!empty($d['location'])) {
                $mapUrl = 'https://yandex.ru/maps/?text=' . urlencode($d['location']);
                $confirmKb = inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]);
            }

            editMsg($chatId, $msgId, "✅ <b>Сообщение отправлено!</b>\n\n"
                . "📋 Номер: <code>$appealId</code>\n"
                . "📌 Тема: " . htmlspecialchars($d['subject']) . "\n"
                . "📂 Категория: " . htmlspecialchars($d['category']) . "\n"
                . "⚡ Приоритет: " . ($PRIORITIES[$d['priority']] ?? $d['priority'])
                . "$organInfo$locationInfo$dateInfo\n"
                . "$contactInfo$fileInfo$linkInfo\n\n"
                . "<i>Данная платформа не является государственным ресурсом.</i>", $confirmKb);
            // Отправляем меню отдельным сообщением
            send($chatId, "📋 Главное меню:", $menu);

            // Уведомить операторов
            $notify = "🔔 <b>Новое сообщение!</b>\n\n"
                . "📋 <b>$appealId</b>\n"
                . "📌 " . htmlspecialchars($d['subject']) . "\n"
                . "📂 " . htmlspecialchars($d['category']) . "\n"
                . "⚡ " . ($PRIORITIES[$d['priority']] ?? $d['priority']) . "\n"
                . "$contactInfo$fileInfo$linkInfo\n\n"
                . mb_substr(htmlspecialchars($msgText), 0, 200) . (mb_strlen($msgText) > 200 ? '...' : '');

            $ops = $pdo->query("SELECT chat_id FROM bot_users WHERE role = 'operator' AND notify = 1")->fetchAll();
            foreach ($ops as $op) {
                if ($op['chat_id'] != $chatId) {
                    send($op['chat_id'], $notify, inlineKb([[btn("📋 Открыть", "view_$appealId")]]));
                }
            }
        }
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
            . "📂 <b>Категория:</b> " . htmlspecialchars($a['category']) . "\n"
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
            . "📂 <b>Категория:</b> " . htmlspecialchars($a['category']) . "\n"
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
$user = ensureBotUser($chatId, $username);
$st = getState($chatId);

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

// ── КНОПКИ МЕНЮ ──────────────────────────────────────
if ($text === '📨 Отправить сообщение') { $text = '/send'; }
if ($text === '🔍 Мои сообщения')       { $text = '/status'; }
if ($text === '🆕 Новые')               { $text = '/new'; }
if ($text === '📋 Активные')            { $text = '/list'; }
if ($text === '🔔 Уведомления')         { $text = '/notify'; }
if ($text === '📊 Статистика' && $user['role'] === 'operator') {
    $total = $pdo->query("SELECT COUNT(*) FROM appeals")->fetchColumn();
    $new   = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status='new'")->fetchColumn();
    $proc  = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status IN ('process','review')")->fetchColumn();
    $done  = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status='done'")->fetchColumn();
    $rej   = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status='rejected'")->fetchColumn();
    send($chatId, "📊 <b>Статистика платформы</b>\n\n"
        . "📋 Всего: <b>$total</b>\n"
        . "🆕 Новые: <b>$new</b>\n"
        . "🔄 В работе: <b>$proc</b>\n"
        . "✅ Закрыто: <b>$done</b>\n"
        . "❌ Отклонено: <b>$rej</b>", operatorMenu());
    return;
}

// ── /myid — показать chat_id для привязки ─────────────
if ($text === '/myid') {
    send($chatId, "🆔 Ваш Telegram ID:\n\n<code>$chatId</code>\n\nСообщите этот ID администратору, чтобы он привязал ваш аккаунт в админ-панели сайта.", $user['role'] === 'operator' ? operatorMenu() : senderMenu());
    return;
}

// ── /send — создание сообщения ─────────────────────────
if ($text === '/send') {
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
    setState($chatId, 'await_category', ['subject' => $text]);
    $rows = [];
    foreach ($CATEGORIES as $k => $v) {
        $rows[] = [btn($v, "cat_$k")];
    }
    send($chatId, "📂 <b>Выберите категорию:</b>", inlineKb($rows));
    return;
}

// ── Орган, Место, Дата события ────────────────────────
if ($st['state'] === 'await_organ') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить') {
        $d['organ'] = '';
    } else {
        $d['organ'] = $text;
    }
    setState($chatId, 'await_location', $d);
    $locKb = ['keyboard' => [
        [['text' => '🗺 Указать на карте', 'web_app' => ['url' => MAP_PICKER_URL]]],
        [['text' => '📍 Отправить геолокацию', 'request_location' => true]],
        [['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]
    ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    send($chatId, "📍 <b>Место события</b>\n\nГде произошло? Укажите адрес, город или регион.\n\n"
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
            $lat = $webData['lat'];
            $lon = $webData['lon'];
            $mapUrl = "https://yandex.ru/maps/?pt=$lon,$lat&z=16&l=map";
            send($chatId, "📍 Место выбрано на карте:\n<b>" . htmlspecialchars($webData['address'], ENT_QUOTES, 'UTF-8') . "</b>",
                inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]));
            setState($chatId, 'await_event_date', $d);
            send($chatId, "📅 <b>Дата события</b>\n\nКогда произошло? Укажите дату.\nНапример: 05.03.2026 или март 2026\n\nВведите текстом или нажмите «Пропустить».",
                replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]], true, false));
            return;
        }
    }

    // Геолокация через кнопку
    if (isset($msg['location'])) {
        $lat = $msg['location']['latitude'];
        $lon = $msg['location']['longitude'];
        // Обратное геокодирование через Nominatim (OSM, бесплатно)
        $geoUrl = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1&accept-language=ru";
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: OpenSignalBot/1.0\r\n", 'timeout' => 5]]);
        $geoJson = @file_get_contents($geoUrl, false, $ctx);
        $address = '';
        if ($geoJson) {
            $geo = json_decode($geoJson, true);
            $address = $geo['display_name'] ?? '';
        }
        if (!$address) {
            $address = "$lat, $lon";
        }
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
        // Кнопка "Показать на карте"
        $mapUrl = 'https://yandex.ru/maps/?text=' . urlencode($text);
        send($chatId, "📍 Место: <b>" . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</b>",
            inlineKb([[['text' => '🗺 Показать на карте', 'url' => $mapUrl]]]));
    }
    setState($chatId, 'await_event_date', $d);
    send($chatId, "📅 <b>Дата события</b>\n\nКогда произошло? Укажите дату.\nНапример: 05.03.2026 или март 2026\n\nВведите текстом или нажмите «Пропустить».",
        replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]], true, false));
    return;
}

if ($st['state'] === 'await_event_date') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить') {
        $d['event_date'] = null;
    } else {
        // Пытаемся распарсить дату
        $parsed = null;
        // Формат ДД.ММ.ГГГГ
        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $text, $m)) {
            $parsed = $m[3] . '-' . str_pad($m[2], 2, '0', STR_PAD_LEFT) . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        // Формат ГГГГ-ММ-ДД
        elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $parsed = $text;
        }
        $d['event_date'] = $parsed;
        $d['event_date_raw'] = $text;
    }
    setState($chatId, 'await_anon', $d);
    send($chatId, "👤 <b>Как отправить сообщение?</b>\n\n"
        . "🔒 <b>Анонимно</b> — ваши личные данные не сохраняются. Мы не сможем связаться с вами для уточнения деталей.\n\n"
        . "👤 <b>С указанием данных</b> — ФИО и контакты нужны для связи с вами и выяснения обстоятельств. "
        . "Данные используются исключительно в рамках платформы и не передаются третьим лицам.",
        inlineKb([
            [btn('🔒 Анонимно', 'anon_yes')],
            [btn('👤 Указать свои данные', 'anon_no')]
        ]));
    return;
}

// ── Контактные данные (не анонимно) ───────────────────
if ($st['state'] === 'await_contact_name') {
    if (mb_strlen($text) < 3) { send($chatId, "⚠ Введите ФИО (минимум 3 символа)."); return; }
    $d = $st['data'];
    $d['contact_name'] = $text;
    setState($chatId, 'await_contact_phone', $d);
    // Кнопка "Поделиться номером" + "Пропустить"
    $phoneKb = ['keyboard' => [
        [['text' => '📞 Отправить мой номер', 'request_contact' => true]],
        [['text' => '⏭ Пропустить']]
    ], 'resize_keyboard' => true, 'one_time_keyboard' => true];
    send($chatId, "📞 <b>Номер телефона</b>\n\nНажмите кнопку ниже, чтобы отправить свой номер, или введите вручную:", $phoneKb);
    return;
}

if ($st['state'] === 'await_contact_phone') {
    // Проверяем, прислали ли контакт через кнопку
    if (isset($msg['contact'])) {
        $d = $st['data'];
        $d['contact_phone'] = '+' . $msg['contact']['phone_number'];
        setState($chatId, 'await_contact_email', $d);
        $cancelKb = replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]], true, false);
        send($chatId, "✅ Номер принят: <b>" . $d['contact_phone'] . "</b>\n\n📧 <b>Введите email</b> или нажмите «Пропустить»:", $cancelKb);
        return;
    }
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_phone'] = '';
    } else {
        $d['contact_phone'] = $text;
    }
    setState($chatId, 'await_contact_email', $d);
    $cancelKb = replyKb([[['text' => '⏭ Пропустить'], ['text' => '❌ Отмена']]], true, false);
    send($chatId, "📧 <b>Введите email</b> или нажмите «Пропустить»:", $cancelKb);
    return;
}

if ($st['state'] === 'await_contact_email') {
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_email'] = '';
    } else {
        $d['contact_email'] = $text;
    }
    $autoTg = $username ? '@' . $username : '';
    $d['contact_telegram_auto'] = $autoTg;
    setState($chatId, 'await_contact_telegram', $d);
    // Inline-кнопки для Telegram
    $buttons = [];
    if ($autoTg) {
        $buttons[] = [btn("✅ Отправить $autoTg", 'tg_use_auto')];
    }
    $buttons[] = [btn('⏭ Пропустить', 'tg_skip')];
    $msg_text = "💬 <b>Telegram для связи</b>\n\n";
    if ($autoTg) {
        $msg_text .= "Ваш аккаунт: <b>$autoTg</b>\nНажмите кнопку, чтобы подтвердить, или введите другой @username:";
    } else {
        $msg_text .= "Введите ваш @username или нажмите «Пропустить»:";
    }
    send($chatId, $msg_text, inlineKb($buttons));
    return;
}

if ($st['state'] === 'await_contact_telegram') {
    // Текстовый ввод — значит ввели вручную
    $d = $st['data'];
    if ($text === '⏭ Пропустить' || $text === '-' || $text === '—') {
        $d['contact_telegram'] = '';
    } else {
        $d['contact_telegram'] = $text;
    }
    setState($chatId, 'await_message', $d);
    $cancelKb = replyKb([[['text' => '❌ Отмена']]], true, false);
    send($chatId, "✏️ <b>Напишите текст сообщения:</b>\n\nОпишите ситуацию подробно.", $cancelKb);
    return;
}

// ── Текст сообщения ───────────────────────────────────
if ($st['state'] === 'await_message') {
    if (mb_strlen($text) < 20) { send($chatId, "⚠ Текст слишком короткий. Минимум 20 символов."); return; }
    $d = $st['data'];
    $d['message_text'] = $text;
    $d['files'] = [];
    setState($chatId, 'await_files', $d);
    send($chatId, "📎 <b>Прикрепите файлы или фото</b> (до 5 шт.)\n\nМожно отправить несколько. Когда закончите, нажмите кнопку ниже.",
        inlineKb([[btn('✅ Отправить без файлов', 'skip_files'), btn('✅ Готово', 'done_files')]]));
    return;
}

// ── Приём файлов/фото ─────────────────────────────────
if ($st['state'] === 'await_files') {
    // Получили файл или фото
    $fileId = null;
    $fileName = null;

    if (isset($msg['photo'])) {
        // Берём самое большое фото
        $photo = end($msg['photo']);
        $fileId = $photo['file_id'];
        $fileName = 'photo_' . time() . '_' . rand(100,999) . '.jpg';
    } elseif (isset($msg['document'])) {
        $fileId = $msg['document']['file_id'];
        $fileName = $msg['document']['file_name'] ?? ('file_' . time());
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
        send($chatId, "📎 Файл принят (" . count($files) . "/5). Отправьте ещё или нажмите <b>Готово</b>.",
            inlineKb([[btn('✅ Готово, отправить', 'done_files')]]));
        return;
    }

    // Если прислали текст — предупреждаем
    if ($text && $text !== '❌ Отмена') {
        send($chatId, "⚠ Сейчас ожидаются файлы/фото. Отправьте файл или нажмите кнопку.",
            inlineKb([[btn('✅ Отправить без файлов', 'skip_files'), btn('✅ Готово', 'done_files')]]));
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
        . "📂 <b>Категория:</b> " . htmlspecialchars($a['category']) . "\n"
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
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    if ($update) processUpdate($update);
}
