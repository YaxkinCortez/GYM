@echo off
setlocal enableextensions enabledelayedexpansion
title Gimnasio La Fabrica - Gestor de Inicio

:: ============================================================================
::  REGISTRO (LOG): TODO lo que hace este lanzador queda grabado en
::  data\iniciar.log. El registro del servidor (node server.js) va aparte a
::  data\arranque.log. Adjunta esos dos archivos para reportar cualquier fallo.
::  Tecnica: el .bat se relanza con stdout+stderr redirigidos por PowerShell,
::  que copia todo al log y ademas lo muestra en pantalla (tee). Si PowerShell
::  faltara (nunca en Windows 11), se ejecuta normalmente sin log de lanzador.
:: ============================================================================
cd /d "%~dp0"
if not exist "%~dp0data" mkdir "%~dp0data"
set "GF_LOGFILE=%~dp0data\iniciar.log"

if defined GF_LOG_TEE goto cuerpo
where powershell.exe >nul 2>&1
if errorlevel 1 goto cuerpo
set "GF_LOG_TEE=1"
>"%TEMP%\gf_launch.cmd" echo call "%~f0"
>"%TEMP%\gf_tee.ps1" echo $tmp='%GF_LOGFILE%.tee'; $p = Start-Process -FilePath $env:ComSpec -ArgumentList '/c','%TEMP%\gf_launch.cmd' -PassThru -NoNewWindow -RedirectStandardOutput $tmp -RedirectStandardError "$tmp.err"; $p.WaitForExit(); Add-Content -Path '%GF_LOGFILE%' -Value '===== LANZADOR =====' -Encoding UTF8; Get-Content $tmp -Encoding Unicode -ErrorAction SilentlyContinue | Add-Content -Path '%GF_LOGFILE%' -Encoding UTF8; if ((Get-Item "$tmp.err" -ErrorAction SilentlyContinue).Length -gt 0) { Add-Content -Path '%GF_LOGFILE%' -Value '--- ERRORES ---' -Encoding UTF8; Get-Content "$tmp.err" -Encoding Unicode | Add-Content -Path '%GF_LOGFILE%' -Encoding UTF8 }; Remove-Item $tmp,"$tmp.err" -Force -ErrorAction SilentlyContinue; exit $p.ExitCode
powershell -NoProfile -ExecutionPolicy Bypass -File "%TEMP%\gf_tee.ps1"
set "EC=%errorlevel%"
del "%TEMP%\gf_launch.cmd" >nul 2>&1
del "%TEMP%\gf_tee.ps1" >nul 2>&1
type "%~dp0data\iniciar.log" 2>nul
endlocal & exit /b %EC%

:cuerpo

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

:: 3. Comprobacion de actualizaciones + arranque garantizado.
:: El comprobador (comprobar_y_actualizar.js) AHORA LANZA POR SI SOLO server.js
:: en una ventana nueva (con pause para que se vea cualquier error) antes de
:: terminar, tanto si hay Internet como si no. Esto elimina las dos causas
:: historicas de "No se puede obtener acceso a esta pagina":
::   a) el comprobador quedandose colgado esperando a GitHub y
::   b) procesos Node zombis que dejaban el puerto 3000 bloqueado.
echo [1/2] Comprobando actualizaciones e iniciando servidor...

set "PROYECTO_PORTABLE="
if exist "%~dp0nodejs\node.exe" set "PROYECTO_PORTABLE=1"

:: Si ya hay un servidor vivo respondiendo en el puerto 3000, NO duplicarlo.
netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [OK] El servidor ya se encuentra activo en el puerto 3000.
    goto abrir_navegador
)

:: Codigo 2 = se instalo una version nueva: volver a comprobar hasta quedar al dia.
"%NODE_CMD%" "%~dp0comprobar_y_actualizar.js" --sin-token-nuevo
if errorlevel 2 goto bucle_actualizador

:: Fallback: si NO existe la carpeta nodejs\ portable dentro del proyecto (se usa
:: el Node del sistema), lanzar el servidor desde aqui con ventana visible.
if not defined PROYECTO_PORTABLE (
    start "Gimnasio La Fabrica - Servidor" /MIN cmd /c ""%NODE_CMD%" "%~dp0server.js" & pause"
)
goto lanzador_listo

:bucle_actualizador
"%NODE_CMD%" "%~dp0comprobar_y_actualizar.js" --sin-token-nuevo
if errorlevel 2 goto bucle_actualizador
goto lanzador_listo

:lanzador_listo

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
