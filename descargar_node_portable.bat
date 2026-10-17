@echo off
setlocal
title Descargador de Node.js Portable - Gimnasio La Fabrica
cd /d "%~dp0"

set "PATH=%SystemRoot%\system32;%SystemRoot%;%SystemRoot%\System32\Wbem;%SystemRoot%\System32\WindowsPowerShell\v1.0\;%PATH%"

cls
echo ===============================================================================
echo     DESCARGADOR DE NODE.JS PORTABLE - GIMNASIO LA FABRICA
echo ===============================================================================
echo.
echo Este asistente descargara el ejecutable oficial de Node.js para Windows
echo directamente en la carpeta local "nodejs\node.exe".
echo.
echo  - No requiere permisos de administrador.
echo  - No modifica el registro ni variables de tu PC.
echo  - Permite ejecutar iniciar_gimnasio.bat de inmediato sin instalar nada mas.
echo.

if exist "%~dp0nodejs\node.exe" goto node_ya_existe

if not exist "%~dp0nodejs" mkdir "%~dp0nodejs"

echo [1/2] Descargando node.exe oficial desde https://nodejs.org/...
echo Esto puede tomar entre 10 y 30 segundos segun tu conexion.
echo.

:: 1. Intentar con curl
where curl.exe >nul 2>&1
if not errorlevel 1 goto descargar_con_curl

:: 2. Intentar con PowerShell
where powershell.exe >nul 2>&1
if not errorlevel 1 goto descargar_con_powershell

goto error_descarga

:descargar_con_curl
echo [Descarga] Utilizando curl...
curl.exe -L -k --progress-bar "https://nodejs.org/dist/v20.18.0/win-x64/node.exe" -o "%~dp0nodejs\node.exe"
if exist "%~dp0nodejs\node.exe" goto descarga_ok
echo [Descarga] curl no pudo completar la descarga. Probando con PowerShell...

:descargar_con_powershell
echo [Descarga] Utilizando PowerShell...
powershell.exe -ExecutionPolicy Bypass -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; (New-Object System.Net.WebClient).DownloadFile('https://nodejs.org/dist/v20.18.0/win-x64/node.exe', '%~dp0nodejs\node.exe')"
if exist "%~dp0nodejs\node.exe" goto descarga_ok

goto error_descarga

:node_ya_existe
echo [OK] Ya tienes Node.js Portable en la subcarpeta \nodejs\node.exe
echo Puedes iniciar el sistema directamente ejecutando: iniciar_gimnasio.bat
echo.
goto fin

:descarga_ok
echo.
echo ===============================================================================
echo [2/2] Verificando ejecutable descargado:
"%~dp0nodejs\node.exe" -v
echo.
echo [EXITO] Node.js Portable quedo listo en: %~dp0nodejs\node.exe
echo.
echo Ahora puedes hacer doble clic en iniciar_gimnasio.bat para abrir el gimnasio.
echo ===============================================================================
goto fin

:error_descarga
echo.
echo ===============================================================================
echo [ERROR] No se pudo descargar el archivo automaticamente.
echo Por favor verifica tu conexion a internet o descarga Node.js
echo manualmente desde el sitio oficial: https://nodejs.org/
echo ===============================================================================
goto fin

:fin
echo.
pause
exit /b 0
