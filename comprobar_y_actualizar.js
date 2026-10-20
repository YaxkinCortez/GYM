#!/usr/bin/env node
/**
 * Comprobador y Actualizador Autónomo de Gimnasio La Fábrica
 * 
 * Flujo:
 * 1. Comprueba en GitHub (YaxkinCortez/GYM) si existe una versión más reciente.
 * 2. Si existe nueva versión:
 *    - Realiza respaldo preventivo de la base de datos local.
 *    - Descarga el paquete de actualización automáticamente.
 *    - Instala los archivos actualizados protegiendo la base de datos local (basedatos_local.json).
 *    - Regenera el archivo Gimnasio_La_Fabrica_Completo.zip.
 *    - Termina con código de salida 2 (para que iniciar_gimnasio.bat se vuelva a lanzar).
 * 3. Si no hay actualización:
 *    - Termina con código de salida 0 (para que iniciar_gimnasio.bat continúe e inicie la webapp).
 * 4. Si ocurre un fallo de conexión o red:
 *    - Termina con código de salida 1 (para continuar con la versión local sin interrumpir el gimnasio).
 */

import fs from 'fs';
import os from 'os';
import net from 'net';
import path from 'path';
import https from 'https';
import http from 'http';
import dns from 'dns';
import { execSync, spawn } from 'child_process';
import { fileURLToPath } from 'url';
import AdmZip from 'adm-zip';
import {
  parsearVersion,
  formatearVersion,
  incrementarVersion,
  compararVersiones,
  maxVersionSegura,
  obtenerVersionLocal,
  guardarVersionLocal
} from './data/versionManager.js';
import { generarTokenArranque } from './data/arranqueToken.js';

// Cada EJECUCION del lanzador (doble clic en iniciar_gimnasio.bat) es un
// arranque "desde cero": se emite un token de arranque nuevo que server.js
// leera al iniciarse. Eso invalida automaticamente cualquier sesion emitida
// por el lanzamiento anterior (el usuario debe volver a iniciar sesion).
// El reinicio AUTOMATICO por actualizacion puede pasar por aqui cuando el
// .bat se relanza (con GF_REINTENTO/GF_RELANZADO en el entorno): en ese caso
// NO se emite token nuevo, para no cerrar la sesion a mitad del arranque.
if (!process.argv.includes('--sin-token-nuevo') && process.env.GF_RELANZADO !== '1' && !process.env.GF_REINTENTO) {
  try { generarTokenArranque(); } catch (_) {}
}

// ==================== GARANTIA DE SALIDA (anti "no arranca") ====================
// Comportamiento historico del bug: en equipos sin Node instalado o con red
// inestable, este proceso NUNCA terminaba (quedaba esperando una descarga de
// GitHub indefinidamente), el .bat jamas llegaba a lanzar server.js y el
// navegador mostraba "No se puede obtener acceso a esta pagina".
// Regla absoluta: este comprobador SIEMPRE termina solo, como maximo en 20 s.
const LIMITE_TOTAL_MS = 20000;
let finalizado = false;
function terminarSiempre(codigo, motivo) {
  if (finalizado) return;
  finalizado = true;
  try {
    if (motivo) console.log(`\n [GARANTIA DE SALIDA] ${motivo}`);
  } catch (_) {}
  // Salida FORZADA: si quedara alguna peticion HTTP colgada, no impedira que
  // el lanzador continue hacia server.js.
  process.exit(codigo);
}
setTimeout(() => terminarSiempre(0, 'Tiempo limite global (20 s) alcanzado. Continuando con la version local instalada.'), LIMITE_TOTAL_MS);

// Dependencias criticas: si faltan (p. ej. se borro node_modules o la
// instalacion queda incompleta), avisar con mensaje claro en vez de fallar.
try {
  for (const dep of ['express', 'express-session', 'cookie-parser', 'ejs', 'adm-zip']) {
    if (!fs.existsSync(path.join(__dirname, 'node_modules', dep))) {
      console.log(` [AVISO] Falta la dependencia "${dep}". Ejecuta "npm install" en esta carpeta.`);
    }
  }
} catch (_) {}

// Mataprocesos huérfanos: limpiar nodos zombies que bloquean el puerto 3000.
// Solo mata procesos cuyo comando completo menciona ESTE proyecto (server.js
// u otro comprobar_y_actualizar.js colgado): nunca toca otros programas Node.
async function puertoEstaOcupado(puerto) {
  return new Promise((resolve) => {
    const probar = net.connect({ host: '127.0.0.1', port: puerto });
    const t = setTimeout(() => { probar.destroy(); resolve(false); }, 800);
    probar.on('connect', () => { clearTimeout(t); probar.destroy(); resolve(true); });
    probar.on('error', () => { clearTimeout(t); resolve(false); });
  });
}

function matarNodezombies(puerto) {
  let muertos = 0;
  try {
    if (process.platform === 'win32') {
      // wmic devuelve lineas "ComandoLinea|PID"; PowerShell Get-CimInstance
      // como respaldo si wmic no existe (Windows 11 lo retira).
      let lineas = [];
      try {
        const out = execSync(
          'wmic process where "name=\'node.exe\'" get ProcessId,CommandLine /format:list',
          { encoding: 'utf8', timeout: 6000 }
        );
        let cmdActual = '';
        for (const l of out.split(/\r?\n/)) {
          if (l.startsWith('CommandLine=')) cmdActual = l.slice(12);
          else if (l.startsWith('ProcessId=')) {
            const pid = parseInt(l.slice(10), 10);
            if (pid && pid !== process.pid && /server\.js|comprobar_y_actualizar/.test(cmdActual)) {
              lineas.push(pid);
            }
          }
        }
      } catch (_) {
        try {
          const ps = `Get-CimInstance Win32_Process -Filter "Name='node.exe'" | Select-Object ProcessId,CommandLine | ConvertTo-Json`;
          const json = JSON.parse(execSync(`powershell -NoProfile -Command "${ps.replace(/"/g, '\\"')}"`, { encoding: 'utf8', timeout: 8000 }).trim() || '[]');
          const arr = Array.isArray(json) ? json : [json];
          for (const p of arr) {
            if (p && p.ProcessId && p.ProcessId !== process.pid && /server\.js|comprobar_y_actualizar/.test(p.CommandLine || '')) {
              lineas.push(p.ProcessId);
            }
          }
        } catch (_) {}
      }
      for (const pid of lineas) {
        try { execSync(`taskkill /F /PID ${pid}`, { stdio: 'ignore', timeout: 4000 }); muertos++; } catch (_) {}
      }
    } else {
      try {
        const out = execSync(`ps -eo pid,args`, { encoding: 'utf8', timeout: 4000 });
        for (const linea of out.split('\n')) {
          const m = linea.trim().match(/^(\d+)\s+(.*)$/);
          if (!m) continue;
          const pid = parseInt(m[1], 10);
          if (pid !== process.pid && /node.*(server\.js|comprobar_y_actualizar)/.test(m[2])) {
            try { process.kill(pid, 'SIGKILL'); muertos++; } catch (_) {}
          }
        }
      } catch (_) {}
    }
  } catch (_) {}
  return muertos;
}

// Si el puerto ya responde, hay un servidor VIVO: no se duplica ni se mata.
// Si hay zombies pero el puerto NO responde (servidor caído que dejó procesos
// colgados — causa clasica de "no se puede acceder"), se limpian para que el
// nuevo server.js pueda tomar el puerto.
async function despejarPuerto(puerto) {
  if (await puertoEstaOcupado(puerto)) {
    // El puerto responde: puede ser un servidor LEGITIMO (la app ya abierta) o
    // un proceso colgado que escucha pero no sirve paginas. Se hace una peticion
    // HTTP real de cortesía: si NO responde con codigo alguno en 4 s, es un
    // zombie -> se limpia y el nuevo servidor toma el puerto. Esta era la causa
    // restante de "No se puede obtener acceso a esta pagina": procesos Node de
    // lanzamientos anteriores seguían escuchando en 3000 pero sin responder.
    let sano = false;
    try {
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 4000);
      const resp = await fetch(`http://127.0.0.1:${puerto}/login.php`, { signal: ctrl.signal });
      clearTimeout(t);
      sano = resp && resp.status >= 200 && resp.status < 500;
    } catch (_) { sano = false; }
    if (sano) {
      console.log(` [OK] Ya existe un servidor ACTIVO y respondiendo en el puerto ${puerto}. No se duplicara.`);
      return;
    }
    console.log(` [AVISO] Hay un proceso escuchando en el puerto ${puerto} pero NO responde al HTTP (servidor colgado). Se terminara para poder reiniciar la app...`);
    const nZ = matarNodezombies(puerto);
    if (nZ > 0) console.log(` [LIMPIEZA] ${nZ} proceso(s) zombi terminados.`);
    else {
      // Si no pudimos identificar el PID por su linea de comando, usar netstat.
      try {
        const out = execSync(`netstat -ano | findstr :${puerto} | findstr LISTENING`, { encoding: 'utf8', timeout: 5000 });
        const pids = [...new Set(out.split(/\r?\n/).map(l => (l.trim().match(/(\d+)\s*$/)||[])[1]).filter(Boolean))];
        for (const pid of pids) {
          if (parseInt(pid, 10) !== process.pid) {
            try { execSync(`taskkill /F /PID ${pid}`, { stdio: 'ignore', timeout: 4000 }); } catch (_) {}
          }
        }
      } catch (_) {}
    }
    await new Promise(r => setTimeout(r, 800));
    return;
  }
  const n = matarNodezombies(puerto);
  if (n > 0) {
    console.log(` [LIMPIEZA] ${n} proceso(s) Node zombi del gimnasio terminados. El puerto ${puerto} queda libre.`);
    await new Promise(r => setTimeout(r, 600));
  }
}

// Normaliza una versión remota leída de GitHub. Si el tag/descripción no
// contiene un número x.y.z (ej. "build-ecd6395"), se conserva el texto tal
// cual en lugar de corromperse con valores default.
function normalizarVersionRemota(vStr) {
  const arr = parsearVersion(vStr);
  return arr ? formatearVersion(arr) : String(vStr || '').trim();
}

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const RUTA_VERSION = path.join(__dirname, 'data', 'version.json');
const RUTA_BD_LOCAL = path.join(__dirname, 'data', 'basedatos_local.json');
const RUTA_RESPALDOS = path.join(__dirname, 'data', 'respaldos');
const ARCHIVO_FLAG_SIMULAR = path.join(__dirname, 'data', 'simular_actualizacion.flag');

function obtenerInfoVersion() {
  try {
    if (fs.existsSync(RUTA_VERSION)) {
      return JSON.parse(fs.readFileSync(RUTA_VERSION, 'utf-8'));
    }
  } catch (err) {
    console.warn('[Actualizador] Advertencia al leer version.json:', err.message);
  }
  return {
    version: 'v1.0.0',
    repo: 'YaxkinCortez/GYM',
    ultimoCommitSha: '7c54118250fe9b1e0a60c6598f9f8bc2ea848699',
    fechaActualizacion: new Date().toISOString().split('T')[0],
    descripcion: 'Versión inicial estable'
  };
}

function guardarInfoVersion(info) {
  try {
    const dir = path.dirname(RUTA_VERSION);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(RUTA_VERSION, JSON.stringify(info, null, 2), 'utf-8');
  } catch (err) {
    console.warn('[Actualizador] No se pudo guardar version.json:', err.message);
  }
}

function verificarConexionInternet(timeoutMs = 1200) {
  return new Promise((resolve) => {
    let respondido = false;
    const timer = setTimeout(() => {
      if (!respondido) {
        respondido = true;
        resolve(false);
      }
    }, timeoutMs);

    dns.lookup('github.com', (err) => {
      if (!respondido) {
        respondido = true;
        clearTimeout(timer);
        if (!err) return resolve(true);
        // Segundo intento rápido con google.com
        dns.lookup('google.com', (err2) => {
          resolve(!err2);
        });
      }
    });
  });
}

function hacerPeticionHttps(url, opciones = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url);
    const cliente = u.protocol === 'https:' ? https : http;
    const reqOpciones = {
      hostname: u.hostname,
      path: u.pathname + (u.search || ''),
      headers: {
        'User-Agent': 'GimnasioLaFabrica-Actualizador/1.0',
        'Accept': 'application/vnd.github.v3+json',
        ...(opciones.headers || {})
      },
      timeout: 2500
    };

    const req = cliente.get(reqOpciones, (res) => {
      // Manejar redirecciones de GitHub (301, 302, 307)
      if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
        return resolve(hacerPeticionHttps(res.headers.location, opciones));
      }

      if (opciones.stream) {
        return resolve(res);
      }

      let data = '';
      res.on('data', chunk => { data += chunk; });
      res.on('end', () => {
        resolve({ statusCode: res.statusCode, body: data });
      });
    });

    req.on('timeout', () => {
      req.destroy();
      reject(new Error('Tiempo de espera agotado al conectar con GitHub'));
    });

    req.on('error', err => reject(err));
  });
}

async function descargarArchivo(url, destino) {
  return new Promise(async (resolve, reject) => {
    try {
      const u = new URL(url);
      const cliente = u.protocol === 'https:' ? https : http;
      
      const req = cliente.get(url, {
        headers: {
          'User-Agent': 'GimnasioLaFabrica-Actualizador/1.0',
          'Accept': 'application/octet-stream'
        }
      }, (res) => {
        if (res.statusCode >= 300 && res.statusCode < 400 && res.headers.location) {
          return descargarArchivo(res.headers.location, destino).then(resolve).catch(reject);
        }

        if (res.statusCode !== 200) {
          return reject(new Error(`Error al descargar: código HTTP ${res.statusCode}`));
        }

        const fileStream = fs.createWriteStream(destino);
        res.pipe(fileStream);

        fileStream.on('finish', () => {
          fileStream.close();
          resolve(destino);
        });

        fileStream.on('error', err => {
          try { fs.unlinkSync(destino); } catch (_) {}
          reject(err);
        });
      });

      req.on('error', err => reject(err));
      req.setTimeout(30000, () => {
        req.destroy();
        reject(new Error('Tiempo de espera agotado durante la descarga'));
      });
    } catch (err) {
      reject(err);
    }
  });
}

function respaldarBaseDeDatosLocal() {
  if (!fs.existsSync(RUTA_RESPALDOS)) {
    fs.mkdirSync(RUTA_RESPALDOS, { recursive: true });
  }

  if (fs.existsSync(RUTA_BD_LOCAL)) {
    const ahora = new Date();
    const timestamp = ahora.toISOString().replace(/[:.]/g, '-');
    const rutaCopia = path.join(RUTA_RESPALDOS, `respaldo_previo_actualizacion_${timestamp}.json`);
    fs.copyFileSync(RUTA_BD_LOCAL, rutaCopia);
    console.log(` [SEGURIDAD] Base de datos local respaldada con éxito en:`);
    console.log(`            -> ${path.basename(rutaCopia)}`);
    return rutaCopia;
  } else {
    console.log(' [SEGURIDAD] No se encontró base de datos previa, se conservará la estructura actual.');
    return null;
  }
}

function descomprimirYActualizarArchivos(rutaZip) {
  const dirTemp = path.join(__dirname, 'temp_actualizacion_extraido');
  if (fs.existsSync(dirTemp)) {
    try { fs.rmSync(dirTemp, { recursive: true, force: true }); } catch (_) {}
  }
  fs.mkdirSync(dirTemp, { recursive: true });

  // 1. Extraer zip mediante AdmZip directamente en Node.js
  let extraido = false;
  try {
    const zip = new AdmZip(rutaZip);
    zip.extractAllTo(dirTemp, true);
    extraido = true;
  } catch (errAdm) {
    try {
      execSync(`powershell -Command "Expand-Archive -Path '${rutaZip}' -DestinationPath '${dirTemp}' -Force"`, { stdio: 'ignore' });
      extraido = true;
    } catch (errPowershell) {
      throw new Error('No se pudo descomprimir el archivo de actualización: ' + errAdm.message);
    }
  }

  if (!extraido) {
    throw new Error('Fallo al extraer el paquete de actualización.');
  }

  // Si GitHub empaquetó dentro de una carpeta raíz (ej. GYM-main)
  let origen = dirTemp;
  const elementos = fs.readdirSync(dirTemp);
  if (elementos.length === 1 && fs.statSync(path.join(dirTemp, elementos[0])).isDirectory()) {
    origen = path.join(dirTemp, elementos[0]);
  }

  // 2. Copiar archivos excluyendo ESTRICTAMENTE la base de datos local y respaldos
  // NOTA: server.js sigue en ejecución mientras se instala la actualización, por lo
  // que NO se puede borrar/reemplazar un archivo .js abierto (EPERM en Windows).
  // El servidor detecta estos marcadores y se auto-relanza al terminar la instalación.
  const archivosReinicioServidor = new Set(['server.js', 'package.json', 'package-lock.json']);
  const carpetaReinicioServidor = 'views';
  const marcadorReinicio = path.join(__dirname, 'data', 'reiniciar_pendiente.flag');
  let reinicioNecesario = false;

  const carpetasProtegidas = ['respaldos', 'node_modules', '.git', 'nodejs'];
  // NOTA: 'version.json' debe preservarse junto con la base de datos. Si se
  // sobrescribiera con el version.json del repositorio, tras instalar una
  // actualización la versión local quedaría por debajo de la remota y el
  // sistema volvería a marcar "actualización disponible" indefinidamente.
  const archivosProtegidos = ['basedatos_local.json', 'version.json', '.env', 'Gimnasio_La_Fabrica_Completo.zip', 'iniciar_gimnasio.bat', 'descargar_node_portable.bat'];
  // Marcadores locales que NUNCA deben sobrescribirse con los del repositorio:
  // .token_arranque es el sello de la sesion actual (si un zip antiguo lo
  // trajera, al volver a lanzar el .bat se reutilizaria token viejo y la
  // sesion anterior seguiria viva; ademas rompea la invalidacion por arranque).
  const archivosProtegidosRutaRelativa = [
    path.join('data', 'basedatos_local.json'),
    path.join('data', 'version.json'),
    path.join('data', '.token_arranque')
  ];

  function copiarRecursivo(srcDir, destDir) {
    const items = fs.readdirSync(srcDir);
    for (const item of items) {
      const srcItem = path.join(srcDir, item);
      const destItem = path.join(destDir, item);
      const stat = fs.statSync(srcItem);

      if (stat.isDirectory()) {
        if (carpetasProtegidas.includes(item)) continue;
        if (item === carpetaReinicioServidor) reinicioNecesario = true;
        if (!fs.existsSync(destItem)) fs.mkdirSync(destItem, { recursive: true });
        copiarRecursivo(srcItem, destItem);
      } else {
        // PROTEGER BASE DE DATOS LOCAL Y MARCADORES DE SESION/VERSION
        if (archivosProtegidos.includes(item)) {
          console.log(` [PROTECCIÓN] Preservando archivo local intacto: ${item}`);
          continue;
        }
        // No sobrescribir data/basedatos_local.json bajo ninguna circunstancia
        if (archivosProtegidosRutaRelativa.some(p => destItem.endsWith(p))) {
          console.log(` [PROTECCIÓN] ${path.relative(__dirname, destItem)} conservado sin alteraciones.`);
          continue;
        }
        if (archivosReinicioServidor.has(item)) reinicioNecesario = true;
        fs.copyFileSync(srcItem, destItem);
      }
    }
  }

  copiarRecursivo(origen, __dirname);

  // Dejar marcador para que el server.js en ejecución se auto-relance y cargue
  // los archivos nuevos. Si la instalación ocurre con el servidor CERRADO, el
  // marcador simplemente será ignorado/limpiado en el próximo arranque.
  try {
    if (reinicioNecesario) {
      fs.writeFileSync(marcadorReinicio, new Date().toISOString());
      console.log(' [REINICIO] Archivos del servidor actualizados: se programó reinicio automático.');
    } else if (fs.existsSync(marcadorReinicio)) {
      fs.unlinkSync(marcadorReinicio);
    }
  } catch (_) {}

  // Limpiar temporales
  try {
    fs.rmSync(dirTemp, { recursive: true, force: true });
    if (fs.existsSync(rutaZip)) fs.unlinkSync(rutaZip);
  } catch (_) {}
}

function regenerarZipProyecto() {
  const outputZip = path.join(__dirname, 'Gimnasio_La_Fabrica_Completo.zip');
  try {
    const zip = new AdmZip();
    const excludeDirs = new Set(['.git', '.cache', 'temp_actualizacion_extraido', '.aistudio']);
    const excludeFiles = new Set(['Gimnasio_La_Fabrica_Completo.zip', 'cookies.txt', 'temp_actualizacion.zip']);

    function addDir(dirPath, zipPath = '') {
      const entries = fs.readdirSync(dirPath, { withFileTypes: true });
      for (const entry of entries) {
        const fullPath = path.join(dirPath, entry.name);
        const entryZipPath = zipPath ? (zipPath + '/' + entry.name) : entry.name;
        if (entry.isDirectory()) {
          if (excludeDirs.has(entry.name) || (entry.name.startsWith('.') && entry.name !== '.env.example')) continue;
          addDir(fullPath, entryZipPath);
        } else if (entry.isFile()) {
          if (excludeFiles.has(entry.name) || (entry.name.startsWith('.') && entry.name !== '.env.example')) continue;
          zip.addLocalFile(fullPath, zipPath);
        }
      }
    }

    addDir(__dirname);
    zip.writeZip(outputZip);

    const publicDir = path.join(__dirname, 'public');
    if (fs.existsSync(publicDir)) {
      try {
        fs.copyFileSync(outputZip, path.join(publicDir, 'Gimnasio_La_Fabrica_Completo.zip'));
      } catch (_) {}
    }
    console.log(' [PROYECTO] Archivo Gimnasio_La_Fabrica_Completo.zip regenerado con éxito.');
  } catch (err) {
    console.warn('[ZIP] Advertencia al generar archivo zip:', err.message);
  }
}

async function comprobarYActualizar() {
  // Watchdog de seguridad: maximo 3 segundos para la comprobacion por red.
  // (Ademas existe el limite global de 20 s "terminarSiempre": el proceso
  // NUNCA puede quedar colgado, que era lo que impedía arrancar la app.)
  const timeoutSeguridad = setTimeout(() => {
    console.log('\n [DEGRADACIÓN ELEGANTE] Tiempo límite de comprobación alcanzado.');
    console.log(' Procediendo directamente al inicio del programa y pantalla de login...');
    lanzarServidorLocal(); // no dependemos del .bat: nosotros mismos arrancamos la webapp
    terminarSiempre(0, null);
  }, 3000);
  timeoutSeguridad.unref();

  // Limpiar procesos Node zombis del proyecto que dejan el puerto 3000
  // bloqueado SIN servidor util (causa clasica de "no se puede acceder").
  try { await despejarPuerto(3000); } catch (_) {}

  // LIMPIEZA CRITICA: el marcador de simulacion era un archivo LOCAL de
  // pruebas que por error se subio a GitHub; al descargarse el zip de
  // actualizacion volvía a aparecer en todos los equipos y disparaba una
  // "actualización simulada" infinita (incremento -> descarga -> relanzamiento
  // -> otra simulacion) impidiendo que server.js llegara a abrir el puerto
  // 3000 ("No se puede obtener acceso a esta página"). Aqui se ELIMINA siempre
  // al iniciar, aunque venga dentro de un paquete de actualización viejo.
  // La simulación solo se activa con el argumento explicito --simular.
  try { if (fs.existsSync(ARCHIVO_FLAG_SIMULAR)) fs.unlinkSync(ARCHIVO_FLAG_SIMULAR); } catch (_) {}

  const versionInfo = obtenerInfoVersion();
  const repo = process.env.REPO_GITHUB || versionInfo.repo || 'YaxkinCortez/GYM';
  const args = process.argv.slice(2);
  const forzarSimulacion = args.includes('--simular');

  console.log('===============================================================================');
  console.log('         GIMNASIO LA FÁBRICA - COMPROBADOR DE VERSIONES GITHUB');
  console.log('===============================================================================');
  console.log(` Repositorio configurado: https://github.com/${repo}`);
  const versionLocal = obtenerVersionLocal();
  console.log(` Versión actual local:   ${versionLocal}`);

  let actualizacionDisponible = null;

  // 1. Verificar si hay simulación activa solicitada
  if (forzarSimulacion) {
    if (fs.existsSync(ARCHIVO_FLAG_SIMULAR)) {
      try { fs.unlinkSync(ARCHIVO_FLAG_SIMULAR); } catch (_) {}
    }
    const nuevaVer = incrementarVersion(versionLocal);

    actualizacionDisponible = {
      tipo: 'simulacion',
      version: nuevaVer,
      descripcion: 'Actualización simulada para comprobación del ciclo de inicio y reinicio.',
      urlDescarga: `https://github.com/${repo}/archive/refs/heads/main.zip`,
      commitSha: 'simulada_' + Date.now().toString(16)
    };
    console.log('\n [MODO PRUEBA] Simulación de incremento a versión superior: ' + nuevaVer);
  } else {
    // 0. Pre-verificación de conectividad (Patrón Graceful Degradation)
    const tieneInternet = await verificarConexionInternet(1200);
    if (!tieneInternet) {
      console.log('\n===============================================================================');
      console.log(' [PATRÓN DE DEGRADACIÓN ELEGANTE: MODO FUERA DE LÍNEA]');
      console.log(' No se detectó acceso a Internet activo.');
      console.log(' Saltando la comprobación de GitHub para permitir que la aplicación proceda');
      console.log(' directamente a la pantalla de inicio de sesión sin demoras ni bloqueos.');
      console.log('===============================================================================');
      lanzarServidorLocal(); // sin internet TAMPOCO dependemos del .bat: la app arranca igual
      terminarSiempre(0, null);
    }

    console.log(' Conectando con GitHub para verificar actualizaciones disponibles...');

    let versionRemota = null;
    let descripcionRemota = 'Nueva versión desde GitHub';
    let urlDescargaRemota = `https://github.com/${repo}/archive/refs/heads/main.zip`;
    let commitShaRemoto = '';

    // A. Consultar el archivo version.json remoto en GitHub
    try {
      const respVer = await hacerPeticionHttps(`https://raw.githubusercontent.com/${repo}/main/data/version.json`);
      if (respVer.statusCode === 200) {
        const vData = JSON.parse(respVer.body);
        if (vData && vData.version) {
          versionRemota = normalizarVersionRemota(vData.version);
          descripcionRemota = vData.descripcion || descripcionRemota;
          commitShaRemoto = vData.ultimoCommitSha || '';
        }
      }
    } catch (_) {}

    // B. Si no se obtuvo version.json, comprobar releases oficiales
    if (!versionRemota) {
      try {
        const respRelease = await hacerPeticionHttps(`https://api.github.com/repos/${repo}/releases/latest`);
        if (respRelease.statusCode === 200) {
          const releaseData = JSON.parse(respRelease.body);
          const tag = releaseData.tag_name || releaseData.name;
          if (tag) {
            versionRemota = normalizarVersionRemota(tag);
            descripcionRemota = releaseData.body || descripcionRemota;
            urlDescargaRemota = releaseData.zipball_url || urlDescargaRemota;
            commitShaRemoto = releaseData.target_commitish || '';
          }
        }
      } catch (_) {}
    }

    // C. Si no hay release, comprobar commits recientes
    if (!versionRemota) {
      try {
        const respCommits = await hacerPeticionHttps(`https://api.github.com/repos/${repo}/commits?per_page=1`);
        if (respCommits.statusCode === 200) {
          const commits = JSON.parse(respCommits.body);
          if (Array.isArray(commits) && commits.length > 0) {
            const latest = commits[0];
            const msg = latest.commit?.message ? latest.commit.message.split('\n')[0] : '';
            const match = msg.match(/(\d+\.\d+(?:\.\d+)?)/);
            if (match) {
              versionRemota = normalizarVersionRemota(match[1]);
            } else if (/^build[-_]/i.test(msg)) {
              // Mensaje tipo "build-ecd6395": NO es una versión instalable.
              // Se registra solo como referencia de sincronización, sin
              // disparar descargas ni corromper el registro de versiones.
              versionRemota = null;
              console.log(` [SINCRONIZACIÓN] Último commit en GitHub: "${msg}". No corresponde a una versión numérica; se omite la descarga.`);
            }
            commitShaRemoto = latest.sha;
          }
        }
      } catch (errCommits) {
        console.log(' [DEGRADACIÓN ELEGANTE] Error temporal de red con GitHub. Continuando con la versión local...');
      }
    }

    // D. Evaluación de versión con Protección Anti-Retrocesos
    if (versionRemota) {
      const diff = compararVersiones(versionRemota, versionLocal);
      if (diff > 0) {
        // La versión de GitHub es ESTRICTAMENTE MAYOR
        actualizacionDisponible = {
          tipo: 'github',
          version: versionRemota,
          descripcion: descripcionRemota,
          urlDescarga: urlDescargaRemota,
          commitSha: commitShaRemoto
        };
      } else {
        // La versión local es MAYOR o IGUAL. ¡NO DESCARGAR NADA!
        console.log('\n===============================================================================');
        console.log(' [PROTECCIÓN ANTI-RETROCESOS ACTIVADA]');
        console.log(` Versión local instalada: ${versionLocal}`);
        console.log(` Versión remota en GitHub: ${versionRemota}`);
        console.log(' Tu versión local es SUPERIOR o IGUAL a la de GitHub.');
        console.log(' No se realiza ninguna descarga para evitar dar un retroceso en el software.');
        console.log(' Procediendo directamente al inicio del programa y pantalla de login...');
        console.log('===============================================================================');
        lanzarServidorLocal();
        terminarSiempre(0, null);
      }
    } else {
      console.log(`\n [SISTEMA AL DÍA] No se detectó ninguna versión superior en GitHub.`);
      console.log(` Continuando con la versión local ${versionLocal}...`);
      lanzarServidorLocal();
      terminarSiempre(0, null);
    }
  }

  // 4. Si no hay actualización disponible, continuar
  if (!actualizacionDisponible) {
    console.log(`\n [SISTEMA LISTO] Continuando ejecución local con versión ${versionLocal}.`);
    console.log(' Procediendo directamente al inicio del servidor y pantalla de login...');
    lanzarServidorLocal();
    terminarSiempre(0, null);
  }

  // 5. ¡Nueva versión encontrada! Descargar e instalar preservando la BD
  console.log('\n===============================================================================');
  console.log(' ¡NUEVA VERSIÓN DETECTADA EN GITHUB!');
  console.log(` Versión disponible: ${actualizacionDisponible.version}`);
  console.log(` Detalles:           ${actualizacionDisponible.descripcion}`);
  console.log('===============================================================================');

  const rutaDescarga = path.join(__dirname, 'temp_actualizacion.zip');

  try {
    // Paso A: Respaldar base de datos local
    console.log('\n [PASO 1/4] Creando respaldo de seguridad de la base de datos local...');
    respaldarBaseDeDatosLocal();

    // Paso B: Descargar paquete
    console.log(` [PASO 2/4] Descargando actualización desde GitHub (${actualizacionDisponible.urlDescarga})...`);
    await descargarArchivo(actualizacionDisponible.urlDescarga, rutaDescarga);
    console.log(' [DESCARGA] Paquete de actualización descargado correctamente.');

    // Paso C: Descomprimir e instalar sin tocar la base de datos local
    console.log(' [PASO 3/4] Instalando archivos de la nueva versión (Base de datos protegida)...');
    descomprimirYActualizarArchivos(rutaDescarga);
    console.log(' [INSTALACIÓN] Archivos actualizados correctamente sin modificar la base de datos local.');

    // Paso D: Actualizar registro de versiones
    console.log(' [PASO 4/4] Actualizando registro del sistema y empaquetando proyecto...');
    // IMPORTANTE: releer version.json desde disco, porque la instalación anterior
    // puede haberlo sobrescrito con el version.json del repositorio. Si usáramos
    // el objeto en memoria (obtenido al inicio), escribiríamos una versión antigua
    // y el sistema volvería a marcar siempre una actualización disponible.
    const versionInfoActual = obtenerInfoVersion();
    // Solo numeros x.y.z reales: jamas registrar etiquetas "build-*" ni hashes
    // como version instalada (eso corrompia el registro y el login mostraba
    // versiones falsas). Si la etiqueta remota no tiene numero, se conserva la
    // version local ya registrada.
    const arrInstalada = parsearVersion(actualizacionDisponible.version);
    versionInfoActual.version = arrInstalada ? formatearVersion(arrInstalada) : versionLocal;
    versionInfoActual.repo = versionInfoActual.repo || repo;
    versionInfoActual.ultimoCommitSha = actualizacionDisponible.commitSha || versionInfoActual.ultimoCommitSha;
    versionInfoActual.fechaActualizacion = new Date().toISOString().split('T')[0];
    versionInfoActual.descripcion = actualizacionDisponible.descripcion;
    versionInfoActual.estado = 'actualizado_exitoso';
    guardarInfoVersion(versionInfoActual);
    guardarVersionLocal(actualizacionDisponible.version, {
      descripcion: actualizacionDisponible.descripcion,
      ultimoCommitSha: actualizacionDisponible.commitSha || ''
    });
    console.log(` [REGISTRO] Versión local registrada ahora como: ${obtenerVersionLocal()}`);

    // Regenerar Gimnasio_La_Fabrica_Completo.zip tras la modificación
    regenerarZipProyecto();

    console.log('\n===============================================================================');
    console.log(` [ACTUALIZACIÓN EXITOSA] El sistema se ha actualizado a ${actualizacionDisponible.version}.`);
    console.log(' Tu base de datos local (socios, ventas, cobros e inventario) se conservó 100% intacta.');
    console.log(' Solicitando a iniciar_gimnasio.bat que se vuelva a lanzar para arrancar la webapp...');
    console.log('===============================================================================\n');

    // Código de salida 2 indica a iniciar_gimnasio.bat que hubo actualización y debe volver a lanzarse
    process.exit(2);

  } catch (errActualizacion) {
    console.error('\n [ERROR] Ocurrió un inconveniente durante la actualización:', errActualizacion.message);
    console.log(' Tu base de datos local permanece totalmente segura.');
    console.log(' Continuando con el inicio de la versión existente...');
    try {
      if (fs.existsSync(rutaDescarga)) fs.unlinkSync(rutaDescarga);
    } catch (_) {}
    // Red lenta para DESCARGAS (paquetes grandes): NO bloquear el arranque.
    // El .bat puede tardar en reintentar, asi que lanzamos el servidor nosotros.
    lanzarServidorLocal();
    terminarSiempre(0, null);
  }
}

// ==================== AUTO-ARRANQUE DEL SERVIDOR ====================
// El comprobador ya NO depende de que iniciar_gimnasio.bat lance server.js
// despues: el propio proceso lo arranca en una ventana/consola nueva antes de
// terminar. Si el .bat tambien lo intentara, server.js detecta el puerto
// ocupado y sale sin duplicarse, asi que nunca habra dos servidores.
let servidorLanzado = false;
function lanzarServidorLocal() {
  // Si server.js nos invoco a nosotros (GF_ALREADY_RAN), NO abrir otro servidor:
  // el padre continua y se lanza a si mismo justo despues.
  if (servidorLanzado || process.env.GF_ALREADY_RAN === '1') return;
  servidorLanzado = true;
  const rutaServer = path.join(__dirname, 'server.js');
  try {
    if (process.platform === 'win32') {
      // ANTES se lanzaba con stdio:'ignore': cualquier error del servidor
      // (puerto ocupado, token invalido, sintaxis) moria en una ventana sin
      // salida y el navegador mostraba "No se puede obtener acceso a esta
      // pagina" sin dejar ni rastro. Ahora TODA la consola del servidor se
      // vuelca a data\arranque.log y ademas queda la ventana visible con el
      // registro completo al final.
      const rutaLog = path.join(__dirname, 'data', 'arranque.log');
      try {
        fs.mkdirSync(path.dirname(rutaLog), { recursive: true });
        fs.appendFileSync(rutaLog, `\r\n===== [${new Date().toISOString()}] Auto-arranque del servidor desde comprobador =====\r\n`);
      } catch (_) {}
      const cmd = `title Gimnasio La Fabrica - Servidor && "${process.execPath}" "${rutaServer}" 2>&1 | "%SystemRoot%\\System32\\more.com" >> "${rutaLog}" & echo. & echo [El servidor termino. Registro completo en data\\arranque.log] & pause`;
      spawn('cmd.exe', ['/c', cmd], { detached: true, stdio: 'ignore', windowsHide: false }).unref();
    } else {
      const logStream = fs.openSync(path.join(os.tmpdir(), 'gimnasio_server.log'), 'a');
      const hijo = spawn(process.execPath, [rutaServer], {
        detached: true,
        cwd: __dirname,
        stdio: ['ignore', logStream, logStream]
      });
      hijo.on('error', () => {});
      hijo.unref();
      try { fs.closeSync(logStream); } catch (_) {}
    }
    console.log(' [ARRANQUE] Servidor web lanzado automaticamente (no se necesita esperar al lanzador).');
  } catch (errLanzar) {
    console.warn(' [ARRANQUE] No se pudo lanzar el servidor automaticamente:', errLanzar.message);
  }
}

comprobarYActualizar().catch(err => {
  console.warn('[Actualizador] Conexión omitida o error no fatal:', err.message);
  // Nunca dejar el arranque a medias: aunque este paso falle, la app debe abrir.
  try { lanzarServidorLocal(); } catch (_) {}
  terminarSiempre(0, null);
});
