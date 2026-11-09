@echo off
rem Lokale Testumgebung: MariaDB (Port 3307) und PHP mit SQLite, http://127.0.0.1:8082
start "SQL-Manager MariaDB" /min "%~dp0..\_devtools\mariadb\bin\mariadbd.exe" --defaults-file="%~dp0..\_devtools\data\my.ini" --console
start "SQL-Manager PHP" /min "%~dp0..\_devtools\php\php.exe" -d extension=pdo_sqlite -S 127.0.0.1:8082 -t "%~dp0."
timeout /t 2 >nul
start http://127.0.0.1:8082/
