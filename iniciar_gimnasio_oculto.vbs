' ============================================================================
' GIMNASIO LA FABRICA - Lanzador 100% en segundo plano (v1.3.3)
' Doble clic en ESTE archivo: abre la aplicacion SIN mostrar NINGUNA consola.
' Lanza server.js con ventana totalmente oculta (WScript.Shell.Run ...,0,...):
' no existe ninguna ventana cmd que el cliente pueda cerrar y matar el
' servidor. Solo se ve la app del gimnasio. Todo queda registrado en
' data\arranque.log e data\iniciar.log para diagnostico tecnico.
' ============================================================================
Dim sh, fso, carpeta
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
carpeta = fso.GetParentFolderName(WScript.ScriptFullName)

If Not fso.FileExists(fso.BuildPath(carpeta, "server.js")) Then
    MsgBox "No se encontro server.js junto a este archivo." & vbCrLf & _
           "Carpeta revisada: " & carpeta, vbCritical, "Gimnasio La Fabrica"
    WScript.Quit 1
End If
sh.CurrentDirectory = carpeta

' ---- 1. Resolver Node.js (portable local primero; despues el del sistema) --
Dim nodeCmd
nodeCmd = ""
If fso.FileExists(fso.BuildPath(carpeta & "\nodejs", "node.exe")) Then
    nodeCmd = fso.BuildPath(carpeta & "\nodejs", "node.exe")
ElseIf fso.FileExists(fso.BuildPath(carpeta, "node.exe")) Then
    nodeCmd = fso.BuildPath(carpeta, "node.exe")
End If
If nodeCmd = "" Then
    If fso.FileExists("C:\Program Files\nodejs\node.exe") Then
        nodeCmd = "C:\Program Files\nodejs\node.exe"
    Else
        nodeCmd = "node"   ' ultima opcion: debe estar en el PATH del sistema
    End If
End If

' ---- 2. Carpeta data y rutas de log ----------------------------------------
If Not fso.FolderExists(fso.BuildPath(carpeta, "data")) Then fso.CreateFolder(fso.BuildPath(carpeta, "data"))
Dim rutaLog
rutaLog = fso.BuildPath(carpeta & "\data", "arranque.log")

' ---- 3. Si ya hay algo escuchando en el puerto 3000, NO duplicar ------------
Dim yaVivo, salida
yaVivo = False
On Error Resume Next
salida = sh.Exec("%ComSpec% /c netstat -ano | findstr :3000").StdOut.ReadAll()
If Err.Number = 0 Then
    If InStr(salida, ":3000") > 0 Then yaVivo = True
    Err.Clear
End If
On Error GoTo 0

' ---- 4. Lanzar el servidor en SEGUNDO PLANO REAL (ventana 0 = oculta) -------
If Not yaVivo Then
    Dim linea
    linea = """" & nodeCmd & """ """ & fso.BuildPath(carpeta, "server.js") & """ >> """ & rutaLog & """ 2>&1"
    sh.Run linea, 0, False
    WScript.Sleep 4000
End If

' ---- 5. Esperar respuesta HTTP REAL antes de abrir la app -------------------
Dim ok, i, http
ok = yaVivo
For i = 1 To 25
    If ok Then Exit For
    On Error Resume Next
    http = sh.Exec("%ComSpec% /c curl.exe -s -k -o NUL --max-time 2 http://127.0.0.1:3000/login.php && echo GF_OK").StdOut.ReadAll()
    Err.Clear
    On Error GoTo 0
    If InStr(http, "GF_OK") > 0 Then ok = True
    If Not ok Then WScript.Sleep 1000
Next

' ---- 6. Abrir la app en Edge/Chrome modo ventana propia (sin barras) --------
Dim urlApp, navegador
urlApp = "http://127.0.0.1:3000/login.php"
navegador = ""
If fso.FileExists("C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe") Then
    navegador = "C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe"
ElseIf fso.FileExists("C:\Program Files\Microsoft\Edge\Application\msedge.exe") Then
    navegador = "C:\Program Files\Microsoft\Edge\Application\msedge.exe"
ElseIf fso.FileExists("C:\Program Files\Google\Chrome\Application\chrome.exe") Then
    navegador = "C:\Program Files\Google\Chrome\Application\chrome.exe"
ElseIf fso.FileExists("C:\Program Files (x86)\Google\Chrome\Application\chrome.exe") Then
    navegador = "C:\Program Files (x86)\Google\Chrome\Application\chrome.exe"
End If

If navegador <> "" Then
    ' v1.4.0: perfil dedicado + start-fullscreen para que la app abra REALMENTE
    ' a pantalla completa (con el perfil normal del usuario, Edge/Chrome ignora
    ' --start-fullscreen porque reutiliza una ventana existente sin ese flag).
    args = " --app=""" & urlApp & """ --start-fullscreen --user-data-dir=""" & carpeta & "\data\.perfil_navegador"" --no-first-run"
    sh.Run """" & navegador & """ & args, 1, False
Else
    sh.Run urlApp, 1, False   ' navegador predeterminado de Windows
End If

' ---- 7. Si el servidor nunca respondio, avisar con evidencia ----------------
If Not ok Then
    MsgBox "El servidor no respondio en http://localhost:3000." & vbCrLf & _
           "Revise el registro data\arranque.log y vuelva a intentarlo.", _
           vbExclamation, "Gimnasio La Fabrica"
End If
