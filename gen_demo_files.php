<?php
/**
 * Генератор физических файлов для демо-обращений АПО-2026-1000xx
 * Запуск: php gen_demo_files.php
 * Можно безопасно удалить после генерации.
 */

$UPLOADS = __DIR__ . '/uploads/';

// ── Валидные минимальные PNG/JPG (без зависимости от GD в CLI) ──
// 8x8 PNG, оттенки по $bg_hint (просто разные файлы с небольшими отличиями)
function make_png($path, $w, $h, $bg, $caption) {
    // Валидный PNG 1x1 прозрачный
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgAAIAAAUAAen63NgAAAAASUVORK5CYII=');
    // Добавим "комментарий" чтобы файлы отличались по размеру и контенту
    $png .= "\n<!-- DEMO PNG: {$caption} -->";
    file_put_contents($path, $png);
}
function make_jpg($path, $w, $h, $bg, $caption) {
    // Валидный JPG 1x1
    $jpg = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAAEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/2wBDAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQH/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFAEBAAAAAAAAAAAAAAAAAAAAAP/EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhEDEQA/AL+AAAA//9k=');
    $jpg .= "\n<!-- DEMO JPG: {$caption} -->";
    file_put_contents($path, $jpg);
}

// ── Минимальный валидный PDF ──
function make_pdf($path, $text) {
    $esc = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $text);
    $stream = "BT /F1 14 Tf 50 750 Td ({$esc}) Tj ET\nBT /F1 10 Tf 50 720 Td (Demo file generated for testing) Tj ET";
    $streamLen = strlen($stream);

    $objs = [];
    $objs[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj";
    $objs[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj";
    $objs[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj";
    $objs[] = "4 0 obj\n<< /Length {$streamLen} >>\nstream\n{$stream}\nendstream\nendobj";
    $objs[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objs as $obj) {
        $offsets[] = strlen($pdf);
        $pdf .= $obj . "\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs)+1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objs); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objs)+1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";
    file_put_contents($path, $pdf);
}

// ── Минимальный ZIP-контейнер ──
function make_zip($path, $filename, $content) {
    if (!class_exists('ZipArchive')) {
        file_put_contents($path, "PK\x03\x04demo_zip_placeholder\n" . $content);
        return;
    }
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString($filename, $content);
    $zip->close();
}

// ── Plain text / bytes ──
function make_text($path, $text) {
    file_put_contents($path, $text);
}

function make_bytes($path, $bytes) {
    file_put_contents($path, $bytes);
}

// ============================================================
// Генерация файлов по обращениям
// ============================================================

// АПО-2026-100001 — взятка, аудио + проект акта
$d = $UPLOADS . 'АПО-2026-100001/';
make_bytes($d . 'audio_record.mp3',
    "ID3\x04\x00\x00\x00\x00\x00\x23TSSE\x00\x00\x00\x0f\x00\x00\x03Lavf58.29.100\x00" .
    str_repeat("\xFF\xFB\x90\x00", 400) // фрейм-заглушки MPEG
);
make_pdf($d . 'akt_draft.pdf', 'Projekt akta priemki rabot - DEMO');

// АПО-2026-100003 — вымогательство, архив + заражённый exe
$d = $UPLOADS . 'АПО-2026-100003/';
make_zip($d . 'screenshots.zip', 'screenshot1.txt', "Скриншот переписки с вымогателем\n[DEMO]");
// "infected" exe — любой PE-заголовок
make_bytes($d . 'proof.exe',
    "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF\x00\x00" .
    str_repeat("\x00", 100) .
    "DEMO_INFECTED_PLACEHOLDER_EICAR_TEST_FILE_NOT_A_REAL_VIRUS"
);

// АПО-2026-100004 — кибершпионаж, .eml + лог
$d = $UPLOADS . 'АПО-2026-100004/';
make_text($d . 'phishing_emails.eml',
    "From: it-security@corp-portal-ru.fake\r\n" .
    "To: victim@company.ru\r\n" .
    "Subject: =?UTF-8?B?0J7QsdGP0LfQsNGC0LXQu9GM0L3QviDRgdGB0LXQvtGA0LXRgNCy0LjQu9GM?=\r\n" .
    "Content-Type: text/html; charset=UTF-8\r\n\r\n" .
    "<html><body><p>Ваш аккаунт требует верификации — [DEMO phishing sample]</p></body></html>\r\n"
);
make_text($d . 'network_log.txt',
    "2026-04-03 14:22:15  46.39.225.115 -> 185.220.101.42  HTTP GET /login.php (phishing)\n" .
    "2026-04-03 14:22:18  185.220.101.42 -> 46.39.225.115  200 OK (credentials exfil)\n" .
    "2026-04-03 14:23:01  User-Agent: Mozilla/5.0 (Linux) HeadlessChrome/110\n" .
    "2026-04-03 14:23:04  TLS SNI: corp-portal-ru.fake (visually similar to corp-portal.ru)\n" .
    "[DEMO network reconnaissance log]\n"
);

// АПО-2026-100005 — наркотики, скриншот
$d = $UPLOADS . 'АПО-2026-100005/';
make_png($d . 'screenshot.png', 400, 200, [40, 40, 50], 'MESSENGER SCREENSHOT');

// АПО-2026-100006 — терроризм, фото
$d = $UPLOADS . 'АПО-2026-100006/';
make_jpg($d . 'photo1.jpg', 500, 300, [60, 80, 60], 'Photo near school');

// АПО-2026-100008 — оружие, два фото
$d = $UPLOADS . 'АПО-2026-100008/';
make_jpg($d . 'pavilion.jpg', 500, 300, [80, 60, 40], 'Pavilion 12 - market');
make_jpg($d . 'seller.jpg', 500, 300, [90, 70, 50], 'Seller photo');

// АПО-2026-100010 — превышение полномочий, видео + 2 pdf
$d = $UPLOADS . 'АПО-2026-100010/';
// Минимальный MP4 — просто байты, сервер отдаст octet-stream
make_bytes($d . 'video_corridor.mp4',
    "\x00\x00\x00\x20ftypisom\x00\x00\x02\x00isomiso2avc1mp41" .
    str_repeat("\x00", 200) . 'DEMO_VIDEO_PLACEHOLDER'
);
make_pdf($d . 'medical_report.pdf', 'Medical examination report - DEMO');
make_pdf($d . 'witnesses.pdf', 'Witness statements - DEMO');

// ============================================================
// Итоги
// ============================================================
echo "Generated demo files:\n";
$total = 0;
foreach (glob($UPLOADS . 'АПО-2026-1000*', GLOB_ONLYDIR) as $dir) {
    $files = glob($dir . '/*');
    echo "  " . basename($dir) . " — " . count($files) . " files\n";
    foreach ($files as $f) {
        echo "    " . basename($f) . " (" . filesize($f) . " bytes)\n";
        $total++;
    }
}
echo "Total: $total files\n";
