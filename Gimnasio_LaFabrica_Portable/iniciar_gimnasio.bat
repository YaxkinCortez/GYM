@echo off
:: codepage standard
title Gimnasio La Fabrica - Servidor Desktop Portable
cd /d "%~dp0"

echo ====================================================================
echo      GIMNASIO LA FABRICA - SISTEMA DESKTOP PORTABLE (SQLite3)
echo ====================================================================
echo.
echo [1/3] Detectando ejecutable de PHP...

set "PHP_CMD="
set "PHP_DIR="

:: 1. Subcarpeta local \php\
if exist "%~dp0php\php.exe" (
    set "PHP_CMD=%~dp0php\php.exe"
    set "PHP_DIR=%~dp0php\"
    echo [OK] Detectado PHP en carpeta local: \php\php.exe
    goto :php_encontrado
)

:: 2. Variable PATH del sistema
where php.exe >nul 2>&1
if %ERRORLEVEL% NEQ 0 goto :check_xampp_c
set "PHP_CMD=php"
for /f "tokens=*" %%I in ('where php.exe') do if not defined PHP_DIR set "PHP_DIR=%%~dpI"
echo [OK] Detectado PHP en PATH del sistema.
goto :php_encontrado

:check_xampp_c
:: 3. XAMPP en C:\
if exist "C:\xampp\php\php.exe" (
    set "PHP_CMD=C:\xampp\php\php.exe"
    set "PHP_DIR=C:\xampp\php\"
    echo [OK] Detectado PHP de XAMPP en C:\xampp\php\
    goto :php_encontrado
)

:: 4. XAMPP en D:\
if exist "D:\xampp\php\php.exe" (
    set "PHP_CMD=D:\xampp\php\php.exe"
    set "PHP_DIR=D:\xampp\php\"
    echo [OK] Detectado PHP de XAMPP en D:\xampp\php\
    goto :php_encontrado
)

:: 5. C:\php\
if exist "C:\php\php.exe" (
    set "PHP_CMD=C:\php\php.exe"
    set "PHP_DIR=C:\php\"
    echo [OK] Detectado PHP en C:\php\
    goto :php_encontrado
)

echo.
echo ====================================================================
echo  [AVISO] No se detecto php.exe en tu sistema.
echo ====================================================================
echo Para ejecutar el sistema portable, necesitas PHP.
echo Puedes instalarlo automaticamente en 15 segundos ejecutando:
echo.
echo   👉 descargar_php_portable.bat
echo.
echo O coloca tus archivos de PHP en la carpeta "php" de este proyecto.
echo ====================================================================
echo.
pause
exit /b 1

:php_encontrado
echo [INFO] Directorio base de PHP: %PHP_DIR%
echo.
echo [2/3] Verificando version y extensiones SQLite...
"%PHP_CMD%" -v
echo.

set "EXT_FLAGS="
set "INI_FLAGS="

if defined PHP_DIR (
    if exist "%PHP_DIR%ext" (
        set EXT_FLAGS=-d extension_dir="%PHP_DIR%ext" -d extension=pdo_sqlite -d extension=sqlite3
    ) else (
        set EXT_FLAGS=-d extension=pdo_sqlite -d extension=sqlite3
    )
    if exist "%PHP_DIR%php.ini" (
        set INI_FLAGS=-c "%PHP_DIR%php.ini"
    )
) else (
    set EXT_FLAGS=-d extension=pdo_sqlite -d extension=sqlite3
)

:: Probar si el driver pdo_sqlite responde
"%PHP_CMD%" %INI_FLAGS% %EXT_FLAGS% -r "echo extension_loaded('pdo_sqlite') ? '[OK] Driver PDO SQLite3 cargado con exito' : '[AVISO] Extension pdo_sqlite requiere activacion en php.ini';"
echo.

echo [3/3] Verificando estado del puerto 8080...
:: Comprobar si el servidor ya esta en ejecucion en el puerto 8080
netstat -aon | findstr :8080 | findstr LISTENING >nul 2>&1
if not errorlevel 1 (
    echo [OK] El servidor del gimnasio ya se encuentra activo en http://127.0.0.1:8080
    goto :abrir_navegador
)

:: Si habia algun proceso colgado que no responda, liberarlo
for /f "tokens=5" %%a in ('netstat -aon ^| findstr :8080 ^| findstr LISTENING 2^>nul') do (
    taskkill /F /PID %%a >nul 2>&1
    timeout /t 1 /nobreak >nul
)

echo.
echo ====================================================================
echo  INICIANDO SERVIDOR LOCAL (Puerto 8080)
echo ====================================================================
echo  - URL:           http://127.0.0.1:8080
echo  - Base de datos: database/gimnasio.sqlite
echo.

:: 1. Crear el script ejecutor del servidor con log de errores
(
    echo @echo off
    echo chcp 65001 ^>nul
    echo cd /d "%~dp0"
    echo "%PHP_CMD%" %INI_FLAGS% %EXT_FLAGS% -d display_errors=1 -S 127.0.0.1:8080 router.php ^> "%~dp0server.log" 2^>^&1
) > "%~dp0servidor_run.bat"

:: 2. Lanzar de forma 100% silenciosa en segundo plano mediante PowerShell o fallback
powershell -NoProfile -NonInteractive -WindowStyle Hidden -Command "Start-Process -FilePath cmd.exe -ArgumentList '/c \"\"%~dp0servidor_run.bat\"\"' -WindowStyle Hidden" >nul 2>&1
if errorlevel 1 (
    start /B "" cmd /c "%~dp0servidor_run.bat"
)

:: 3. Verificar que el puerto 8080 este escuchando activamente
echo [INFO] Esperando a que el servidor este listo...
set "ACTIVO=0"
for /l %%i in (1,1,10) do (
    netstat -aon | findstr :8080 | findstr LISTENING >nul 2>&1
    if not errorlevel 1 (
        set "ACTIVO=1"
        goto :abrir_navegador
    )
    timeout /t 1 /nobreak >nul
)

if "%ACTIVO%"=="0" (
    echo.
    echo ====================================================================
    echo  [AVISO] El servidor en segundo plano no respondio en 10 segundos.
    echo  Revisando server.log:
    echo ====================================================================
    if exist "%~dp0server.log" type "%~dp0server.log"
    echo.
    echo Intentando inicio directo en primer plano para mostrar detalles...
    "%PHP_CMD%" %INI_FLAGS% %EXT_FLAGS% -d display_errors=1 -S 127.0.0.1:8080 router.php
    pause
    exit /b 1
)

:abrir_navegador
echo [OK] Servidor activo y respondiendo en http://127.0.0.1:8080
echo.
echo ====================================================================
echo  ABRIENDO EN VENTANA INDEPENDIENTE DE ESCRITORIO
echo  (Modo App nativo: Sin cuadro de busqueda ni barra de direcciones)
echo ====================================================================
echo.

:: 1. Intentar con Microsoft Edge (Presente en todas las instalaciones de Windows 10 y 11)
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)
if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)
if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)

:: 2. Intentar con Google Chrome
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)
if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)
if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" (
    start "" "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)

:: 3. Intentar con Brave Browser
if exist "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" (
    start "" "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" --app="http://127.0.0.1:8080/login.php"
    goto :fin_lanzador
)

:: 4. Apertura directa por comando de aplicacion registrada en Windows
start msedge --app="http://127.0.0.1:8080/login.php" >nul 2>&1
if not errorlevel 1 goto :fin_lanzador

start chrome --app="http://127.0.0.1:8080/login.php" >nul 2>&1
if not errorlevel 1 goto :fin_lanzador

:: 5. Fallback secundario si ningun navegador Chromium soporta --app
start "" "http://127.0.0.1:8080/login.php"

:fin_lanzador
timeout /t 1 /nobreak >nul
exit
