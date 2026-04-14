<?php
/**
 * Регистрация webhook у Telegram.
 * Запуск: php bot_webhook_set.php https://opensignal.ru/bot.php
 * Удалить: php bot_webhook_set.php --delete
 */
define('BOT_POLL_MODE', true); // чтобы bot.php не пытался обработать псевдо-update
require_once __DIR__ . '/bot.php';

if (($argv[1] ?? '') === '--delete') {
    $r = tg('deleteWebhook', ['drop_pending_updates' => true]);
    print_r($r);
    exit;
}

$url = $argv[1] ?? '';
if (!$url || !preg_match('~^https://~', $url)) {
    die("Usage: php bot_webhook_set.php https://your-domain/bot.php\n");
}

$secretFile = __DIR__ . '/bot_webhook_secret.txt';
if (!file_exists($secretFile)) {
    $secret = bin2hex(random_bytes(24));
    file_put_contents($secretFile, $secret);
    chmod($secretFile, 0640);
    echo "Сгенерирован секрет: $secret\n";
} else {
    $secret = trim(file_get_contents($secretFile));
    echo "Используется существующий секрет из bot_webhook_secret.txt\n";
}

$r = tg('setWebhook', [
    'url' => $url,
    'secret_token' => $secret,
    'allowed_updates' => ['message', 'callback_query'],
    'drop_pending_updates' => true,
    'max_connections' => 40,
]);
print_r($r);

echo "\n--- getWebhookInfo ---\n";
print_r(tg('getWebhookInfo'));
