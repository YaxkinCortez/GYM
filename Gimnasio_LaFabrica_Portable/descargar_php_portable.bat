@echo off
:: codepage standard
title Descargar PHP Portable para Windows
cd /d "%~dp0"

echo ====================================================================
echo   DESCARGADOR AUTOMATICO DE PHP PORTABLE PARA WINDOWS
echo ====================================================================
echo.
echo Este script descargara la version oficial y ligera de PHP para Windows
echo (25 MB) y la colocara en la carpeta "\php\" de este proyecto.
echo No requiere privilegios de administrador ni modificar tu PC.
echo.

if exist "%~dp0php\php.exe" (
    echo [OK] Ya tienes PHP instalado en la subcarpeta \php\
    echo Puedes iniciar el sistema directamente con: iniciar_gimnasio.bat
    echo.
    pause
    exit /b 0
)

if not exist "%~dp0php" mkdir "%~dp0php"

echo [1/3] Descargando archivo zip oficial de windows.php.net...
set "PHP_ZIP_URL=https://windows.php.net/downloads/releases/archives/php-8.2.20-nts-Win32-vs16-x64.zip"
set "ALT_PHP_URL=https://windows.php.net/downloads/releases/php-8.3.12-nts-Win32-vs16-x64.zip"

where curl.exe >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    curl.exe -L -k --progress-bar "%PHP_ZIP_URL%" -o "%~dp0php_temp.zip" || curl.exe -L -k --progress-bar "%ALT_PHP_URL%" -o "%~dp0php_temp.zip"
) else (
    powershell -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; (New-Object Net.WebClient).DownloadFile('%PHP_ZIP_URL%', '%~dp0php_temp.zip')"
)

if not exist "%~dp0php_temp.zip" (
    echo.
    echo [ERROR] No se pudo descargar el archivo. Verifica tu conexion a internet.
    pause
    exit /b 1
)

echo.
echo [2/3] Descomprimiendo en subcarpeta \php\...
where tar.exe >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    tar.exe -xf "%~dp0php_temp.zip" -C "%~dp0php"
) else (
    powershell -Command "Expand-Archive -Path '%~dp0php_temp.zip' -DestinationPath '%~dp0php' -Force"
)

del "%~dp0php_temp.zip" >nul 2>&1

echo [3/3] Configurando php.ini con extensiones SQLite habilitadas...
if exist "%~dp0php\php.ini-development" (
    if not exist "%~dp0php\php.ini" (
        copy "%~dp0php\php.ini-development" "%~dp0php\php.ini" >nul
        powershell -Command "(Get-Content '%~dp0php\php.ini') -replace ';extension=pdo_sqlite', 'extension=pdo_sqlite' -replace ';extension=sqlite3', 'extension=sqlite3' -replace ';extension_dir = "ext"', 'extension_dir = "ext"' | Set-Content '%~dp0php\php.ini'"
    )
)

echo.
echo ====================================================================
echo  [LISTO] PHP Portable instalado con exito en \php\
echo ====================================================================
echo Ahora ya puedes hacer doble clic en: iniciar_gimnasio.bat
echo.
pause
exit /b 0
