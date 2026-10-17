@echo off
setlocal
title Gimnasio La Fabrica - Gestor de Inicio y Actualizaciones
cd /d "%~dp0"

:: 1. Garantizar rutas del sistema en el PATH
set "PATH=%SystemRoot%\system32;%SystemRoot%;%SystemRoot%\System32\Wbem;%SystemRoot%\System32\WindowsPowerShell\v1.0\;%~dp0nodejs;%~dp0;%PATH%"

cls
echo ===============================================================================
echo                GIMNASIO LA FABRICA - SISTEMA INTEGRAL Y POS
echo ===============================================================================
echo.

:: 2. Deteccion de Node.js en el sistema o en la carpeta local
set "NODE_CMD="

:: Comprobar si node funciona en PATH
node -v >nul 2>&1
if not errorlevel 1 goto node_en_path

:: Comprobar en subcarpeta local nodejs\node.exe
if exist "%~dp0nodejs\node.exe" goto node_en_subcarpeta

:: Comprobar en raiz node.exe
if exist "%~dp0node.exe" goto node_en_raiz

:: Comprobar en Program Files estandar
if exist "%ProgramFiles%\nodejs\node.exe" goto node_en_program_files

:: Comprobar en Program Files (x86)
if exist "%ProgramFiles(x86)%\nodejs\node.exe" goto node_en_program_files_x86

:: Comprobar en AppData de usuario
if exist "%LOCALAPPDATA%\Programs\node\node.exe" goto node_en_localappdata

:: Si no se detecto ninguna instalacion, descargar automaticamente Node.js Portable
goto descargar_node_automatico

:node_en_path
set "NODE_CMD=node"
goto node_detectado

:node_en_subcarpeta
set "NODE_CMD=%~dp0nodejs\node.exe"
goto node_detectado

:node_en_raiz
set "NODE_CMD=%~dp0node.exe"
goto node_detectado

:node_en_program_files
set "NODE_CMD=%ProgramFiles%\nodejs\node.exe"
goto node_detectado

:node_en_program_files_x86
set "NODE_CMD=%ProgramFiles(x86)%\nodejs\node.exe"
goto node_detectado

:node_en_localappdata
set "NODE_CMD=%LOCALAPPDATA%\Programs\node\node.exe"
goto node_detectado

:descargar_node_automatico
echo [INFO] No se encontro Node.js en el sistema.
echo [INFO] Descargando version portable oficial de Node.js para Windows...
echo       (Este proceso es rapido y solo se realiza una unica vez)
echo.

if not exist "%~dp0nodejs" mkdir "%~dp0nodejs"

:: Probar descarga con curl si esta presente
where curl.exe >nul 2>&1
if not errorlevel 1 goto descargar_curl

:: Probar descarga con PowerShell si curl no esta
where powershell.exe >nul 2>&1
if not errorlevel 1 goto descargar_powershell

goto error_descargador_no_disponible

:descargar_curl
echo [Descarga] Obteniendo node.exe con curl...
curl.exe -L -k --progress-bar "https://nodejs.org/dist/v20.18.0/win-x64/node.exe" -o "%~dp0nodejs\node.exe"
if exist "%~dp0nodejs\node.exe" goto descarga_completada
echo [Descarga] curl no pudo finalizar. Probando con PowerShell...

:descargar_powershell
echo [Descarga] Obteniendo node.exe con PowerShell...
powershell.exe -ExecutionPolicy Bypass -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; (New-Object System.Net.WebClient).DownloadFile('https://nodejs.org/dist/v20.18.0/win-x64/node.exe', '%~dp0nodejs\node.exe')"
if exist "%~dp0nodejs\node.exe" goto descarga_completada

:error_sin_node
echo.
echo ===============================================================================
echo [ERROR] No se pudo descargar o inicializar Node.js automaticamente.
echo.
echo Para solucionarlo facilmente:
echo   1. Asegurese de tener conexion a Internet.
echo   2. O descargue e instale Node.js LTS oficial desde: https://nodejs.org/
echo   3. Luego vuelva a hacer doble clic en este archivo iniciar_gimnasio.bat
echo ===============================================================================
echo.
pause
exit /b 1

:descarga_completada
echo [OK] Node.js Portable descargado e integrado con exito.
set "NODE_CMD=%~dp0nodejs\node.exe"
echo.

:node_detectado
echo [OK] Motor Node.js detectado: %NODE_CMD%
echo.

:: 3. Verificar comprobador y actualizador de GitHub
if not exist "%~dp0comprobar_y_actualizar.js" goto saltar_actualizador

echo [1/3] Comprobando si existe una nueva version en GitHub...
echo.

"%NODE_CMD%" "%~dp0comprobar_y_actualizar.js"
set "COD_ACT=%ERRORLEVEL%"

if "%COD_ACT%"=="2" goto relanzar_por_actualizacion
if "%COD_ACT%"=="1" echo [AVISO] Continuando con la version local para asegurar operacion continua.
goto verificar_servidor

:relanzar_por_actualizacion
echo.
echo ===============================================================================
echo  [ACTUALIZACION EXITOSA] Nueva version descargada e instalada con exito.
echo  Tu base de datos local fue preservada intacta sin modificaciones.
echo.
echo  Relanzando iniciar_gimnasio.bat para empezar la webapp actualizada...
echo ===============================================================================
ping 127.0.0.1 -n 3 >nul
start "" cmd /c "%~f0"
exit /b 0

:saltar_actualizador
echo [INFO] Iniciando directamente la aplicacion local...

:verificar_servidor
echo.
echo [2/3] Verificando estado del servidor web (Puerto 3000)...

:: Comprobar si ya esta escuchando en el puerto 3000
netstat -aon | findstr :3000 | findstr LISTENING >nul 2>&1
if not errorlevel 1 (
    echo [OK] El servidor ya se encuentra activo en http://127.0.0.1:3000
    goto abrir_navegador
)

echo [INFO] Arrancando servidor en segundo plano...
powershell -NoProfile -NonInteractive -WindowStyle Hidden -Command "Start-Process -FilePath '%NODE_CMD%' -ArgumentList '\"%~dp0server.js\"' -WorkingDirectory '%~dp0' -WindowStyle Hidden" >nul 2>&1
if errorlevel 1 (
    start /B "" "%NODE_CMD%" "%~dp0server.js" > "%~dp0server.log" 2>&1
)

:: Esperar a que el puerto 3000 este listo
echo [INFO] Esperando inicio del servidor...
set "ACTIVO=0"
for /l %%i in (1,1,10) do (
    netstat -aon | findstr :3000 | findstr LISTENING >nul 2>&1
    if not errorlevel 1 (
        set "ACTIVO=1"
        goto abrir_navegador
    )
    timeout /t 1 /nobreak >nul
)

if "%ACTIVO%"=="0" (
    echo.
    echo ===============================================================================
    echo [AVISO] El servidor en segundo plano tardo mas de lo esperado en iniciar.
    echo Intentando inicio en primer plano para mostrar detalles...
    echo ===============================================================================
    "%NODE_CMD%" "%~dp0server.js"
    pause
    exit /b 1
)

:abrir_navegador
echo.
echo [3/3] Abriendo aplicacion en su ventana independiente...
echo ===============================================================================
echo  MODO APP NATIVO DE ESCRITORIO
echo  URL: http://127.0.0.1:3000/login.php
echo ===============================================================================
echo.

:: 1. Intentar con Microsoft Edge (presente en 100% de Windows 10 y Windows 11)
if exist "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)
if exist "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%ProgramFiles%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)
if exist "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" (
    start "" "%LOCALAPPDATA%\Microsoft\Edge\Application\msedge.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)

:: 2. Intentar con Google Chrome
if exist "%ProgramFiles%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)
if exist "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" (
    start "" "%ProgramFiles(x86)%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)
if exist "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" (
    start "" "%LOCALAPPDATA%\Google\Chrome\Application\chrome.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)

:: 3. Intentar con Brave Browser
if exist "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" (
    start "" "%ProgramFiles%\BraveSoftware\Brave-Browser\Application\brave.exe" --app="http://127.0.0.1:3000/login.php"
    goto fin_lanzador
)

:: 4. Apertura directa por comando registrado de aplicacion
start msedge --app="http://127.0.0.1:3000/login.php" >nul 2>&1
if not errorlevel 1 goto fin_lanzador

start chrome --app="http://127.0.0.1:3000/login.php" >nul 2>&1
if not errorlevel 1 goto fin_lanzador

:: 5. Fallback secundario con navegador estandar
start "" "http://127.0.0.1:3000/login.php"

:fin_lanzador
echo [OK] Gimnasio iniciado exitosamente. Cerrando consola...
timeout /t 1 /nobreak >nul
exit

:error_descargador_no_disponible
echo.
echo ===============================================================================
echo [ERROR] No se encontro ni curl ni PowerShell para descargar Node.js.
echo Por favor instale Node.js LTS manualmente desde: https://nodejs.org/
echo ===============================================================================
echo.
pause
exit /b 1
