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
  // Lanzamiento interno (server.js -> comprobar_y_actualizar.js como respaldo,
  // o relanzamiento del .bat tras instalar una actualizacion con GF_REINTENTO/
  // GF_RELANZADO/GF_ALREADY_RAN): NO se emite token nuevo aqui porque eso
  // invalidaria la sesion a mitad del arranque y podria provocar redirecciones
  // en bucle. El token nuevo solo se genera al ejecutar iniciar_gimnasio.bat
  // desde cero (doble clic normal, sin estas variables de entorno).
  if (process.env.GF_ALREADY_RAN === '1' || process.env.GF_RELANZADO === '1' || process.env.GF_REINTENTO) {
    return obtenerOCrearTokenArranque();
  }
  const token = Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
  try { fs.writeFileSync(RUTA_TOKEN, token, 'utf8'); } catch (_) {}
  return token;
}
