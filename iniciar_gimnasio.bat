@echo off
setlocal
title Gimnasio La Fabrica - Gestor de Inicio
cd /d "%~dp0"

:: 1. Garantizar rutas del sistema en el PATH
set "PATH=%SystemRoot%\system32;%SystemRoot%;%SystemRoot%\System32\Wbem;%SystemRoot%\System32\WindowsPowerShell\v1.0\;%~dp0nodejs;%~dp0;%PATH%"

cls
echo ===============================================================================
echo                GIMNASIO LA FABRICA - SISTEMA INTEGRAL Y POS
echo ===============================================================================
echo.

:: 2. Deteccion robusta de Node.js (Prioriza la version portable local para funcionar 100%% sin Internet)
set "NODE_CMD="

:: A. Comprobar en subcarpeta local nodejs\node.exe (Portable offline)
if not defined NODE_CMD if exist "%~dp0nodejs\node.exe" set "NODE_CMD=%~dp0nodejs\node.exe"

:: B. Comprobar en raiz node.exe
if not defined NODE_CMD if exist "%~dp0node.exe" set "NODE_CMD=%~dp0node.exe"

:: C. Comprobar en PATH del sistema
for /f "tokens=*" %%I in ('where node.exe 2^>nul') do if not defined NODE_CMD set "NODE_CMD=%%I"
if not defined NODE_CMD for /f "tokens=*" %%I in ('where node 2^>nul') do if not defined NODE_CMD set "NODE_CMD=%%I"

:: D. Comprobar en Program Files estandar
if not defined NODE_CMD if exist "%ProgramFiles%\nodejs\node.exe" set "NODE_CMD=%ProgramFiles%\nodejs\node.exe"

:: E. Comprobar en Program Files (x86)
if not defined NODE_CMD if exist "%ProgramFiles(x86)%\nodejs\node.exe" set "NODE_CMD=%ProgramFiles(x86)%\nodejs\node.exe"

:: F. Comprobar en AppData de usuario
if not defined NODE_CMD if exist "%LOCALAPPDATA%\Programs\node\node.exe" set "NODE_CMD=%LOCALAPPDATA%\Programs\node\node.exe"

:: Si se detecto, continuar directamente al arranque
if defined NODE_CMD goto node_detectado

:: Fallback rapido probando ejecucion directa de 'node'
node -v >nul 2>&1
if not errorlevel 1 (
    set "NODE_CMD=node"
    goto node_detectado
)

:: Si no se detecto ninguna instalacion, intentar descargar Node.js si hay red
goto descargar_node_automatico

:descargar_node_automatico
echo [INFO] No se encontro Node.js en el sistema.
echo [INFO] Intentando descargar version portable oficial de Node.js...
echo.

if not exist "%~dp0nodejs" mkdir "%~dp0nodejs"

where curl.exe >nul 2>&1
if not errorlevel 1 (
    curl.exe -L -k --progress-bar --max-time 15 "https://nodejs.org/dist/v20.18.0/win-x64/node.exe" -o "%~dp0nodejs\node.exe"
    if exist "%~dp0nodejs\node.exe" goto descarga_completada
)

where powershell.exe >nul 2>&1
if not errorlevel 1 (
    powershell.exe -ExecutionPolicy Bypass -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; (New-Object System.Net.WebClient).DownloadFile('https://nodejs.org/dist/v20.18.0/win-x64/node.exe', '%~dp0nodejs\node.exe')"
    if exist "%~dp0nodejs\node.exe" goto descarga_completada
)

echo.
echo ===============================================================================
echo [ERROR] No se encontro Node.js en este equipo.
echo.
echo Para solucionarlo facilmente:
echo   1. Descargue e instale Node.js LTS oficial desde: https://nodejs.org/
echo   2. O coloque 'node.exe' dentro de la carpeta 'nodejs' de este programa.
echo   3. Luego vuelva a hacer doble clic en iniciar_gimnasio.bat
echo ===============================================================================
echo.
pause
exit /b 1

:descarga_completada
echo [OK] Node.js Portable descargado con exito.
set "NODE_CMD=%~dp0nodejs\node.exe"
echo.

:node_detectado
echo [OK] Motor Node.js detectado: %NODE_CMD%
echo.

:: 3. Inicio inmediato del servidor (Cero bloqueos, funciona 100%% sin Internet)
echo [1/2] Iniciando servidor local en segundo plano...

:: Comprobar si ya esta respondiendo en el puerto 3000
netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [OK] El servidor ya se encuentra activo en el puerto 3000.
    goto abrir_navegador
)

:: Arrancar server.js en segundo plano de manera limpia y silenciosa
start "Gimnasio La Fabrica - Servidor" /MIN cmd /c ""%NODE_CMD%" "%~dp0server.js""

:: Esperar a que el puerto 3000 este escuchando (maximo 6 intentos)
for /l %%i in (1,1,6) do (
    netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
    if not errorlevel 1 goto servidor_listo
    ping 127.0.0.1 -n 2 >nul 2>&1
)

:servidor_listo
echo [OK] Servidor activo en http://127.0.0.1:3000

:abrir_navegador
echo.
echo [2/2] Abriendo pantalla del gimnasio...
echo ===============================================================================
echo  GIMNASIO LA FABRICA - MODO ESCRITORIO (100%% OPERATIVO SIN INTERNET)
echo  URL: http://127.0.0.1:3000/login.php
echo ===============================================================================
echo.

set "URL_APP=http://127.0.0.1:3000/login.php"

:: 1. Microsoft Edge en modo ventana app nativa (Windows 10 / Windows 11)
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app="%URL_APP%"
    goto fin_lanzador
)
if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app="%URL_APP%"
    goto fin_lanzador
)
if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" --app="%URL_APP%"
    goto fin_lanzador
)

:: 2. Google Chrome en modo ventana app nativa
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app="%URL_APP%"
    goto fin_lanzador
)
if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" --app="%URL_APP%"
    goto fin_lanzador
)
if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" (
    start "" "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" --app="%URL_APP%"
    goto fin_lanzador
)

:: 3. Navegador predeterminado de Windows (SIEMPRE seguro y universal)
start "" "%URL_APP%"

:fin_lanzador
echo.
echo ===============================================================================
echo  [OK] Gimnasio La Fabrica iniciado correctamente.
echo  Cerrando consola del lanzador...
echo ===============================================================================
echo.
ping 127.0.0.1 -n 2 >nul 2>&1
exit
