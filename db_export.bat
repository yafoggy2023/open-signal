@echo off
REM ═══════════════════════════════════════════════════════════════
REM  Экспорт БД fsb_portal в db.sql для коммита в git.
REM  Запускать ПЕРЕД `git commit`, если в БД были изменения.
REM ═══════════════════════════════════════════════════════════════

setlocal
set "MYSQLDUMP=C:\xampp\mysql\bin\mysqldump.exe"
set "DBNAME=fsb_portal"
set "OUTFILE=%~dp0db.sql"

if not exist "%MYSQLDUMP%" (
  echo [ERROR] mysqldump не найден: %MYSQLDUMP%
  exit /b 1
)

echo [*] Экспорт %DBNAME% -^> %OUTFILE% ...
"%MYSQLDUMP%" -u root --default-character-set=utf8mb4 --routines --triggers --single-transaction --add-drop-table --set-charset %DBNAME% > "%OUTFILE%"
if errorlevel 1 (
  echo [ERROR] mysqldump провалился
  exit /b 1
)

for %%A in ("%OUTFILE%") do set "SIZE=%%~zA"
echo [OK] db.sql ^(%SIZE% байт^) готов. Теперь можно: git add db.sql ^&^& git commit
endlocal
