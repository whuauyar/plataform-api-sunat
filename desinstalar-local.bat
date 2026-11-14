@echo off
title Desinstalador LOCAL - Jorge Chavez API SUNAT
color 0C

REM ================================================================
REM  Quita la tarea programada creada por instalar-local.bat
REM  (NO borra tus datos ni tu .env; solo detiene el scheduler
REM   automatico en 2do plano).
REM ================================================================

set "TASK_NAME=JorgeChavezApiSunat-Scheduler"

echo.
echo ============================================================
echo   Desinstalacion LOCAL - Jorge Chavez API SUNAT
echo ============================================================
echo.
echo   Quitando la tarea programada "%TASK_NAME%"...

schtasks /Query /TN "%TASK_NAME%" >nul 2>&1
if errorlevel 1 (
    echo   La tarea no existe. Nada que quitar.
    echo.
    pause
    exit /b 0
)

schtasks /Delete /TN "%TASK_NAME%" /F >nul 2>&1
if errorlevel 1 (
    echo [ERROR] No se pudo quitar la tarea.
    echo         Prueba ejecutar como Administrador.
    echo.
    pause
    exit /b 1
)

echo   Tarea quitada correctamente.
echo.
echo   NOTA: si quieres volver al modo con worker manual, edita el
echo         .env y cambia  QUEUE_CONNECTION=sync  por  =database.
echo ============================================================
echo.
pause
exit /b 0
