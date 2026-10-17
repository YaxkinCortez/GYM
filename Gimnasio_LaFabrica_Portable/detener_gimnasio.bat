@echo off
:: codepage standard
title Cerrar Servidor Gimnasio La Fabrica
cd /d "%~dp0"

echo ====================================================================
echo   Deteniendo servidor de Gimnasio La Fabrica (Puerto 8080)...
echo ====================================================================

for /f "tokens=5" %%a in ('netstat -aon ^| findstr :8080 ^| findstr LISTENING 2^>nul') do (
    echo Cerrando proceso PID %%a...
    taskkill /F /PID %%a >nul 2>&1
)

echo.
echo [OK] El servidor ha sido cerrado correctamente.
timeout /t 2 >nul
exit
