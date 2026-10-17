@echo off
:: codepage standard
cd /d "C:\Users\yaxnn\Downloads\Gimnasio_LaFabrica_Portable\"
"php" -c "C:\Program Files\php\php.ini" -d extension_dir="C:\Program Files\php\ext" -d extension=pdo_sqlite -d extension=sqlite3 -d display_errors=1 -S 127.0.0.1:8080 router.php > "C:\Users\yaxnn\Downloads\Gimnasio_LaFabrica_Portable\server.log" 2>&1
