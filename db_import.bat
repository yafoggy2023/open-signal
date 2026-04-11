@echo off
REM ═══════════════════════════════════════════════════════════════
REM  Импорт БД fsb_portal из db.sql.
REM  Запускать ПОСЛЕ `git pull`, если db.sql обновился.
REM
REM  ВНИМАНИЕ: этот скрипт полностью перезаписывает таблицы
REM  в БД fsb_portal (mysqldump сделан с --add-drop-table).
REM  Локальные изменения, не попавшие в db.sql, будут потеряны.
REM ═══════════════════════════════════════════════════════════════

setlocal
set "MYSQL=C:\xampp\mysql\bin\mysql.exe"
set "DBNAME=fsb_portal"
set "INFILE=%~dp0db.sql"

if not exist "%MYSQL%" (
  echo [ERROR] mysql не найден: %MYSQL%
  exit /b 1
)
if not exist "%INFILE%" (
  echo [ERROR] db.sql не найден: %INFILE%
  echo        Сначала сделай git pull или db_export.bat на другой машине.
  exit /b 1
)

echo [*] Проверяю, существует ли БД %DBNAME% ...
"%MYSQL%" -u root -e "CREATE DATABASE IF NOT EXISTS %DBNAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
  echo [ERROR] Не удалось создать БД. Запущен ли MySQL в XAMPP?
  exit /b 1
)

echo [*] Импорт %INFILE% -^> %DBNAME% ...
"%MYSQL%" -u root --default-character-set=utf8mb4 %DBNAME% < "%INFILE%"
if errorlevel 1 (
  echo [ERROR] Импорт провалился
  exit /b 1
)

echo [OK] БД %DBNAME% восстановлена из db.sql
endlocal
