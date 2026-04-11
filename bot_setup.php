<?php
/**
 * Установка webhook для Telegram-бота
 * Запустить один раз: php bot_setup.php
 */

// Читаем токен
$tokenFile = __DIR__ . '/bot_token.txt';
if (!file_exists($tokenFile)) {
    echo "Создайте файл bot_token.txt с токеном бота!\n";
    exit(1);
}
$token = trim(file_get_contents($tokenFile));
if (!$token || $token === 'ВСТАВЬ_ТОКЕН_СЮДА') {
    echo "Вставьте токен бота в bot_token.txt!\n";
    exit(1);
}

// URL вашего бота (HTTPS обязателен для production!)
// Для локальной разработки используйте ngrok
$webhookUrl = $argv[1] ?? '';
if (!$webhookUrl) {
    echo "Использование: php bot_setup.php https://your-domain.com/bot.php\n";
    echo "\nДля локальной разработки:\n";
    echo "  1. Установите ngrok: https://ngrok.com\n";
    echo "  2. Запустите: ngrok http 80\n";
    echo "  3. Используйте полученный HTTPS URL:\n";
    echo "     php bot_setup.php https://xxxx.ngrok-free.app/fsb/bot.php\n";
    exit(1);
}

// Установка webhook
$url = "https://api.telegram.org/bot$token/setWebhook?url=" . urlencode($webhookUrl);
$result = json_decode(file_get_contents($url), true);

if ($result['ok'] ?? false) {
    echo "✅ Webhook установлен: $webhookUrl\n";

    // Проверяем info
    $info = json_decode(file_get_contents("https://api.telegram.org/bot$token/getWebhookInfo"), true);
    echo "URL: " . ($info['result']['url'] ?? '-') . "\n";
    echo "Pending updates: " . ($info['result']['pending_update_count'] ?? 0) . "\n";
} else {
    echo "❌ Ошибка: " . ($result['description'] ?? 'unknown') . "\n";
}
