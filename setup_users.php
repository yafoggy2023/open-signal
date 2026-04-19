<?php
/**
 * Одноразовый скрипт настройки пользователей.
 * Запуск: php setup_users.php
 * УДАЛИТЬ после выполнения!
 */
define('BOT_POLL_MODE', true);
require_once __DIR__ . '/bot.php';

$ops = [
    // Обновить существующих (ищем по фамилии)
    ['action' => 'update', 'last_name' => 'Ковалёв',
        'first_name' => 'Евгений', 'middle_name' => 'Сергеевич'],

    ['action' => 'update', 'last_name' => 'Иванов',
        'first_name' => 'Андрей', 'middle_name' => 'Андреевич',
        'login' => 'a.ivanov', 'password' => 'trunov33'],

    // Добавить новых
    ['action' => 'insert', 'last_name' => 'Ибрагимов',
        'first_name' => 'Никита', 'middle_name' => 'Сергеевич',
        'login' => 'n.ibragimov', 'password' => 'ildemen39', 'role' => 'duty'],

    ['action' => 'insert', 'last_name' => 'Высоцкий',
        'first_name' => 'Игорь', 'middle_name' => 'Валерьевич',
        'login' => 'i.vysotsky', 'password' => 'pichugin743', 'role' => 'super'],

    ['action' => 'insert', 'last_name' => 'Сидоров',
        'first_name' => 'Егор', 'middle_name' => 'Георгиевич',
        'login' => 'e.sidorov', 'password' => 'petrunin881', 'role' => 'duty'],

    ['action' => 'insert', 'last_name' => 'Логунов',
        'first_name' => 'Никита', 'middle_name' => 'Дмитриевич',
        'login' => 'n.logunov', 'password' => 'nikitin7331', 'role' => 'duty'],

    ['action' => 'insert', 'last_name' => 'Петров',
        'first_name' => 'Святослав', 'middle_name' => 'Игоревич',
        'login' => 's.petrov', 'password' => 'gorustovich9944', 'role' => 'duty'],

    ['action' => 'insert', 'last_name' => 'Новиков',
        'first_name' => 'Николай', 'middle_name' => 'Алексеевич',
        'login' => 'n.novikov', 'password' => 'budilin8211', 'role' => 'duty'],
];

foreach ($ops as $op) {
    if ($op['action'] === 'update') {
        $sets = ['first_name = ?', 'middle_name = ?'];
        $vals = [$op['first_name'], $op['middle_name']];
        if (!empty($op['login']))    { $sets[] = 'login = ?';         $vals[] = $op['login']; }
        if (!empty($op['password'])) { $sets[] = 'password_hash = ?'; $vals[] = password_hash($op['password'], PASSWORD_DEFAULT); }
        $vals[] = $op['last_name'];
        $sql = 'UPDATE users SET ' . implode(', ', $sets) . ' WHERE last_name = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($vals);
        echo "Updated: {$op['last_name']} → rows affected: {$stmt->rowCount()}\n";
    } else {
        $hash = password_hash($op['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (login, password_hash, role, last_name, first_name, middle_name, created_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)");
        $stmt->execute([$op['login'], $hash, $op['role'], $op['last_name'], $op['first_name'], $op['middle_name']]);
        echo "Inserted: {$op['last_name']} {$op['first_name']} (login: {$op['login']}, role: {$op['role']})\n";
    }
}

echo "\nDone. DELETE this file!\n";
