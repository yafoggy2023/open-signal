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

while (true) {
    $result = tg('getUpdates', [
        'offset'  => $offset,
        'timeout' => 30,
        'allowed_updates' => ['message', 'callback_query']
    ], 35);

    if (!empty($result['ok']) && !empty($result['result'])) {
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
    }

    if (empty($result['ok'])) {
        echo date('H:i:s') . " API error, retry in 5s...\n";
        sleep(5);
    }
}
