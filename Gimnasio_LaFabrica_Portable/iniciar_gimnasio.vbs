' ====================================================================
'  LANZADOR 100% SILENCIOSO (SIN VENTANA DE COMANDOS)
'  Gimnasio La Fabrica - SQLite Desktop
' ====================================================================
Set WshShell = CreateObject("WScript.Shell")
Set FSO = CreateObject("Scripting.FileSystemObject")
strDir = FSO.GetParentFolderName(WScript.ScriptFullName)
' Ejecutar iniciar_gimnasio.bat con ventana oculta (0) sin esperar (False)
WshShell.Run "cmd.exe /c """ & strDir & "\iniciar_gimnasio.bat""", 0, False
