import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
export const RUTA_VERSION_JSON = path.join(__dirname, 'version.json');

/**
 * Parsea una versión en sus 3 componentes numéricos [mayor, menor, parche].
 * Limpia prefijos como 'v', sufijos de commits o hashes.
 * Ejemplo: "1.0.2" -> [1, 0, 2]
 * "v1.0.1-7c54118" -> [1, 0, 1]
 */
export function parsearVersion(vStr) {
  if (!vStr || typeof vStr !== 'string') return [1, 0, 5];
  const match = vStr.match(/(\d+)\.(\d+)(?:\.(\d+))?/);
  if (!match) return [1, 0, 5];
  const mayor = parseInt(match[1], 10) || 0;
  const menor = parseInt(match[2], 10) || 0;
  const parche = parseInt(match[3] || '0', 10) || 0;
  return [mayor, menor, parche];
}

export function formatearVersion(arr) {
  const [mayor, menor, parche] = arr;
  return `${mayor}.${menor}.${parche}`;
}

/**
 * Incrementa la versión según las reglas solicitadas:
 * - Inicia en 1.0.2
 * - Cada corrección suma 1 al último dígito (parche): ej. 1.0.2 -> 1.0.3
 * - Si el último dígito llega a 10: parche vuelve a 0 y menor suma 1 (ej: 1.1.9 -> 1.2.0)
 * - Si el segundo dígito llega a 10: menor vuelve a 0 y mayor suma 1 (ej: 1.9.9 -> 2.0.0)
 * - Si el primer dígito llega a 10: continúa el curso normal de los números (10.0.0, 11.0.0...)
 */
export function incrementarVersion(vStr) {
  let [mayor, menor, parche] = parsearVersion(vStr);
  parche += 1;
  if (parche >= 10) {
    parche = 0;
    menor += 1;
  }
  if (menor >= 10) {
    menor = 0;
    mayor += 1;
  }
  return formatearVersion([mayor, menor, parche]);
}

/**
 * Compara dos versiones.
 * Retorna:
 *  > 0 si vA > vB (vA es mayor)
 *  = 0 si vA == vB
 *  < 0 si vA < vB (vA es menor)
 */
export function compararVersiones(vA, vB) {
  const [a1, a2, a3] = parsearVersion(vA);
  const [b1, b2, b3] = parsearVersion(vB);

  if (a1 !== b1) return a1 - b1;
  if (a2 !== b2) return a2 - b2;
  return a3 - b3;
}

export function obtenerVersionLocal() {
  try {
    if (fs.existsSync(RUTA_VERSION_JSON)) {
      const data = JSON.parse(fs.readFileSync(RUTA_VERSION_JSON, 'utf-8'));
      if (data && data.version) {
        return formatearVersion(parsearVersion(data.version));
      }
    }
  } catch (_) {}
  return '1.0.5';
}

export function guardarVersionLocal(nuevaVersion, metadata = {}) {
  try {
    const versionNormalizada = formatearVersion(parsearVersion(nuevaVersion));
    let data = {};
    if (fs.existsSync(RUTA_VERSION_JSON)) {
      try {
        data = JSON.parse(fs.readFileSync(RUTA_VERSION_JSON, 'utf-8'));
      } catch (_) {}
    }
    data.version = versionNormalizada;
    data.fechaActualizacion = new Date().toISOString().split('T')[0];
    if (metadata.descripcion) data.descripcion = metadata.descripcion;
    if (metadata.ultimoCommitSha) data.ultimoCommitSha = metadata.ultimoCommitSha;
    data.estado = 'actualizado_exitoso';
    fs.writeFileSync(RUTA_VERSION_JSON, JSON.stringify(data, null, 2), 'utf-8');
    return versionNormalizada;
  } catch (err) {
    console.warn('[VersionManager] No se pudo guardar version.json:', err.message);
    return nuevaVersion;
  }
}
