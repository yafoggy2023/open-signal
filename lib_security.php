<?php
/**
 * Дополнительные модули безопасности и анализа
 * Используется api.php
 */

// ── ТРАНСЛИТ для имен файлов ─────────────────────
function translit_simple($s) {
    $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, $map);
}

// ── RATE LIMIT для публичной формы подачи ────────
function check_submit_rate_limit($pdo, $ip) {
    if (!$ip || in_array($ip, ['127.0.0.1', '::1'])) return true;
    // Чистим старые записи (>24ч)
    $pdo->prepare("DELETE FROM submit_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL 24 HOUR)")->execute();

    // За последний час — не больше 3 заявок с одного IP
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submit_attempts WHERE ip = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= 3) return false;

    // За сутки — не больше 10
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM submit_attempts WHERE ip = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= 10) return false;

    return true;
}

function record_submit_attempt($pdo, $ip) {
    $pdo->prepare("INSERT INTO submit_attempts (ip, attempt_time) VALUES (?, NOW())")->execute([$ip]);
}

// ── СПАМ-ДЕТЕКТОР (эвристика) ────────────────────
function spam_analyze($subject, $message) {
    $score = 0;
    $flags = [];
    $text = $subject . ' ' . $message;
    $len = mb_strlen($text);

    // 1. Повторяющиеся символы (aaaa, !!!!)
    if (preg_match('/(.)\1{6,}/u', $text)) {
        $score += 25; $flags[] = 'Повторяющиеся символы';
    }

    // 2. Caps lock (более 60% заглавных в длинном тексте)
    if ($len > 50) {
        $upper = mb_strlen(preg_replace('/[^A-ZА-ЯЁ]/u', '', $text));
        $letters = mb_strlen(preg_replace('/[^A-Za-zА-Яа-яЁё]/u', '', $text));
        if ($letters > 0 && ($upper / $letters) > 0.6) {
            $score += 20; $flags[] = 'Чрезмерное использование CAPS';
        }
    }

    // 3. Много ссылок (>2)
    $links = preg_match_all('~https?://|www\.|t\.me/|bit\.ly~i', $text);
    if ($links >= 3) { $score += 30; $flags[] = $links.' ссылок в тексте'; }
    elseif ($links == 2) { $score += 15; $flags[] = '2 ссылки в тексте'; }

    // 4. Спам-слова (реклама, заработок, крипта)
    $spamWords = ['заработок','крипт','биткойн','биткоин','казино','ставки','инвести','прибыл','дешево','скидк','акци','распродаж','viagra','xxx','порно','лотере'];
    $hits = 0;
    foreach ($spamWords as $w) {
        if (mb_stripos($text, $w) !== false) $hits++;
    }
    if ($hits >= 2) { $score += 25; $flags[] = 'Спам-лексика ('.$hits.' слов)'; }
    elseif ($hits == 1) { $score += 10; }

    // 5. Слишком короткое сообщение
    if (mb_strlen($message) < 30) { $score += 15; $flags[] = 'Очень короткий текст'; }

    // 6. Повторяющиеся слова (одно слово >30% всех слов)
    $words = preg_split('/\s+/u', mb_strtolower($message));
    $words = array_filter($words, function($w){ return mb_strlen($w) > 3; });
    if (count($words) > 5) {
        $counts = array_count_values($words);
        arsort($counts);
        $top = reset($counts);
        if (($top / count($words)) > 0.3) {
            $score += 20; $flags[] = 'Повторяющееся слово';
        }
    }

    // 7. Низкая энтропия (очень мало уникальных символов)
    $uniqueChars = mb_strlen(implode('', array_unique(preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY))));
    if ($len > 100 && $uniqueChars < 15) {
        $score += 25; $flags[] = 'Низкое разнообразие текста';
    }

    // 8. Email/телефоны в избытке (>3)
    $contacts = preg_match_all('/[\w.-]+@[\w.-]+\.\w+|\+?\d[\d\s\-()]{8,}/u', $text);
    if ($contacts >= 3) { $score += 15; $flags[] = 'Множество контактов в тексте'; }

    // 9. Латиница + кириллица в одном слове (миксинг для обхода)
    if (preg_match('/\b\w*[A-Za-z]\w*[А-Яа-яЁё]\w*\b|\b\w*[А-Яа-яЁё]\w*[A-Za-z]\w*\b/u', $text)) {
        $score += 15; $flags[] = 'Смешение латиницы и кириллицы';
    }

    return ['score' => min(100, $score), 'flags' => $flags];
}

// ── CLAMAV сканирование файлов ───────────────────
function clamav_scan($filepath) {
    if (!file_exists($filepath)) return ['status' => 'error', 'detail' => 'Файл не найден'];

    // Проверка наличия clamdscan / clamscan
    $bin = null;
    foreach (['clamdscan', 'clamscan', '/usr/bin/clamdscan', '/usr/bin/clamscan'] as $b) {
        $check = @shell_exec("which $b 2>&1");
        if ($check && trim($check)) { $bin = $b; break; }
    }
    // Windows fallback
    if (!$bin && stripos(PHP_OS, 'WIN') === 0) {
        foreach (['C:\\Program Files\\ClamAV\\clamdscan.exe', 'C:\\Program Files\\ClamAV\\clamscan.exe'] as $b) {
            if (file_exists($b)) { $bin = '"' . $b . '"'; break; }
        }
    }

    if (!$bin) return ['status' => 'skipped', 'detail' => 'ClamAV не установлен'];

    $out = [];
    $code = 0;
    $cmd = $bin . ' --no-summary ' . escapeshellarg($filepath) . ' 2>&1';
    @exec($cmd, $out, $code);
    $output = implode("\n", $out);

    if ($code === 0) return ['status' => 'clean', 'detail' => 'OK'];
    if ($code === 1) {
        // Найдена угроза
        if (preg_match('/:\s*(.+)\s+FOUND/', $output, $m)) {
            return ['status' => 'infected', 'detail' => $m[1]];
        }
        return ['status' => 'infected', 'detail' => 'Угроза обнаружена'];
    }
    return ['status' => 'error', 'detail' => 'Ошибка сканирования (код '.$code.')'];
}

// ── ЛОГ ИЗМЕНЕНИЙ полей ──────────────────────────
function log_field_change($pdo, $appeal_db_id, $field, $old, $new) {
    if ((string)$old === (string)$new) return;
    $pdo->prepare("INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$appeal_db_id, $_SESSION['user_id'] ?? null, $field, (string)$old, (string)$new]);
}

// ── ВОДЯНОЙ ЗНАК на изображения ──────────────────
function apply_image_watermark($srcPath, $text) {
    if (!function_exists('imagecreatefromstring')) return false;
    $data = @file_get_contents($srcPath);
    if (!$data) return false;
    $img = @imagecreatefromstring($data);
    if (!$img) return false;

    $w = imagesx($img); $h = imagesy($img);

    // Полупрозрачный белый текст с тенью
    $white = imagecolorallocatealpha($img, 255, 255, 255, 60);
    $black = imagecolorallocatealpha($img, 0, 0, 0, 90);

    $fontSize = max(2, min(5, intval($w / 200)));
    $lines = [
        '⚠ КОНФИДЕНЦИАЛЬНО',
        $text,
        date('d.m.Y H:i'),
    ];
    $y = $h - 60;
    foreach ($lines as $line) {
        // тень
        imagestring($img, $fontSize, 12, $y + 1, $line, $black);
        imagestring($img, $fontSize, 11, $y, $line, $white);
        $y += 16;
    }

    // Диагональный штамп по центру (через imagestring, без TTF)
    $stamp = 'ФСБ · ' . $text;
    $sx = max(10, intval($w / 2 - strlen($stamp) * 4));
    $sy = intval($h / 2);
    $stampColor = imagecolorallocatealpha($img, 192, 57, 43, 95);
    imagestring($img, 5, $sx, $sy, $stamp, $stampColor);

    ob_start();
    $mime = mime_content_type($srcPath);
    if (stripos($mime, 'png') !== false) imagepng($img);
    elseif (stripos($mime, 'gif') !== false) imagegif($img);
    else imagejpeg($img, null, 88);
    $out = ob_get_clean();
    imagedestroy($img);
    return $out;
}
