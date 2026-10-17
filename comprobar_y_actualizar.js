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
import path from 'path';
import https from 'https';
import http from 'http';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import AdmZip from 'adm-zip';

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
      timeout: 8000
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
  const carpetasProtegidas = ['respaldos', 'node_modules', '.git', 'nodejs'];
  const archivosProtegidos = ['basedatos_local.json', '.env', 'Gimnasio_La_Fabrica_Completo.zip'];

  function copiarRecursivo(srcDir, destDir) {
    const items = fs.readdirSync(srcDir);
    for (const item of items) {
      const srcItem = path.join(srcDir, item);
      const destItem = path.join(destDir, item);
      const stat = fs.statSync(srcItem);

      if (stat.isDirectory()) {
        if (carpetasProtegidas.includes(item)) continue;
        if (!fs.existsSync(destItem)) fs.mkdirSync(destItem, { recursive: true });
        copiarRecursivo(srcItem, destItem);
      } else {
        // PROTEGER BASE DE DATOS LOCAL
        if (archivosProtegidos.includes(item)) {
          console.log(` [PROTECCIÓN] Preservando archivo local intacto: ${item}`);
          continue;
        }
        // No sobrescribir data/basedatos_local.json bajo ninguna circunstancia
        if (destItem.endsWith(path.join('data', 'basedatos_local.json'))) {
          console.log(' [PROTECCIÓN] Base de datos local conservada sin alteraciones.');
          continue;
        }
        fs.copyFileSync(srcItem, destItem);
      }
    }
  }

  copiarRecursivo(origen, __dirname);

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
  const versionInfo = obtenerInfoVersion();
  const repo = process.env.REPO_GITHUB || versionInfo.repo || 'YaxkinCortez/GYM';
  const args = process.argv.slice(2);
  const forzarSimulacion = args.includes('--simular') || fs.existsSync(ARCHIVO_FLAG_SIMULAR);

  console.log('===============================================================================');
  console.log('         GIMNASIO LA FÁBRICA - COMPROBADOR DE VERSIONES GITHUB');
  console.log('===============================================================================');
  console.log(` Repositorio configurado: https://github.com/${repo}`);
  console.log(` Versión actual local:   ${versionInfo.version}`);
  console.log(' Conectando con GitHub para verificar actualizaciones disponibles...');

  let actualizacionDisponible = null;

  // 1. Verificar si hay simulación activa solicitada
  if (forzarSimulacion) {
    if (fs.existsSync(ARCHIVO_FLAG_SIMULAR)) {
      try { fs.unlinkSync(ARCHIVO_FLAG_SIMULAR); } catch (_) {}
    }
    const partes = (versionInfo.version || 'v1.0.0').replace('v', '').split('.');
    const mayor = partes[0] || '1';
    const menor = parseInt(partes[1] || '0', 10) + 1;
    const parche = partes[2] || '0';
    const nuevaVer = `v${mayor}.${menor}.${parche}`;

    actualizacionDisponible = {
      tipo: 'simulacion',
      version: nuevaVer,
      descripcion: 'Actualización simulada para comprobación del ciclo de inicio y reinicio.',
      urlDescarga: `https://github.com/${repo}/archive/refs/heads/main.zip`,
      commitSha: 'simulada_' + Date.now().toString(16)
    };
    console.log('\n [MODO PRUEBA] Se ha solicitado simular actualización a: ' + nuevaVer);
  } else {
    // 2. Comprobar releases oficiales en GitHub
    try {
      const respRelease = await hacerPeticionHttps(`https://api.github.com/repos/${repo}/releases/latest`);
      if (respRelease.statusCode === 200) {
        const releaseData = JSON.parse(respRelease.body);
        const tag = releaseData.tag_name || releaseData.name;
        if (tag && tag !== versionInfo.version) {
          actualizacionDisponible = {
            tipo: 'release',
            version: tag,
            descripcion: releaseData.body || 'Nueva versión publicada en GitHub.',
            urlDescarga: releaseData.zipball_url || `https://github.com/${repo}/archive/refs/tags/${tag}.zip`,
            commitSha: releaseData.target_commitish || ''
          };
        }
      }
    } catch (errRelease) {
      // Continuar con verificación de commits
    }

    // 3. Si no hay release nuevo, comprobar commits recientes
    if (!actualizacionDisponible) {
      try {
        const respCommits = await hacerPeticionHttps(`https://api.github.com/repos/${repo}/commits?per_page=1`);
        if (respCommits.statusCode === 200) {
          const commits = JSON.parse(respCommits.body);
          if (Array.isArray(commits) && commits.length > 0) {
            const latest = commits[0];
            const sha = latest.sha;
            // Si el commit en GitHub difiere del último registrado
            if (sha && sha !== versionInfo.ultimoCommitSha) {
              const msg = latest.commit?.message ? latest.commit.message.split('\n')[0] : 'Mejoras en el sistema';
              actualizacionDisponible = {
                tipo: 'commit',
                version: `v1.0.1-${sha.substring(0, 7)}`,
                descripcion: `Nuevo commit en GitHub: "${msg}"`,
                urlDescarga: `https://github.com/${repo}/archive/refs/heads/main.zip`,
                commitSha: sha
              };
            }
          }
        }
      } catch (errCommits) {
        console.warn(' [AVISO RED] No fue posible comunicarse con GitHub:', errCommits.message);
      }
    }
  }

  // 4. Si no hay nueva versión, el sistema ya está al día
  if (!actualizacionDisponible) {
    console.log(`\n [AL DÍA] Tu sistema cuenta con la versión más reciente (${versionInfo.version}).`);
    console.log(' No se requieren descargas. Continuando con el inicio de la aplicación...');
    process.exit(0);
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
    versionInfo.version = actualizacionDisponible.version;
    versionInfo.ultimoCommitSha = actualizacionDisponible.commitSha || versionInfo.ultimoCommitSha;
    versionInfo.fechaActualizacion = new Date().toISOString().split('T')[0];
    versionInfo.descripcion = actualizacionDisponible.descripcion;
    versionInfo.estado = 'actualizado_exitoso';
    guardarInfoVersion(versionInfo);

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
    process.exit(1);
  }
}

comprobarYActualizar();
