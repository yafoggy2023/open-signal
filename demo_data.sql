-- ============================================================
-- Открытый сигнал — демонстрационные данные
-- 10 обращений, покрывающих все возможности портала:
--   • все источники (site / telegram)
--   • все приоритеты (low / medium / high / critical)
--   • все статусы (new / process / review / done / rejected)
--   • анонимные и с контактами (ФИО, email, phone, telegram, адрес)
--   • достоверность: high / medium / low
--   • геолокация: Россия, иностранные IP, VPN, хостинг
--   • прикреплённые файлы (clean / infected / skipped ClamAV)
--   • комментарии с @упоминаниями
--   • история изменений
--   • связанные обращения
--   • дубли по IP (одинаковый IP у двух обращений)
--   • spam_score и spam_flags
--   • примечания
--
-- Запуск:
--   mysql -u root fsb_portal < demo_data.sql
--
-- ВНИМАНИЕ: обращения с префиксом АПО-2026-1000xx перезатираются
-- при повторном запуске. Пользовательские обращения не затрагиваются.
-- ============================================================

SET NAMES utf8mb4;

-- Очистка предыдущих демо-данных (cascade удалит files/comments/history)
DELETE FROM linked_appeals WHERE appeal_id_a LIKE 'АПО-2026-1000%' OR appeal_id_b LIKE 'АПО-2026-1000%';
DELETE FROM appeals WHERE appeal_id LIKE 'АПО-2026-1000%';

-- ============================================================
-- 1. КОРРУПЦИЯ — критический, идентифицированный, высокая достоверность
-- Файлы, комментарии с упоминаниями, история
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100001',
  'Требование взятки за подписание акта приёмки работ',
  'Коррупция',
  'critical',
  'new',
  0,
  '{"last":"Смирнов","first":"Алексей","mid":"Петрович","email":"a.smirnov@example.ru","phone":"+79161234567","addr":"г. Москва, ул. Тверская, 15, кв. 42"}',
  'Прокуратура г. Москвы',
  'г. Москва, ул. Тверская, 15',
  '2026-04-08',
  'Заместитель главы администрации района Иванов А.С. в ходе личной встречи 8 апреля 2026 года в своём служебном кабинете открыто потребовал передать ему 500 000 рублей за подписание акта приёмки выполненных работ по муниципальному контракту № 45-МК-2026. Разговор записан на диктофон (запись прилагается), также имеется фотокопия проекта акта с его пометками. Готов дать показания и участвовать в следственных действиях. Контактные данные указаны.',
  '176.59.120.45',
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Safari/537.36',
  'Windows / Chrome 125.0.0.0',
  'Россия', 'Москва', 'Москва', 'PJSC MTS', 0, 0,
  5, '[]', 'site',
  'Свидетель согласен на очную ставку. Высокий приоритет.',
  1,
  '2026-04-09 10:14:23'
);
SET @id1 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id1, 'audio_record.mp3', 3456789, 'clean', NULL, '2026-04-09 10:14:30'),
  (@id1, 'akt_draft.pdf', 423517, 'clean', NULL, '2026-04-09 10:14:35');

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id1, 1, 'Поручаю @admin оперативно связаться с заявителем — нужен доступ к оригиналу аудиозаписи.', '2026-04-09 11:02:10'),
  (@id1, 2, 'Связался, завтра выезд на осмотр. @superadmin, согласуйте ордер на обыск кабинета.', '2026-04-09 14:35:44');

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id1, 1, 'priority', 'high', 'critical', '2026-04-09 10:30:00'),
  (@id1, 1, 'assigned_to', NULL, '1', '2026-04-09 10:31:12'),
  (@id1, 1, 'note', '', 'Свидетель согласен на очную ставку. Высокий приоритет.', '2026-04-09 10:32:05');


-- ============================================================
-- 2. ФИШИНГ — спам, VPN+хостинг, иностранный IP, НИЗКАЯ достоверность
-- Демонстрация всех красных флагов скоринга
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100002',
  'срочно проверьте сайт',
  'fraud',
  'low',
  'new',
  1,
  NULL,
  '',
  '',
  NULL,
  'сайт фишинг деньги украли',
  '45.155.166.66',
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/110.0.0.0 Safari/537.36',
  'Linux / Chrome',
  'Netherlands', 'North Holland', 'Amsterdam', 'NordVPN / M247 Europe SRL', 1, 1,
  72, '["Очень короткий текст","VPN/прокси","Хостинг-провайдер","Headless-браузер"]', 'site',
  '',
  NULL,
  '2026-04-10 02:17:58'
);


-- ============================================================
-- 3. ВЫМОГАТЕЛЬСТВО — идентифицированный, process, файл с вирусом
-- Комментарии, история, связан с #4
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100003',
  'Вымогательство денег под угрозой публикации клеветы',
  'Вымогательство',
  'high',
  'process',
  0,
  '{"last":"Ковалёва","first":"Ирина","mid":"Сергеевна","email":"i.kovaleva@mail.ru","phone":"+79217778899","telegram":"@irina_k_spb","addr":"г. Санкт-Петербург, Невский пр., 88"}',
  'ГУ МВД по г. Санкт-Петербургу',
  'г. Санкт-Петербург, Невский пр., 88',
  '2026-04-05',
  'С 5 апреля мне поступают сообщения от неизвестного с требованием перевести 2 миллиона рублей на криптокошелёк, иначе в сеть выложат сфабрикованные материалы, порочащие мою деловую репутацию. Прислали скриншоты якобы готовой публикации и вложили исполняемый файл "proof.exe" — не открывала. Прошу принять меры и проверить файл. Все переписки и вложения прилагаю.',
  '95.161.150.33',
  'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
  'iOS / Safari',
  'Россия', 'Санкт-Петербург', 'Санкт-Петербург', 'Rostelecom', 0, 0,
  3, '[]', 'site',
  'Файл proof.exe — ClamAV обнаружил троян. Изолирован.',
  2,
  '2026-04-05 19:42:11'
);
SET @id3 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id3, 'screenshots.zip', 1245678, 'clean', NULL, '2026-04-05 19:42:15'),
  (@id3, 'proof.exe', 89432, 'infected', 'Win32.Trojan.Agent.GEN', '2026-04-05 19:42:20');

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id3, 2, 'Файл proof.exe заражён трояном, помещён в карантин. @superadmin, нужна экспертиза.', '2026-04-05 20:15:33'),
  (@id3, 1, 'Согласовано. Передаём в отдел К. Есть похожий случай — @admin, свяжи с АПО-2026-100004.', '2026-04-06 09:21:04'),
  (@id3, 2, 'Связал. Обращения объединены.', '2026-04-06 09:45:12');

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id3, 2, 'status', 'new', 'process', '2026-04-06 09:50:00'),
  (@id3, 2, 'assigned_to', NULL, '2', '2026-04-06 09:50:15');


-- ============================================================
-- 4. КИБЕРШПИОНАЖ — telegram-источник, telegram_sender, review
-- Идентифицированный через Telegram, высокая достоверность
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, sender_chat_id, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100004',
  'Попытка взлома корпоративной почты через поддельный домен',
  'cybercrime',
  'critical',
  'review',
  0,
  '{"telegram_sender":"@dmitry_sec_ru"}',
  'УФСБ России по Республике Татарстан',
  'г. Казань, ул. Баумана, 44',
  '2026-04-03',
  'Я сотрудник ИБ казанской компании. С 3 апреля зафиксированы массовые попытки фишинга сотрудников с домена, визуально копирующего наш корпоративный портал (отличается одна буква). Перехватил письма, заголовки указывают на отправителя из Амстердама, используется VPN. Во вложении — сами письма (.eml), лог сетевой разведки и хэши образцов. Считаю, что это целенаправленная атака на компанию.',
  '46.39.225.115',
  'Telegram Bot API',
  'Telegram',
  'Россия', 'Татарстан', 'Казань', 'PJSC MegaFon', 0, 0,
  2, '[]', 'telegram',
  742891456,
  'Связано с вымогательством #100003 — тот же паттерн.',
  1,
  '2026-04-04 08:55:19'
);
SET @id4 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id4, 'phishing_emails.eml', 215447, 'clean', NULL, '2026-04-04 08:55:22'),
  (@id4, 'network_log.txt', 48213, 'clean', NULL, '2026-04-04 08:55:25');

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id4, 1, 'Материал качественный. @admin, передайте коллегам в отделе К.', '2026-04-04 10:12:44'),
  (@id4, 2, 'Передано. Ждём обратную связь от экспертов.', '2026-04-04 11:30:01');

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id4, 1, 'status', 'new', 'process', '2026-04-04 10:00:00'),
  (@id4, 1, 'priority', 'high', 'critical', '2026-04-04 10:05:30'),
  (@id4, 2, 'status', 'process', 'review', '2026-04-05 16:20:00'),
  (@id4, 1, 'assigned_to', NULL, '1', '2026-04-04 10:10:00');

-- Связь между #3 и #4
INSERT INTO linked_appeals (appeal_id_a, appeal_id_b, created_at) VALUES
  ('АПО-2026-100003', 'АПО-2026-100004', '2026-04-06 09:45:12');


-- ============================================================
-- 5. НАРКОТИКИ — анонимный, IP хостинг-провайдера (США)
-- Файл без AV-сканирования (ClamAV не установлен)
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100005',
  'Организация сбыта запрещённых веществ через мессенджер',
  'drugs',
  'medium',
  'new',
  1,
  NULL,
  'УКОН',
  'г. Новосибирск, Ленинский район',
  '2026-04-07',
  'Видел объявление в закрытом канале, там продают вещества. Присылают через курьера.',
  '188.114.97.3',
  'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
  'macOS / Chrome 124.0.0.0',
  'United States', 'California', 'San Francisco', 'DigitalOcean LLC', 0, 1,
  15, '["Хостинг-провайдер"]', 'site',
  'Требуется верификация: анонимное, IP-хостинг.',
  NULL,
  '2026-04-10 13:05:02'
);
SET @id5 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id5, 'screenshot.png', 342891, 'skipped', 'ClamAV не установлен', '2026-04-10 13:05:08');


-- ============================================================
-- 6. ТЕРРОРИЗМ — анонимный, короткий, разделяет IP с #7 (дубли)
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100006',
  'Подозрительные люди возле школы №17',
  'terrorism',
  'critical',
  'new',
  1,
  NULL,
  '',
  'г. Екатеринбург, ул. Мира, школа №17',
  '2026-04-10',
  'Возле школы №17 несколько дней подряд стоят люди в одинаковой одежде, фотографируют вход, осматривают здание. Сегодня один из них обошёл школу по периметру и что-то записывал.',
  '94.180.76.15',
  'Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36',
  'Android / Chrome 125.0.0.0',
  'Россия', 'Свердловская область', 'Екатеринбург', 'ER-Telecom Holding', 0, 0,
  8, '[]', 'site',
  '',
  2,
  '2026-04-11 07:22:14'
);
SET @id6 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id6, 'photo1.jpg', 2341567, 'clean', NULL, '2026-04-11 07:22:18');


-- ============================================================
-- 7. КОНТРАБАНДА — тот же IP, что и #6 (демонстрация дублей по IP)
-- Статус rejected
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, closed_by, closed_at, created_at
) VALUES (
  'АПО-2026-100007',
  'Незаконный провоз товаров через границу',
  'smuggling',
  'low',
  'rejected',
  1,
  NULL,
  '',
  '',
  NULL,
  'на границе что-то везут',
  '94.180.76.15',
  'Mozilla/5.0 (Linux; Android 13; SM-A536E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36',
  'Android / Chrome 125.0.0.0',
  'Россия', 'Свердловская область', 'Екатеринбург', 'ER-Telecom Holding', 0, 0,
  20, '["Слишком короткий текст","Дубль по IP"]', 'site',
  'Отклонено: недостаточно информации, дубль IP с #100006.',
  NULL, 2, '2026-04-11 08:01:45',
  '2026-04-11 07:45:33'
);
SET @id7 = LAST_INSERT_ID();

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id7, 2, 'status', 'new', 'rejected', '2026-04-11 08:01:45'),
  (@id7, 2, 'note', '', 'Отклонено: недостаточно информации, дубль IP с #100006.', '2026-04-11 08:02:00');


-- ============================================================
-- 8. ОРУЖИЕ — telegram, закрытое (done), полная история
-- Связано с #1
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, sender_chat_id, note, assigned_to, closed_by, closed_at, created_at
) VALUES (
  'АПО-2026-100008',
  'Объявление о продаже травматического оружия без документов',
  'weapons',
  'high',
  'done',
  0,
  '{"last":"Орлов","first":"Максим","mid":"Игоревич","email":"m.orlov@example.ru","phone":"+79055554433","telegram":"@m_orlov","telegram_sender":"@m_orlov"}',
  'УМВД по Московской области',
  'г. Подольск, рынок «Садовод», павильон 12',
  '2026-03-28',
  'Продавец на рынке в Подольске в открытую предлагает травматические пистолеты без предъявления каких-либо документов. Со слов продавца, "можно переделать под боевой". Цена 25 000 р. Фото павильона и продавца прилагаю. Готов опознать.',
  '83.220.237.10',
  'Telegram Bot API',
  'Telegram',
  'Россия', 'Московская область', 'Подольск', 'PJSC VimpelCom', 0, 0,
  4, '[]', 'telegram',
  612345789,
  'Успешная контрольная закупка. Объект задержан, материалы в суде.',
  2, 1, '2026-04-05 17:10:00',
  '2026-03-29 15:20:00'
);
SET @id8 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id8, 'pavilion.jpg', 1892334, 'clean', NULL, '2026-03-29 15:20:10'),
  (@id8, 'seller.jpg', 1634501, 'clean', NULL, '2026-03-29 15:20:15');

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id8, 2, 'Принято в работу. Организую контрольную закупку.', '2026-03-30 10:00:00'),
  (@id8, 2, 'Закупка состоялась, объект задержан на месте. Передаём материалы в суд.', '2026-04-02 14:30:00'),
  (@id8, 1, 'Отличная работа. @admin, закрывай.', '2026-04-05 17:05:00');

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id8, 2, 'status', 'new', 'process', '2026-03-30 09:55:00'),
  (@id8, 2, 'assigned_to', NULL, '2', '2026-03-30 09:55:30'),
  (@id8, 2, 'status', 'process', 'review', '2026-04-02 14:35:00'),
  (@id8, 1, 'status', 'review', 'done', '2026-04-05 17:10:00'),
  (@id8, 1, 'note', '', 'Успешная контрольная закупка. Объект задержан, материалы в суде.', '2026-04-05 17:10:30');

INSERT INTO linked_appeals (appeal_id_a, appeal_id_b, created_at) VALUES
  ('АПО-2026-100001', 'АПО-2026-100008', '2026-04-05 17:00:00');


-- ============================================================
-- 9. ЭКСТРЕМИЗМ — анонимный, VPN из Германии, НИЗКАЯ достоверность
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100009',
  'Публикация запрещённых материалов в соцсети',
  'extremism',
  'medium',
  'process',
  1,
  NULL,
  '',
  '',
  '2026-04-08',
  'В паблике выкладывают посты с признаками экстремизма. Ссылку не прикладываю, но там всё видно.',
  '185.220.101.42',
  'Mozilla/5.0 (Windows NT 10.0; rv:115.0) Gecko/20100101 Firefox/115.0',
  'Windows / Firefox',
  'Germany', 'Hessen', 'Frankfurt', 'Tor exit node / M247 Ltd', 1, 1,
  35, '["VPN/прокси","Хостинг-провайдер","Tor-узел","Короткий текст"]', 'site',
  'Требуется верификация. IP — Tor exit node.',
  2,
  '2026-04-09 22:44:17'
);
SET @id9 = LAST_INSERT_ID();

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id9, 2, 'Заявитель использует Tor и не предоставил ссылку. Без дополнительных данных проверить невозможно.', '2026-04-10 09:15:00');


-- ============================================================
-- 10. ЗЛОУПОТРЕБЛЕНИЕ ПОЛНОМОЧИЯМИ — идентифицированный с адресом
-- 3 файла, high, review, высокая достоверность
-- ============================================================
INSERT INTO appeals (
  appeal_id, subject, category, priority, status, is_anon, contact_json,
  organ, location, event_date, message,
  ip_address, user_agent, device_info,
  geo_country, geo_region, geo_city, geo_isp, geo_proxy, geo_hosting,
  spam_score, spam_flags, source, note, assigned_to, created_at
) VALUES (
  'АПО-2026-100010',
  'Незаконное задержание сотрудником полиции и давление на свидетелей',
  'Превышение должностных полномочий',
  'high',
  'review',
  0,
  '{"last":"Петров","first":"Николай","mid":"Викторович","email":"n.petrov@gmail.com","phone":"+79031112233","telegram":"@n_petrov_93","addr":"г. Краснодар, ул. Красная, 176, кв. 8"}',
  'Следственный комитет РФ по Краснодарскому краю',
  'г. Краснодар, ул. Красная, 176',
  '2026-03-25',
  'В ночь с 25 на 26 марта я был остановлен сотрудниками патруля ДПС без объяснения причин, доставлен в отделение, где на меня оказывалось психологическое давление с целью дать показания против знакомого. Протокол не составлялся, документы не возвращали 6 часов. Есть видеозапись с камеры в коридоре отделения (получена по адвокатскому запросу), медицинское освидетельствование и письменные показания двух свидетелей, находившихся рядом.',
  '31.148.25.77',
  'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
  'Android / Chrome 126.0.0.0',
  'Россия', 'Краснодарский край', 'Краснодар', 'Rostelecom', 0, 0,
  1, '[]', 'site',
  'Назначена служебная проверка. Ожидаем заключение СК.',
  2,
  '2026-03-27 11:33:44'
);
SET @id10 = LAST_INSERT_ID();

INSERT INTO appeal_files (appeal_db_id, filename, filesize, av_status, av_detail, uploaded_at) VALUES
  (@id10, 'video_corridor.mp4', 18456123, 'clean', NULL, '2026-03-27 11:33:50'),
  (@id10, 'medical_report.pdf', 289431, 'clean', NULL, '2026-03-27 11:33:55'),
  (@id10, 'witnesses.pdf', 156724, 'clean', NULL, '2026-03-27 11:34:00');

INSERT INTO comments (appeal_db_id, user_id, text, created_at) VALUES
  (@id10, 1, 'Материалы серьёзные. @admin, координируй со СК, запроси служебную проверку.', '2026-03-27 15:20:00'),
  (@id10, 2, 'Служебная проверка назначена, ответственный — подполковник Соколов. Ожидаем заключение.', '2026-03-30 10:45:00');

INSERT INTO appeal_history (appeal_db_id, user_id, field, old_value, new_value, created_at) VALUES
  (@id10, 1, 'status', 'new', 'process', '2026-03-27 15:25:00'),
  (@id10, 1, 'priority', 'medium', 'high', '2026-03-27 15:26:00'),
  (@id10, 2, 'status', 'process', 'review', '2026-03-30 10:50:00'),
  (@id10, 1, 'assigned_to', NULL, '2', '2026-03-27 15:27:00');


-- ============================================================
-- Готово
-- ============================================================
SELECT 'Demo data inserted' AS status,
       (SELECT COUNT(*) FROM appeals WHERE appeal_id LIKE 'АПО-2026-1000%') AS appeals,
       (SELECT COUNT(*) FROM appeal_files WHERE appeal_db_id IN (SELECT id FROM appeals WHERE appeal_id LIKE 'АПО-2026-1000%')) AS files,
       (SELECT COUNT(*) FROM comments WHERE appeal_db_id IN (SELECT id FROM appeals WHERE appeal_id LIKE 'АПО-2026-1000%')) AS comments,
       (SELECT COUNT(*) FROM appeal_history WHERE appeal_db_id IN (SELECT id FROM appeals WHERE appeal_id LIKE 'АПО-2026-1000%')) AS history,
       (SELECT COUNT(*) FROM linked_appeals WHERE appeal_id_a LIKE 'АПО-2026-1000%' OR appeal_id_b LIKE 'АПО-2026-1000%') AS links;
