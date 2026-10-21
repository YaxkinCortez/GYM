' ============================================================================
' GIMNASIO LA FABRICA - Lanzador 100% en segundo plano (v1.3.2)
' Doble clic en ESTE archivo abre la aplicacion SIN mostrar ninguna consola
' negra: ejecuta iniciar_gimnasio.bat de forma totalmente oculta. El servidor
' queda registrando todo en data\arranque.log e iniciar.log por si hace falta
' diagnosticar algo.
' Si quieres ver las ventanas tecnicas, usa iniciar_gimnasio.bat directamente.
' ============================================================================
Option Explicit
Dim sh, fso, carpeta, bat, cmd
Set sh = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")
carpeta = fso.GetParentFolderName(WScript.ScriptFullName)
bat = fso.BuildPath(carpeta, "iniciar_gimnasio.bat")
If Not fso.FileExists(bat) Then
    MsgBox "No se encontro iniciar_gimnasio.bat junto a este archivo." & vbCrLf & _
           "Carpeta revisada: " & carpeta, vbCritical, "Gimnasio La Fabrica"
    WScript.Quit 1
End If
sh.CurrentDirectory = carpeta
' 0 = ventana oculta; False = no esperar (el lanzador hace su trabajo y cierra)
' sh.Run: 0 = ventana totalmente oculta, False = no esperar a que termine.
sh.Run """" & bat & """", 0, False
WScript.Sleep 3000 ' pequeno margen para que el lanzador despegue antes de cerrar el vbs
