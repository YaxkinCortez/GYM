#!/usr/bin/env bash
# Script de inicio y actualización automática para Gimnasio La Fábrica (Linux / macOS)

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$DIR"

echo "==============================================================================="
echo "               GIMNASIO LA FÁBRICA - SISTEMA INTEGRAL Y POS"
echo "==============================================================================="
echo ""
echo "[PASO 1/2] Comprobando si existe una nueva versión en GitHub..."
echo ""

node "$DIR/comprobar_y_actualizar.js"
CODIGO_ACTUALIZACION=$?

if [ $CODIGO_ACTUALIZACION -eq 2 ]; then
    echo ""
    echo "==============================================================================="
    echo " [ACTUALIZACIÓN EXITOSA] Nueva versión descargada e instalada con éxito."
    echo " Tu base de datos local fue preservada intacta sin modificaciones."
    echo ""
    echo " Relanzando iniciar_gimnasio.sh para empezar la webapp actualizada..."
    echo "==============================================================================="
    sleep 2
    exec "$0" "$@"
fi

echo ""
echo "[PASO 2/2] Iniciando aplicación web Gimnasio La Fábrica..."
echo "Acceso local: http://localhost:3000"
echo "Presiona Ctrl+C para detener el servidor."
echo "==============================================================================="
echo ""

node "$DIR/server.js"
