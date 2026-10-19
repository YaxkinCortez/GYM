import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const RUTA_TOKEN = path.join(__dirname, '.token_arranque');

/**
 * Token de arranque compartido entre el proceso "lanzador" (comprobar_y_actualizar.js)
 * y el servidor (server.js). Se guarda en data/.token_arranque:
 *
 *  - comprobar_y_actualizar.js escribe un token NUEVO justo antes de lanzar server.js
 *    (cada doble clic en iniciar_gimnasio.bat => sesion nueva => exige login).
 *  - El reinicio AUTOMATICO por actualizacion NO reescribe este archivo: el proceso
 *    nuevo hereda el mismo token y la sesion del usuario sobrevive al reinicio.
 *  - Si el archivo no existe (arranque directo de server.js), se genera y persiste.
 */
export function obtenerOCrearTokenArranque() {
  try {
    const existente = fs.readFileSync(RUTA_TOKEN, 'utf8').trim();
    if (existente) return existente;
  } catch (_) { /* todavia no existe */ }
  return generarTokenArranque();
}

export function generarTokenArranque() {
  const token = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  try { fs.writeFileSync(RUTA_TOKEN, token, 'utf8'); } catch (_) {}
  return token;
}
