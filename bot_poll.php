<?php
/**
 * Telegram-бот — Открытый сигнал
 * Long Polling режим (без webhook)
 *
 * Запуск: php bot_poll.php
 * Остановка: Ctrl+C
 */

define('BOT_POLL_MODE', true);
require_once __DIR__ . '/bot.php';

// Удаляем webhook если был
tg('deleteWebhook');

echo "=== Открытый сигнал — Telegram бот ===\n";
echo "Long polling запущен...\n";
echo "Нажмите Ctrl+C для остановки\n\n";

$offset = 0;
$consecutiveFails = 0; // счётчик подряд идущих сетевых сбоев (для затихания в лог)

while (true) {
    // Long-poll: Telegram держит соединение до 25 сек, ждём ответа до 40 сек.
    // Буфер 15 сек защищает от срабатывания cURL-таймаута раньше, чем ответит сервер.
    $result = tg('getUpdates', [
        'offset'  => $offset,
        'timeout' => 25,
        'allowed_updates' => ['message', 'callback_query']
    ], 40);

    // ── Случай 1: успех, есть обновления ─────────────────
    if (!empty($result['ok']) && !empty($result['result'])) {
        $consecutiveFails = 0;
        foreach ($result['result'] as $update) {
            $offset = $update['update_id'] + 1;

            // Логируем
            $type = isset($update['callback_query']) ? 'callback' : 'message';
            $from = '';
            if ($type === 'message') {
                $from = $update['message']['from']['username'] ?? $update['message']['from']['first_name'] ?? '?';
                $txt = mb_substr($update['message']['text'] ?? '[media]', 0, 50);
                echo date('H:i:s') . " [$from] $txt\n";
            } else {
                $from = $update['callback_query']['from']['username'] ?? '?';
                echo date('H:i:s') . " [$from] callback: " . ($update['callback_query']['data'] ?? '') . "\n";
            }

            try {
                processUpdate($update);
            } catch (Exception $e) {
                echo date('H:i:s') . " ERROR: " . $e->getMessage() . "\n";
            }
        }
        continue;
    }

    // ── Случай 2: успех, но нет обновлений (нормальный idle) ──
    if (!empty($result['ok'])) {
        $consecutiveFails = 0;
        continue; // сразу новый запрос, никакого sleep'а
    }

    // ── Случай 3: реальная ошибка Telegram API (ok:false с кодом) ──
    if (is_array($result) && isset($result['error_code'])) {
        $consecutiveFails = 0;
        echo date('H:i:s') . " API error [" . $result['error_code'] . "]: "
            . ($result['description'] ?? '') . " — retry in 5s\n";
        sleep(5);
        continue;
    }

    // ── Случай 4: cURL-таймаут или сеть (tg() вернула null) ──
    // Сообщения не теряются — offset не сдвигается, Telegram отдаст их в следующем getUpdates.
    // Логируем только если сбоев подряд много (реальные проблемы с сетью),
    // одиночные таймауты глотаем молча.
    $consecutiveFails++;
    if ($consecutiveFails >= 3) {
        echo date('H:i:s') . " Network issue ({$consecutiveFails} consecutive timeouts), retry in 5s\n";
        sleep(5);
    } else {
        sleep(1);
    }
}
