@echo off
title Instalador LOCAL - Jorge Chavez API SUNAT
color 0B

REM ================================================================
REM  INSTALADOR PARA USO LOCAL (Laragon / Windows)
REM  Ejecutar UNA sola vez con doble clic.
REM
REM  Que hace:
REM   1) Pone las colas en modo SINCRONO (QUEUE_CONNECTION=sync)
REM      -> las facturas y boletas se envian a SUNAT al instante,
REM         SIN necesidad de correr "php artisan queue:work".
REM   2) Registra una tarea programada de Windows que ejecuta el
REM      scheduler de Laravel cada minuto. Corre OCULTA (sin ventana)
REM      y se encarga de:
REM        - recuperar comprobantes que quedaron pendientes
REM        - cerrar los tickets de resumenes / anulaciones
REM   El cliente NUNCA tiene que abrir una terminal.
REM ================================================================

REM --- Carpeta del proyecto = carpeta donde esta este .bat ---
set "PROJECT_DIR=%~dp0"
if "%PROJECT_DIR:~-1%"=="\" set "PROJECT_DIR=%PROJECT_DIR:~0,-1%"

set "TASK_NAME=JorgeChavezApiSunat-Scheduler"
set "WIN_DIR=%PROJECT_DIR%\scripts\win"
set "CMD_FILE=%WIN_DIR%\scheduler-run.cmd"
set "VBS_FILE=%WIN_DIR%\scheduler-run.vbs"

echo.
echo ============================================================
echo   Instalacion LOCAL - Jorge Chavez API SUNAT
echo ============================================================
echo   Proyecto:
echo     %PROJECT_DIR%
echo.

REM ------------------------------------------------------------
REM  1) Localizar php.exe
REM ------------------------------------------------------------
set "PHP_EXE="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
if not defined PHP_EXE (
    for /f "delims=" %%P in ('dir /b /s "C:\laragon\bin\php\php.exe" 2^>nul') do if not defined PHP_EXE set "PHP_EXE=%%P"
)
if not defined PHP_EXE (
    echo [ERROR] No se encontro php.exe.
    echo         Asegurate de tener Laragon instalado, o agrega PHP al PATH.
    echo.
    pause
    exit /b 1
)
echo   PHP detectado:
echo     %PHP_EXE%
echo.

REM Verificar que php responde
"%PHP_EXE%" -v >nul 2>&1
if errorlevel 1 (
    echo [ERROR] php.exe no responde correctamente.
    echo.
    pause
    exit /b 1
)

REM ------------------------------------------------------------
REM  2) QUEUE_CONNECTION=sync en el .env
REM ------------------------------------------------------------
set "ENV_FILE=%PROJECT_DIR%\.env"
if not exist "%ENV_FILE%" (
    echo [ERROR] No existe el archivo .env en el proyecto.
    echo         Copia .env.example a .env y configura la base de datos primero.
    echo.
    pause
    exit /b 1
)

echo   Configurando colas en modo sincrono...
copy /y "%ENV_FILE%" "%ENV_FILE%.bak" >nul
powershell -NoProfile -ExecutionPolicy Bypass -Command "$p=$env:ENV_FILE; $c=Get-Content -Raw -LiteralPath $p; if($c -match '(?m)^QUEUE_CONNECTION='){ $c=[regex]::Replace($c,'(?m)^QUEUE_CONNECTION=.*$','QUEUE_CONNECTION=sync') } else { $c=$c.TrimEnd()+[Environment]::NewLine+'QUEUE_CONNECTION=sync'+[Environment]::NewLine }; Set-Content -LiteralPath $p -Value $c -NoNewline -Encoding UTF8"
if errorlevel 1 (
    echo [ERROR] No se pudo actualizar el .env.
    echo.
    pause
    exit /b 1
)
echo     .env actualizado ^(respaldo en .env.bak^)
echo.

REM Limpiar cache de configuracion para que tome el nuevo valor
pushd "%PROJECT_DIR%"
"%PHP_EXE%" artisan config:clear >nul 2>&1
popd

REM ------------------------------------------------------------
REM  3) Generar el lanzador oculto del scheduler
REM ------------------------------------------------------------
echo   Generando lanzador del scheduler...
if not exist "%WIN_DIR%" mkdir "%WIN_DIR%"

REM  scheduler-run.cmd  -> corre el scheduler de Laravel
echo @echo off> "%CMD_FILE%"
echo cd /d "%PROJECT_DIR%">> "%CMD_FILE%"
echo "%PHP_EXE%" artisan schedule:run>> "%CMD_FILE%"

REM  scheduler-run.vbs  -> ejecuta el .cmd de forma OCULTA (sin ventana)
echo Set WshShell = CreateObject("WScript.Shell")> "%VBS_FILE%"
echo WshShell.Run """%CMD_FILE%""", 0, False>> "%VBS_FILE%"

echo     Lanzador creado en scripts\win\
echo.

REM ------------------------------------------------------------
REM  4) Registrar la tarea programada (cada minuto, oculta)
REM ------------------------------------------------------------
echo   Registrando tarea programada de Windows...
schtasks /Create /TN "%TASK_NAME%" /TR "wscript.exe \"%VBS_FILE%\"" /SC MINUTE /MO 1 /IT /F >nul 2>&1
if errorlevel 1 (
    echo [ERROR] No se pudo registrar la tarea programada.
    echo         Prueba ejecutar este instalador como Administrador
    echo         ^(clic derecho -^> Ejecutar como administrador^).
    echo.
    pause
    exit /b 1
)
echo     Tarea "%TASK_NAME%" registrada ^(cada 1 minuto, oculta^).
echo.

REM ------------------------------------------------------------
REM  5) Procesar de una vez lo que hubiera quedado pendiente
REM ------------------------------------------------------------
echo   Recuperando comprobantes pendientes ^(si los hay^)...
pushd "%PROJECT_DIR%"
REM  Drenar cualquier job que haya quedado en la tabla `jobs` de cuando
REM  el driver era `database` (bajo `sync` ya nadie los procesaria).
"%PHP_EXE%" artisan queue:work database --stop-when-empty --queue=sunat,webhooks,mail,default --tries=1 --timeout=120 >nul 2>&1
REM  Reencolar (ahora sincronos) los comprobantes atascados y cerrar tickets.
"%PHP_EXE%" artisan sunat:reintentar-pendientes --minutos=0 >nul 2>&1
"%PHP_EXE%" artisan summaries:poll-pending >nul 2>&1
popd
echo.

echo ============================================================
echo   LISTO. Instalacion local completada.
echo ------------------------------------------------------------
echo   - Facturas y boletas: se envian a SUNAT al instante.
echo   - Resumenes y anulaciones: se completan solos en 2do plano.
echo   - No hace falta abrir ninguna terminal.
echo.
echo   Para desinstalar la tarea: ejecuta  desinstalar-local.bat
echo ============================================================
echo.
pause
exit /b 0
