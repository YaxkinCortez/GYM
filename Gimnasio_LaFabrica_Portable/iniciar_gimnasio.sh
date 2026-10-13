#!/usr/bin/env bash
cd "$(dirname "$0")"
echo "Iniciando Gimnasio La Fábrica en http://127.0.0.1:8080 ..."
php -S 127.0.0.1:8080 -t . router.php &
sleep 1
if which xdg-open > /dev/null; then
  xdg-open http://127.0.0.1:8080/
elif which open > /dev/null; then
  open http://127.0.0.1:8080/
fi
