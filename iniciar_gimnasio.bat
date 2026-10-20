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
>>"%GF_LOGFILE%" echo [LANZADOR] Iniciando comprobador de actualizaciones %DATE% %TIME%
>>"%GF_ARRANQUE%" echo [LANZADOR .BAT] Llamando al comprobador %DATE% %TIME%
"%NODE_CMD%" "%~dp0comprobar_y_actualizar.js" --sin-token-nuevo
if errorlevel 2 goto bucle_actualizador

:: Fallback de SEGURIDAD: lanzar server.js desde AQUI con ventana visible, SIEMPRE
:: (aunque el comprobador ya intente abrirlo). server.js verifica con HTTP real
:: quien ocupa el puerto 3000: si hay un servidor vivo NO se duplica y sale
:: limpio; si hay un zombie lo elimina y toma el puerto. Antes este paso estaba
:: condicionado a que NO existiera nodejs\ portable: con portable instalado
:: NUNCA se ejecutaba y quedabamos dependientes 100%% del lanzamiento interno del
:: comprobador (el unico que fallaba en silencio -> "no se puede acceder").
:: Escribir el comando del servidor en un .bat auxiliar (sin comillas anidadas fragiles).
set "BAT_SERVIDOR=%~dp0data\servidor_ventana.bat"
>"%BAT_SERVIDOR%" echo @echo off
>>"%BAT_SERVIDOR%" echo title Gimnasio La Fabrica - Servidor
>>"%BAT_SERVIDOR%" echo echo ===== [VENTANA SERVIDOR desde BAT] %DATE% %TIME% =====
>>"%BAT_SERVIDOR%" echo "%NODE_CMD%" "%~dp0server.js" ^>^> "%GF_ARRANQUE%" 2^>^&1
>>"%BAT_SERVIDOR%" echo set CODIGO=!ERRORLEVEL!
>>"%BAT_SERVIDOR%" echo echo [SERVIDOR TERMINO codigo !CODIGO!] %DATE% %TIME%
>>"%BAT_SERVIDOR%" echo echo [SERVIDOR TERMINO codigo !CODIGO!] %DATE% %TIME% ^>^> "%GF_ARRANQUE%"
>>"%BAT_SERVIDOR%" echo echo.
>>"%BAT_SERVIDOR%" echo echo Si la app no abrio, copie data\arranque.log y data\iniciar.log para diagnosticar.
>>"%BAT_SERVIDOR%" echo pause
start "Gimnasio La Fabrica - Servidor" /MIN "%COMSPEC%" /c "%BAT_SERVIDOR%"
>>"%GF_LOGFILE%" echo [LANZADOR] Ventana del servidor lanzada desde el .bat %DATE% %TIME%
goto lanzador_listo

:bucle_actualizador
>>"%GF_LOGFILE%" echo [LANZADOR] Actualizacion instalada, re-comprobando... %DATE% %TIME%
"%NODE_CMD%" "%~dp0comprobar_y_actualizar.js" --sin-token-nuevo
if errorlevel 2 goto bucle_actualizador
goto lanzador_listo

:lanzador_listo

:: Esperar a que el puerto 3000 este ESCUCHANDO (maximo ~15s). Ojo: escuchar el
:: puerto no significa responder; antes se abria el navegador aqui mismo y por
:: eso salia "No se puede obtener acceso a esta pagina" cuando el proceso que
:: ocupaba el puerto era un zombie o el servidor moria despues. Ahora la espera
:: real de respuesta HTTP se hace aqui mismo, antes de abrir el navegador.
set "PUERTO_VIVO="
for /l %%i in (1,1,15) do (
    netstat -ano 2>nul | findstr /R /C:":3000 .*LISTENING" >nul 2>&1
    if not errorlevel 1 set "PUERTO_VIVO=%%i"
    if not defined PUERTO_VIVO ping 127.0.0.1 -n 2 >nul 2>&1
)

:puerto_escuchando
if defined PUERTO_VIVO (
    echo [OK] Puerto 3000 escuchando. Verificando que el servidor responda...
    >>"%GF_LOGFILE%" echo [LANZADOR] Puerto 3000 escuchando %DATE% %TIME%
    >>"%GF_ARRANQUE%" echo [LANZADOR .BAT] Puerto 3000 escuchando %DATE% %TIME%
) else (
    echo [AVISO] El puerto 3000 no quedo escuchando tras 15 segundos.
    echo [AVISO] Revise data\arranque.log ^^^y data\iniciar.log para ver el error.
    >>"%GF_LOGFILE%" echo [LANZADOR] AVISO puerto 3000 NO escucha tras 15s %DATE% %TIME%
)

:: Verificacion HTTP REAL antes de abrir el navegador (max ~10s mas).
:: Se pide /login.php con curl (incluido en Windows 10/11); si responde 2xx/3xx,
:: recien entonces se abre la app. Si no responde, se muestra el diagnostico.
if not defined PUERTO_VIVO goto diagnostico_final
where curl.exe >nul 2>&1
if errorlevel 1 goto abrir_navegador
set "HTTP_OK="
for /l %%i in (1,1,10) do (
    curl.exe -s -k -o nul --max-time 3 "http://127.0.0.1:3000/login.php" && set "HTTP_OK=%%i"
    if not defined HTTP_OK ping 127.0.0.1 -n 2 >nul 2>&1
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
echo.
echo ===============================================================================
echo  [OK] Gimnasio La Fabrica iniciado correctamente.
echo  Puede cerrar esta ventana o dejarla abierta; el servidor sigue corriendo.
echo  Registros por si algo falla: data\iniciar.log y data\arranque.log
echo ===============================================================================
>>"%GF_LOGFILE%" echo ===== [LANZADOR] Fin OK %DATE% %TIME% =====
:: FIX VENTANA QUE SE CIERRA: aqui estaba un `exit` (y antes un tee oculto de
:: PowerShell) que mataba la consola sin dejar ver nada. Ahora SIEMPRE pausa.
pause
endlocal & exit /b 0
