<?php
/**
 * Скрипт автоматического backup БД
 *
 * Запуск:
 *   - Вручную:  php backup.php
 *   - По расписанию (Windows Task Scheduler):
 *       schtasks /create /tn "FSB Portal Backup" /tr "C:\xampp\php\php.exe C:\xampp\htdocs\fsb\backup.php" /sc daily /st 03:00
 *   - cron (Linux):
 *       0 3 * * * /usr/bin/php /var/www/fsb/backup.php
 */

// ── НАСТРОЙКИ ─────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'fsb_portal');
define('DB_USER', 'opensignal');
define('DB_PASS', 'CHANGE_ME_ON_DEPLOY'); // заменить на VPS после git pull
define('BACKUP_DIR', __DIR__ . '/backups/');
define('KEEP_DAYS', 30); // Хранить дампы последние 30 дней
define('MYSQLDUMP', '/usr/bin/mysqldump'); // Linux. Windows XAMPP: 'C:\\xampp\\mysql\\bin\\mysqldump.exe'

// ── СОЗДАЁМ ПАПКУ ─────────────────────────────────
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0750, true);
}
// Защита от веб-доступа
$htaccess = BACKUP_DIR . '.htaccess';
if (!file_exists($htaccess)) file_put_contents($htaccess, "Deny from all\n");

// ── ИМЯ ФАЙЛА ─────────────────────────────────────
$timestamp = date('Y-m-d_H-i-s');
$dumpFile  = BACKUP_DIR . "fsb_portal_{$timestamp}.sql";
$gzFile    = $dumpFile . '.gz';

// ── ВЫПОЛНЕНИЕ DUMP ───────────────────────────────
$cmd = sprintf(
    '"%s" --host=%s --user=%s %s --default-character-set=utf8mb4 --routines --triggers --single-transaction %s > "%s" 2>&1',
    MYSQLDUMP,
    escapeshellarg(DB_HOST),
    escapeshellarg(DB_USER),
    DB_PASS ? '--password=' . escapeshellarg(DB_PASS) : '',
    escapeshellarg(DB_NAME),
    $dumpFile
);

echo "[" . date('Y-m-d H:i:s') . "] Создаю backup...\n";
exec($cmd, $out, $code);

if ($code !== 0 || !file_exists($dumpFile) || filesize($dumpFile) < 1024) {
    echo "[ОШИБКА] mysqldump провалился (код $code)\n";
    echo implode("\n", $out) . "\n";
    if (file_exists($dumpFile)) unlink($dumpFile);
    exit(1);
}

$size = filesize($dumpFile);
echo "[OK] SQL-дамп создан: " . basename($dumpFile) . " (" . round($size/1024, 1) . " КБ)\n";

// ── СЖАТИЕ ────────────────────────────────────────
if (function_exists('gzopen')) {
    $in  = fopen($dumpFile, 'rb');
    $out = gzopen($gzFile, 'wb9');
    while (!feof($in)) gzwrite($out, fread($in, 8192));
    fclose($in); gzclose($out);
    unlink($dumpFile);
    echo "[OK] Сжато: " . basename($gzFile) . " (" . round(filesize($gzFile)/1024, 1) . " КБ)\n";
}

// ── ОЧИСТКА СТАРЫХ ────────────────────────────────
$cutoff = time() - KEEP_DAYS * 86400;
$removed = 0;
foreach (glob(BACKUP_DIR . 'fsb_portal_*.sql*') as $f) {
    if (filemtime($f) < $cutoff) {
        unlink($f);
        $removed++;
    }
}
if ($removed) echo "[OK] Удалено старых дампов: $removed\n";

echo "[" . date('Y-m-d H:i:s') . "] Готово.\n";
