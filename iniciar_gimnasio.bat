@echo off
setlocal enableextensions enabledelayedexpansion
title Gimnasio La Fabrica - Gestor de Inicio

:: ============================================================================
::  REGISTRO (LOG): los pasos clave del lanzador quedan grabados en
::  data\iniciar.log. El registro del servidor (node server.js) va aparte a
::  data\arranque.log. Adjunta esos dos archivos para reportar cualquier fallo.
:: ============================================================================
cd /d "%~dp0"
if not exist "%~dp0data" mkdir "%~dp0data"
set "GF_LOGFILE=%~dp0data\iniciar.log"

:: IMPORTANTE (fix ventana que se cierra): el truco anterior de "tee" con
:: PowerShell relanzaba el .bat dentro de una sesion oculta; cuando ese
:: relanzamiento fallaba, la ventana moria al instante sin mostrar NADA y el
:: log no se creaba. Estructura nueva, a prueba de fallos:
::   - El lanzador corre DIRECTAMENTE en esta ventana visible (sin tee externo).
::   - Cada paso escribe sus propias lineas en data\iniciar.log (append nativo),
::     ademas de mostrarlas en pantalla. Sin cscript, sin PowerShell, sin
::     comillas raras: nada de eso puede matar la ventana.
::   - La ventana NUNCA termina sin pasar por `pause` (tanto al terminar bien
::     como ante error de Node).
if not exist "%~dp0data" mkdir "%~dp0data"

:: 1. Garantizar rutas del sistema en el PATH
set "PATH=%SystemRoot%\system32;%SystemRoot%;%SystemRoot%\System32\Wbem;%SystemRoot%\System32\WindowsPowerShell\v1.0\;%~dp0nodejs;%~dp0;%PATH%"

cls
echo ===============================================================================
echo                GIMNASIO LA FABRICA - SISTEMA INTEGRAL Y POS
echo ===============================================================================
>>"%GF_LOGFILE%" echo ===== [LANZADOR] %DATE% %TIME% =====
set "GF_ARRANQUE=%~dp0data\arranque.log"
>>"%GF_ARRANQUE%" echo ===== [LANZADOR .BAT INICIADO] %DATE% %TIME% =====

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

:: 3. ARRANQUE MODO LOCAL (v1.3.0): NO se consulta ni descarga nada de GitHub.
:: El sistema es 100%% local/offline; las actualizaciones se aplican manualmente
:: (git pull o reemplazo de archivos). Este lanzador solo: detecta Node, inicia
:: server.js y abre la app cuando responde. Mas simple = menos puntos de fallo.
echo [1/2] Iniciando servidor local (modo offline)...

:: Si ya hay un servidor vivo escuchando en el puerto 3000, NO duplicarlo.
netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
if not errorlevel 1 (
    echo [OK] El servidor ya se encuentra activo en el puerto 3000.
    goto verificar_http
)

:: Lanzar server.js EN SEGUNDO PLANO (oculto, sin ventana para el cliente).
:: v1.3.2: toda la consola del servidor se redirige a data\arranque.log via el
:: .bat auxiliar; ademas server.js duplica su propia salida al mismo log. El
:: proceso vive desvinculado de esta ventana y solo se ve en el Administrador
:: de tareas si alguien lo busca. Si algo falla, el diagnostico queda en los
:: logs y esta ventana muestra la explicacion con pausa.
set "BAT_SERVIDOR=%~dp0data\servidor_ventana.bat"
>"%BAT_SERVIDOR%" echo @echo off
>>"%BAT_SERVIDOR%" echo cd /d "%~dp0"
>>"%BAT_SERVIDOR%" echo echo ===== [SERVIDOR SEGUNDO PLANO] %DATE% %TIME% ===== ^>^> "%GF_ARRANQUE%"
>>"%BAT_SERVIDOR%" echo "%NODE_CMD%" "%~dp0server.js" ^>^> "%GF_ARRANQUE%" 2^>^&1
>>"%BAT_SERVIDOR%" echo set CODIGO=!ERRORLEVEL!
>>"%BAT_SERVIDOR%" echo echo [SERVIDOR TERMINO codigo !CODIGO!] %DATE% %TIME% ^>^> "%GF_ARRANQUE%"
start "" /MIN "%COMSPEC%" /c "%BAT_SERVIDOR%"
>>"%GF_LOGFILE%" echo [LANZADOR] Servidor lanzado en segundo plano (sin ventanas visibles) %DATE% %TIME%

:verificar_http
:: Esperar a que el puerto 3000 escuche (max ~15s) y luego verificar respuesta
:: HTTP REAL antes de abrir el navegador. Abrir el navegador "a ciegas" era la
:: causa de ver "No se puede obtener acceso a esta pagina".
set "PUERTO_VIVO="
for /l %%i in (1,1,15) do (
    netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
    if not errorlevel 1 set "PUERTO_VIVO=%%i"
    if defined PUERTO_VIVO goto puerto_escuchando
    ping 127.0.0.1 -n 2 >nul 2>&1
)

:puerto_escuchando
if defined PUERTO_VIVO (
    echo [OK] Puerto 3000 escuchando. Verificando que el servidor responda...
    >>"%GF_LOGFILE%" echo [LANZADOR] Puerto 3000 escuchando %DATE% %TIME%
    >>"%GF_ARRANQUE%" echo [LANZADOR .BAT] Puerto 3000 escuchando %DATE% %TIME%
) else (
    echo [AVISO] El puerto 3000 no quedo escuchando tras 15 segundos.
    echo [AVISO] Revise data\arranque.log y data\iniciar.log para ver el error.
    >>"%GF_LOGFILE%" echo [LANZADOR] AVISO puerto 3000 NO escucha tras 15s %DATE% %TIME%
    goto diagnostico_final
)

where curl.exe >nul 2>&1
if errorlevel 1 goto abrir_navegador
set "HTTP_OK="
for /l %%i in (1,1,10) do (
    curl.exe -s -k -o nul --max-time 3 "http://127.0.0.1:3000/login.php" && set "HTTP_OK=%%i"
    if defined HTTP_OK goto servidor_responde
    ping 127.0.0.1 -n 2 >nul 2>&1
)

:diagnostico_final
echo.
echo ===============================================================================
echo [ERROR] El servidor NO respondio en http://127.0.0.1:3000
echo.
echo Causas posibles y solucion:
echo   1. Abra data\arranque.log  (registro del servidor: alli esta el error real)
echo   2. Abra data\iniciar.log   (registro del lanzador)
echo   3. Si hay procesos Node zombis, cierrelos con: taskkill /F /IM node.exe
echo      y vuelva a ejecutar este archivo.
echo Copie ambos .log para reportar el fallo con evidencia exacta.
echo ===============================================================================
>>"%GF_LOGFILE%" echo ===== [LANZADOR] Fin CON ERROR sin respuesta HTTP %DATE% %TIME% =====
pause
endlocal & exit /b 1

:servidor_responde
echo [OK] Servidor activo y respondiendo en http://127.0.0.1:3000
>>"%GF_LOGFILE%" echo [LANZADOR] Servidor HTTP verificado OK %DATE% %TIME%
>>"%GF_ARRANQUE%" echo [LANZADOR .BAT] Servidor HTTP verificado OK (responde /login.php) %DATE% %TIME%

:abrir_navegador
>>"%GF_ARRANQUE%" echo [LANZADOR .BAT] Abriendo navegador %DATE% %TIME%
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
:: v1.3.2: experiencia de cliente limpia: el servidor corre en segundo plano y
:: la app ya se abrio; esta ventana del lanzador se cierra sola sin pausas ni
:: consolas visibles. Ante errores reales, las rutas con diagnostico y pausa
:: quedan mas arriba; todo el historial vive en data\iniciar.log y arranque.log.
>>"%GF_LOGFILE%" echo ===== [LANZADOR] Fin OK - ventana cerrada, servidor en segundo plano %DATE% %TIME% =====
endlocal & exit /b 0
