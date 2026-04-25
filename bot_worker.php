<?php
/**
 * Фоновый воркер обработки одного webhook-update.
 * Запускается из webhook через exec в background.
 * Аргумент: путь к JSON-файлу с update.
 */
if (php_sapi_name() !== 'cli') { exit; }
if (empty($argv[1]) || !file_exists($argv[1])) { exit; }

define('BOT_POLL_MODE', true); // не запускать webhook-блок в bot.php
require_once __DIR__ . '/bot.php';

$updateFile = $argv[1];
$update = json_decode(file_get_contents($updateFile), true);
@unlink($updateFile);

if (!$update) { exit; }

$logFile = __DIR__ . '/bot_webhook.log';
$startTime = microtime(true);
$updateId = $update['update_id'] ?? 0;

try {
    processUpdate($update);
    $ms = round((microtime(true) - $startTime) * 1000);
    file_put_contents($logFile,
        date('Y-m-d H:i:s') . " WORKER OK upd=$updateId in {$ms}ms\n",
        FILE_APPEND);
} catch (Exception $e) {
    $ms = round((microtime(true) - $startTime) * 1000);
    file_put_contents($logFile,
        date('Y-m-d H:i:s') . " WORKER ERROR upd=$updateId in {$ms}ms: " . $e->getMessage() . "\n",
        FILE_APPEND);
    error_log('Bot worker error: ' . $e->getMessage());
}
