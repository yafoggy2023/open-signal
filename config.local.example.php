<?php
/**
 * ШАБЛОН локальной конфигурации.
 *
 * Этот файл (*.example.php) — шаблон, коммитится в git.
 * Реальный файл config.local.php — в .gitignore и никогда не попадает в репо.
 *
 * УСТАНОВКА (один раз на каждой машине):
 *
 *   cp config.local.example.php config.local.php
 *   nano config.local.php   # впишите реальные креды БД и путь к mysqldump
 *
 * После этого обновления через git pull больше не будут затирать пароль.
 */

// ── ПОДКЛЮЧЕНИЕ К БД ─────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'fsb_portal');
define('DB_USER', 'opensignal');      // локально на XAMPP обычно 'root'
define('DB_PASS', 'ВПИШИТЕ_ПАРОЛЬ');  // локально на XAMPP обычно ''

// ── ПУТЬ К mysqldump (для backup.php) ────────────────────
// Linux (VPS):     '/usr/bin/mysqldump'
// Windows (XAMPP): 'C:\\xampp\\mysql\\bin\\mysqldump.exe'
define('MYSQLDUMP', '/usr/bin/mysqldump');
