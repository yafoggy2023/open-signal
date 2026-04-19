<?php
/**
 * Ежедневный дайджест — рассылка операторам в 9:00
 * Запуск: php bot_digest.php
 * Cron: 0 9 * * * php /var/www/open-signal/bot_digest.php >> /var/log/bot_digest.log 2>&1
 */
define('BOT_POLL_MODE', true);
require_once __DIR__ . '/bot.php';

$since = date('Y-m-d 00:00:00', strtotime('-1 day'));
$until = date('Y-m-d 00:00:00');

$stmt = $pdo->prepare("
    SELECT appeal_id, subject, category, priority, is_anon, created_at
    FROM appeals
    WHERE created_at >= ? AND created_at < ?
    ORDER BY FIELD(priority,'critical','high','medium','low'), created_at DESC
");
$stmt->execute([$since, $until]);
$appeals = $stmt->fetchAll();

$date = date('d.m.Y', strtotime('-1 day'));

if (!$appeals) {
    $text = "📊 <b>Дайджест за $date</b>\n\n📭 Новых сообщений не поступало.";
} else {
    $cnt = count($appeals);
    $text = "📊 <b>Дайджест за $date</b>\n\n📬 Поступило сообщений: <b>$cnt</b>\n\n";
    foreach ($appeals as $a) {
        $pri = ['critical' => '🔴', 'high' => '🟠', 'medium' => '🟡', 'low' => '🟢'][$a['priority']] ?? '⚪';
        $anon = $a['is_anon'] ? ' 🔒' : '';
        $time = substr($a['created_at'], 11, 5);
        $text .= "$pri <code>{$a['appeal_id']}</code>$anon $time\n"
               . "   " . htmlspecialchars(mb_substr($a['subject'], 0, 60)) . "\n\n";
    }
}

$ops = $pdo->query("SELECT chat_id FROM bot_users WHERE role = 'operator' AND notify = 1")->fetchAll();
foreach ($ops as $op) {
    $r = send($op['chat_id'], $text);
    $ok = !empty($r['ok']) ? 'OK' : 'FAIL';
    echo date('Y-m-d H:i:s') . " digest → {$op['chat_id']}: $ok\n";
}

echo date('Y-m-d H:i:s') . " digest done. Appeals: " . count($appeals) . ", operators: " . count($ops) . "\n";
