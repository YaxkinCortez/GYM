import express from 'express';
import session from 'express-session';
import cookieParser from 'cookie-parser';
import path from 'path';
import fs from 'fs';
import { spawn } from 'child_process';
import { fileURLToPath } from 'url';
import AdmZip from 'adm-zip';
import {
  store,
  nextId,
  getTodayStr,
  getNowStr,
  getDaysDiff,
  guardarBaseDatos,
  realizarRespaldoEnRuta,
  obtenerArchivosEnRuta,
  verificarYEjecutarRespaldoProgramado
} from './data/store.js';
import {
  parsearVersion,
  formatearVersion,
  incrementarVersion,
  compararVersiones,
  obtenerVersionLocal,
  guardarVersionLocal
} from './data/versionManager.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

export function generarZipProyecto() {
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
      } catch (errCopy) {
        console.warn('[ZIP] Error al sincronizar con public:', errCopy.message);
      }
    }
    return outputZip;
  } catch (err) {
    console.warn('[ZIP] Error al generar archivo zip con adm-zip:', err.message);
    return null;
  }
}

const esScriptPrincipal = Boolean(process.argv[1] && (process.argv[1].endsWith('server.js') || process.argv[1] === fileURLToPath(import.meta.url)));

// NOTA DE ARQUITECTURA: el lanzador oficial (iniciar_gimnasio.bat) ejecuta
// comprobar_y_actualizar.js ANTES de arrancar server.js. Si alguien lanza
// "node server.js" directamente, el servidor se auto-relanza en segundo plano
// mas adelante (ver bloque COMPROBADOR EN SEGUNDO PLANO) para aplicar la
// actualizacion SIN bloquear el arranque. El arranque del servidor JAMAS debe
// esperar a GitHub: esa era la causa del "No se puede obtener acceso a esta
// pagina" (execSync sin timeout colgaba el proceso antes de escuchar el 3000).

// ==================== AUTO-REINICIO TRAS ACTUALIZACIÓN ====================
// Ruta del marcador que deja comprobar_y_actualizar.js cuando la instalación
// reemplazó archivos del propio servidor (server.js, package.json, views/...).
const RUTA_MARCADOR_REINICIO = path.join(__dirname, 'data', 'reiniciar_pendiente.flag');
let reinicioEnCurso = false;

/**
 * Relanza server.js en un proceso nuevo (cargando ya los archivos actualizados)
 * y termina este proceso de forma limpia. Se usa al instalar una actualización
 * con la app abierta: el navegador solo pierde unos segundos de conexión y,
 * como las sesiones se guardan en cookies firmadas, NO se cierra la sesión.
 */
function relanzarServidor(motivo = 'actualización instalada') {
  if (reinicioEnCurso) return;
  reinicioEnCurso = true;
  console.log(`\n[Auto-Reinicio] ${motivo}. Relanzando el servidor con los archivos nuevos...`);
  try { guardarBaseDatos(); } catch (_) {}
  try { fs.unlinkSync(RUTA_MARCADOR_REINICIO); } catch (_) {}

  const args = process.argv.slice(2);
  // GF_REINTENTO: contador anti-bucle. Si el proceso anterior murió antes de
  // que el nuevo tomara el puerto, el lanzador (.bat/.sh) reintentará con este
  // código de salida; nunca se acumulan más de 3 intentos.
  const reintento = parseInt(process.env.GF_REINTENTO || '0', 10) + 1;
  // El hijo debe sobrevivir al padre. Con detached:true a secas, en Linux/macOS
  // el proceso nuevo puede morir junto al anterior (SIGHUP), por lo que se lanza
  // via 'setsid' fuera de la sesion del terminal. En Windows (produccion del
  // gimnasio) no existe setsid: spawn(detached) alli si desvincula al hijo, y
  // ademas el bucle :servidor_bucle del .bat (codigo de salida 10) es la red de
  // seguridad que relanza el servidor aunque el hijo no llegara a vivir.
  const opcionesHijo = {
    cwd: __dirname,
    detached: true,
    stdio: 'ignore',
    // GF_TOKEN_ARRANQUE: el proceso nuevo hereda el token del actual para que
    // el reinicio por actualizacion NO cierre la sesion del usuario. En un
    // arranque desde cero (sin esta variable) server.js lee data/.token_arranque
    // y habra que volver a iniciar sesion, como corresponde.
    env: { ...process.env, GF_RELANZADO: '1', GF_REINTENTO: String(reintento), GF_TOKEN_ARRANQUE: TOKEN_ARRANQUE }
  };
  const hijo = process.platform === 'win32'
    ? spawn(process.execPath, [__filename, ...args], opcionesHijo)
    : spawn('setsid', [process.execPath, __filename, ...args], opcionesHijo);
  hijo.on('error', (errHijo) => {
    console.error('[Auto-Reinicio] No se pudo lanzar el nuevo proceso:', errHijo.message);
    reinicioEnCurso = false;
  });
  hijo.unref();

  // Esperar a que el proceso nuevo tome el puerto antes de morir. El código de
  // salida 10 indica al lanzador (iniciar_gimnasio.bat/sh) que debe volver a
  // ejecutar server.js en la misma ventana para completar el reinicio.
  setTimeout(() => process.exit(10), 1500);
}

// Watchdog: si quedó un marcador de reinicio pendiente (por ejemplo, se
// instaló una actualización mientras el PC se apagó a mitad del proceso),
// este server.js recién iniciado ES el reinicio: limpiar el marcador.
if (esScriptPrincipal && fs.existsSync(RUTA_MARCADOR_REINICIO)) {
  try { fs.unlinkSync(RUTA_MARCADOR_REINICIO); } catch (_) {}
  console.log('[Auto-Reinicio] Marcador de reinicio procesado: sistema actualizado cargado correctamente.');
}

// ==================== COMPROBADOR DE ACTUALIZACIONES (SEGUNDO PLANO) ====================
// Cuando alguien lanza "node server.js" directamente (sin el .bat), el servidor
// NO espera a GitHub: arranca de inmediato y el comprobador corre en segundo
// plano. Al terminar, se relanza SOLO si hubo instalación real: codigo de salida
// 2 (el comprobador instala SIEMPRE que deja marcador; sin él sale con 0/1).
// Antes de este fix, el arranque hacia execSync bloqueante del comprobador sin
// timeout: si la red o las tuberías heredadas colgaban, el puerto 3000 nunca
// abría y el navegador mostraba "No se puede obtener acceso a esta página".
const __comprobadorHijo = esScriptPrincipal && !process.env.GF_ALREADY_RAN
  ? spawn(process.execPath, [path.join(__dirname, 'comprobar_y_actualizar.js'), '--sin-token-nuevo'], {
      cwd: __dirname,
      stdio: 'inherit',
      env: { ...process.env, GF_ALREADY_RAN: '1' }
    })
  : null;
if (__comprobadorHijo) {
  console.log('[Actualizador] Comprobación de GitHub lanzada en segundo plano (el servidor arranca sin esperarla).');
  __comprobadorHijo.on('exit', (codigo) => {
    if (codigo === 2) {
      setTimeout(() => relanzarServidor('actualización instalada en segundo plano al arrancar'), 400);
    }
  });
  __comprobadorHijo.on('error', (errH) => {
    console.warn('[Actualizador] No se pudo iniciar la comprobación en segundo plano:', errH.message);
  });
}

// Scheduler de comprobación para respaldos automáticos programados a una hora específica
const schedulerRespaldos = setInterval(() => {
  try {
    verificarYEjecutarRespaldoProgramado();
  } catch (err) {
    console.warn('[Scheduler Respaldo] Error en ciclo de respaldo programado:', err.message);
  }
}, 30000);
schedulerRespaldos.unref();

const app = express();
const PORT = parseInt(process.env.DEFAULT_APP_PORT || (process.env.PORT === '8080' ? 3000 : (process.env.PORT || 3000)), 10);

// Si otro proceso ya está escuchando en el puerto configurado (por ejemplo,
// el usuario volvió a ejecutar iniciar_gimnasio.bat con la app ya abierta),
// NO abrir un segundo servidor sobre la misma base de datos: salir limpiamente.
// IMPORTANTE: esta comprobacion debe ir DESPUES de declarar PORT; antes usaba
// PORT antes de su inicializacion y el servidor ni siquiera arrancaba
// (ReferenceError), lo que dejaba procesos antiguos colgados y provocaba que
// cada lanzamiento "no terminara de cerrar" el node anterior.
if (esScriptPrincipal) {
  const netMod = await import('net');
  const puertoLibre = await new Promise((resolverPuerto) => {
    const probar = netMod.default.createServer();
    probar.once('error', () => resolverPuerto(false));
    probar.once('listening', () => probar.close(() => resolverPuerto(true)));
    probar.listen(PORT, '127.0.0.1');
  });
  if (!puertoLibre) {
    console.log(`[Gimnasio La Fabrica] Ya existe un servidor activo en el puerto ${PORT}.`);
    console.log('[Gimnasio La Fabrica] Este lanzador no duplicará el servidor; puedes usar la ventana ya abierta.');
    process.exit(0);
  }
}

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(cookieParser());
app.use(express.static(path.join(__dirname, 'public')));

app.use(
  session({
    name: 'gfsid',
    secret: process.env.SESSION_SECRET || 'gimnasio_la_fabrica_secret_2026',
    resave: false,
    saveUninitialized: false,
    // Cookie de sesion sin maxAge: caduca al cerrar el navegador.
    // Ademas, al reiniciar/relanzar el servidor la firma (secret) es la misma,
    // pero si el usuario cerro el software y lo volvio a abrir desde cero,
    // se espera que vuelva a iniciar sesion (no entra "ya logueado").
    cookie: { httpOnly: true }
  })
);

// Invalidacion de sesiones al reiniciar: el token de arranque se guarda en
// data/.token_arranque y lo comparte todo el ciclo de vida del "lanzamiento":
//  - comprobar_y_actualizar.js escribe un token NUEVO cada vez que el usuario
//    ejecuta iniciar_gimnasio.bat => la sesion anterior queda invalidada y hay
//    que volver a iniciar sesion (comportamiento esperado al abrir desde cero).
//  - El auto-reinicio por actualizacion (relanzarServidor / codigo de salida 2
//    del .bat) NO reescribe el archivo: el proceso nuevo lee el mismo token y
//    la sesion del usuario sobrevive al reinicio.
// IMPORTANTE: solo se destruyen sesiones que contienen "tokenArranque"
// (sesiones de usuario ya iniciadas). Una cookie vacia o manipulada produce
// una sesion nueva SIN token: esa NO debe destruirse aqui, porque
// session.destroy() impide que Express vuelva a emitir cookie de sesion y
// se provoca un bucle infinito de redirecciones (ERR_TOO_MANY_REDIRECTS:
// "No se puede obtener acceso a esta página").
import { obtenerOCrearTokenArranque } from './data/arranqueToken.js';
const TOKEN_ARRANQUE = process.env.GF_TOKEN_ARRANQUE || obtenerOCrearTokenArranque();

app.use((req, res, next) => {
  const sesion = req.session;
  if (sesion && sesion.tokenArranque !== undefined && sesion.tokenArranque !== TOKEN_ARRANQUE) {
    // Sesión perteneciente a un proceso anterior: descartarla y despedirla
    // hacia el login. Al haber un usuario detras, la siguiente visita creara
    // una sesion nueva con su propia cookie (no hay bucle: la sesion nueva
    // no tiene token hasta el login, y el login siempre asigna el actual).
    req.session.destroy(() => {
      try { res.clearCookie('gfsid'); } catch (_) {}
      try { return res.redirect('/login.php'); } catch (_) {}
    });
    return;
  }
  next();
});

// Helpers attached to res.locals y persistencia automática
app.use((req, res, next) => {
  if (req.method === 'POST') {
    res.on('finish', () => {
      if (res.statusCode < 400) {
        guardarBaseDatos();
      }
    });
  }
  const appVersion = obtenerVersionLocal();
  res.locals.appVersion = appVersion;
  // Mantener la versión de la BD SIEMPRE sincronizada con data/version.json.
  // Evita que versiones falsas heredadas (p. ej. "v1.2.0") o registros
  // corruptos ("build-ecd6395") sigan apareciendo en la página de
  // actualizaciones y en el historial tras una actualización real.
  if (store.sistema_actualizaciones && store.sistema_actualizaciones.versionActual !== appVersion) {
    store.sistema_actualizaciones.versionActual = appVersion;
  }
  res.locals.configRespaldos = store.config_respaldos || null;
  res.locals.usuario = req.session.usuario || null;
  res.locals.APP_NAME = 'Gimnasio La Fábrica';
  res.locals.hoy = getTodayStr();
  res.locals.fechaActual = getNowStr();
  res.locals.updaterInfo = store.sistema_actualizaciones || null;
  res.locals.updaterAlerta = (store.sistema_actualizaciones && store.sistema_actualizaciones.alertaNotificacion) || null;
  next();
});

// Authentication middleware
function requireAuth(req, res, next) {
  if (!req.session.usuario) {
    return res.redirect('/login.php');
  }
  next();
}

function requireAdmin(req, res, next) {
  if (!req.session.usuario) {
    return res.redirect('/login.php');
  }
  const role = req.session.usuario.role;
  if (role !== 'admin' && role !== 'administrador') {
    return res.redirect('/pages/acceso_restringido.php');
  }
  next();
}

// ========================
// AUTH ROUTES
// ========================
app.get(['/', '/login', '/login.php'], (req, res) => {
  if (req.session.usuario) {
    return res.redirect('/pages/dashboard.php');
  }
  res.render('login', { error: '' });
});

app.post(['/login', '/login.php'], (req, res) => {
  const nameOrEmail = (req.body.name || '').trim();
  const password = req.body.password || '';

  if (!nameOrEmail || !password) {
    return res.render('login', { error: 'Por favor completa todos los campos' });
  }

  const user = store.users.find(
    u => (u.name.toLowerCase() === nameOrEmail.toLowerCase() || u.email.toLowerCase() === nameOrEmail.toLowerCase()) && u.password === password
  );

  if (!user) {
    return res.render('login', { error: 'Nombre o contraseña incorrectos' });
  }

  user.lastSignedIn = getNowStr();
  req.session.usuario = {
    id: user.id,
    name: user.name,
    email: user.email,
    role: user.role
  };
  // Marca de arranque: si el servidor se relanza (actualización o cierre y
  // reapertura del .bat), esta sesión se descarta y hay que volver a entrar.
  req.session.tokenArranque = TOKEN_ARRANQUE;

  res.redirect('/pages/dashboard.php');
});

app.get(['/logout', '/logout.php'], (req, res) => {
  req.session.destroy(() => {
    res.redirect('/login.php');
  });
});

// ========================
// DASHBOARD
// ========================
app.get(['/dashboard', '/pages/dashboard.php'], requireAuth, (req, res) => {
  const today = getTodayStr();

  // Ingresos de hoy
  const ingProductosInternos = store.saleitems
    .filter(i => (i.createdAt || '').startsWith(today))
    .reduce((sum, i) => sum + (Number(i.subtotal) || 0), 0);

  const ingProductosExternos = store.ventas_externas_realizadas
    .filter(i => (i.createdAt || '').startsWith(today))
    .reduce((sum, i) => sum + (Number(i.subtotal) || 0), 0);

  const ingresosMembresias = store.membership_sales
    .filter(m => (m.saleDate || '').startsWith(today))
    .reduce((sum, m) => sum + (Number(m.cost) || 0), 0);

  const ingresosPasesDiarios = store.temp_visits
    .filter(v => (v.checkInTime || '').startsWith(today))
    .reduce((sum, v) => sum + (Number(v.membershipCost) || 0), 0);

  const ingresoHoy = ingProductosInternos + ingProductosExternos + ingresosMembresias + ingresosPasesDiarios;

  // Visitas
  const visitasSocios = store.visits.filter(v => (v.visitDate || '').startsWith(today)).length;
  const visitasTemp = store.temp_visits.filter(v => (v.checkInTime || '').startsWith(today)).length;
  const visitasHoy = visitasSocios + visitasTemp;

  // Clientes y membresías
  const totalClientes = store.clients.length;

  const clientesActivosSet = new Set();
  store.clientmemberships.forEach(cm => {
    if (cm.isActive && cm.endDate >= today) {
      clientesActivosSet.add(cm.clientId);
    }
  });
  const clientesActivos = clientesActivosSet.size;

  // Bajo stock
  const listaBajoStock = store.products.filter(p => p.quantity <= p.minStock);
  const bajoStock = listaBajoStock.length;

  // Membresías por vencer y vencidas
  const clientLatestMembership = {};
  store.clientmemberships.forEach(cm => {
    const existing = clientLatestMembership[cm.clientId];
    if (!existing || cm.endDate > existing.endDate) {
      clientLatestMembership[cm.clientId] = cm;
    }
  });

  const listaPorVencer = [];
  const listaVencidas = [];

  Object.values(clientLatestMembership).forEach(cm => {
    const client = store.clients.find(c => c.id === cm.clientId);
    const plan = store.memberships.find(m => m.id === cm.membershipId);
    if (!client || !plan) return;

    const diff = getDaysDiff(cm.endDate, today);
    if (diff >= 1 && diff <= 5) {
      listaPorVencer.push({
        fullName: client.fullName,
        membership_name: plan.name,
        endDate: cm.endDate,
        dias: diff
      });
    } else if (diff < 0) {
      listaVencidas.push({
        fullName: client.fullName,
        membership_name: plan.name,
        endDate: cm.endDate,
        dias: Math.abs(diff)
      });
    }
  });

  res.render('dashboard', {
    pagina_activa: 'dashboard.php',
    ingProductosInternos,
    ingProductosExternos,
    ingresosMembresias,
    ingresosPasesDiarios,
    ingresoHoy,
    visitasClientes: visitasSocios,
    visitasTemporales: visitasTemp,
    visitasHoy,
    totalClientes,
    clientesActivos,
    bajoStock,
    listaBajoStock,
    listaPorVencer,
    listaVencidas
  });
});

// ========================
// VISITAS
// ========================
app.get(['/visitas', '/pages/visitas.php'], requireAuth, (req, res) => {
  const memDiaria = store.memberships.find(m => m.durationDays === 1 || m.name.toLowerCase().includes('diari')) || { cost: 25.0 };
  const today = getTodayStr();

  const visitasRecientes = [];
  store.visits
    .filter(v => (v.visitDate || '').startsWith(today))
    .slice(-10)
    .reverse()
    .forEach(v => {
      const client = store.clients.find(c => c.id === v.clientId);
      visitasRecientes.push({
        tipo: 'Socio',
        nombre: client ? client.fullName : 'Socio Desconocido',
        hora: (v.checkInTime || '').split(' ')[1] || 'Hoy',
        detalle: 'Entrada registrada en torniquete'
      });
    });

  store.temp_visits
    .filter(v => (v.checkInTime || '').startsWith(today))
    .slice(-10)
    .reverse()
    .forEach(v => {
      visitasRecientes.push({
        tipo: 'Temporal',
        nombre: v.visitorName,
        hora: (v.checkInTime || '').split(' ')[1] || 'Hoy',
        detalle: `Pase diario cobrado ($${Number(v.membershipCost).toFixed(2)})`
      });
    });

  res.render('visitas', {
    pagina_activa: 'visitas.php',
    mensaje: '',
    tipo_mensaje: '',
    q: '',
    encontrados: [],
    costoDiario: Number(memDiaria.cost),
    visitasRecientes
  });
});

app.post(['/visitas', '/pages/visitas.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const memDiaria = store.memberships.find(m => m.durationDays === 1 || m.name.toLowerCase().includes('diari')) || { id: 5, cost: 25.0 };
  const today = getTodayStr();
  const now = getNowStr();

  let mensaje = '';
  let tipo_mensaje = '';
  let q = '';
  let encontrados = [];

  if (accion === 'buscar') {
    q = (req.body.q || '').trim();
    if (q) {
      const qLower = q.toLowerCase();
      encontrados = store.clients.filter(
        c => c.fullName.toLowerCase().includes(qLower) || (c.fingerprint || '').toLowerCase().includes(qLower)
      );
    }
  } else if (accion === 'registrar_visita') {
    const cid = parseInt(req.body.client_id, 10);
    const client = store.clients.find(c => c.id === cid);
    const memberships = store.clientmemberships.filter(cm => cm.clientId === cid).sort((a, b) => b.endDate.localeCompare(a.endDate));
    const mem = memberships[0];

    if (!mem || !mem.endDate) {
      mensaje = '🔴 <strong>¡Acceso Denegado!</strong> El cliente no tiene membresía asignada.';
      tipo_mensaje = 'danger';
    } else {
      const dias = getDaysDiff(mem.endDate, today);
      if (dias < 0) {
        mensaje = `🔴 <strong>¡Acceso Denegado!</strong> Membresía CADUCADA (hace ${Math.abs(dias)} días).`;
        tipo_mensaje = 'danger';
      } else {
        store.visits.push({
          id: nextId('visits'),
          clientId: cid,
          visitDate: today,
          checkInTime: now,
          checkOutTime: null,
          createdAt: now
        });
        if (dias <= 5) {
          mensaje = `⚠️ <strong>Entrada Permitida.</strong> Membresía por vencer en <strong>${dias} días</strong>.`;
          tipo_mensaje = 'warning';
        } else {
          mensaje = `🟢 <strong>Entrada Permitida.</strong> Membresía activa (<strong>${dias} días restantes</strong>).`;
          tipo_mensaje = 'success';
        }
      }
    }
  } else if (accion === 'visita_temporal') {
    const cost = Number(memDiaria.cost) || 25.0;
    const visitorName = (req.body.visitorName || '').trim() || 'Visitante Temporal';

    store.temp_visits.push({
      id: nextId('temp_visits'),
      visitorName,
      checkInTime: now,
      membershipCost: cost
    });

    store.membership_sales.push({
      id: nextId('membership_sales'),
      clientId: null,
      membershipId: memDiaria.id || 5,
      cost,
      saleDate: now
    });

    mensaje = `✅ Pase diario cobrado exitosamente ($${cost.toFixed(2)}) para <strong>${visitorName}</strong>.`;
    tipo_mensaje = 'success';
  }

  // Recalcular visitas de hoy
  const visitasRecientes = [];
  store.visits
    .filter(v => (v.visitDate || '').startsWith(today))
    .slice(-10)
    .reverse()
    .forEach(v => {
      const client = store.clients.find(c => c.id === v.clientId);
      visitasRecientes.push({
        tipo: 'Socio',
        nombre: client ? client.fullName : 'Socio Desconocido',
        hora: (v.checkInTime || '').split(' ')[1] || 'Hoy',
        detalle: 'Entrada registrada en torniquete'
      });
    });

  store.temp_visits
    .filter(v => (v.checkInTime || '').startsWith(today))
    .slice(-10)
    .reverse()
    .forEach(v => {
      visitasRecientes.push({
        tipo: 'Temporal',
        nombre: v.visitorName,
        hora: (v.checkInTime || '').split(' ')[1] || 'Hoy',
        detalle: `Pase diario cobrado ($${Number(v.membershipCost).toFixed(2)})`
      });
    });

  res.render('visitas', {
    pagina_activa: 'visitas.php',
    mensaje,
    tipo_mensaje,
    q,
    encontrados,
    costoDiario: Number(memDiaria.cost),
    visitasRecientes
  });
});

// ========================
// CLIENTES
// ========================
app.get(['/clientes', '/pages/clientes.php'], requireAuth, (req, res) => {
  const q = (req.query.q || '').trim();
  const today = getTodayStr();

  let clientes = store.clients.map(c => {
    const memberships = store.clientmemberships.filter(cm => cm.clientId === c.id).sort((a, b) => b.endDate.localeCompare(a.endDate));
    const mActual = memberships[0];
    let membresiaActual = null;
    let membresiaEstadoClase = 'bg-secondary';
    let diasRestantes = 0;

    if (mActual) {
      const plan = store.memberships.find(m => m.id === mActual.membershipId);
      diasRestantes = getDaysDiff(mActual.endDate, today);
      membresiaActual = {
        ...mActual,
        membership_name: plan ? plan.name : 'Plan'
      };
      if (diasRestantes < 0) {
        membresiaEstadoClase = 'bg-danger';
      } else if (diasRestantes <= 5) {
        membresiaEstadoClase = 'bg-warning text-dark';
      } else {
        membresiaEstadoClase = 'bg-success';
      }
    }

    const deudas = store.client_debts.filter(d => d.clientId === c.id && d.status !== 'liquidada');
    const deudaTotal = deudas.reduce((acc, d) => acc + (Number(d.remainingAmount) || 0), 0);

    return {
      ...c,
      membresiaActual,
      membresiaEstadoClase,
      diasRestantes,
      deudaTotal
    };
  });

  if (q) {
    const qLower = q.toLowerCase();
    clientes = clientes.filter(
      c =>
        c.fullName.toLowerCase().includes(qLower) ||
        (c.phone || '').toLowerCase().includes(qLower) ||
        (c.fingerprint || '').toLowerCase().includes(qLower)
    );
  }

  res.render('clientes', {
    pagina_activa: 'clientes.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    q,
    clientes,
    planes: store.memberships.filter(m => m.isActive)
  });
});

app.post(['/clientes', '/pages/clientes.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();
  const today = getTodayStr();

  // AJAX endpoints
  if (accion === 'verificar_admin') {
    const u = (req.body.username || '').trim();
    const p = req.body.password || '';
    const check = store.users.find(
      user => (user.name === u || user.email === u) && (user.role === 'admin' || user.role === 'administrador') && user.password === p
    );
    if (check) {
      return res.json({ status: 'ok' });
    }
    return res.json({ status: 'error', message: 'Contraseña de administrador incorrecta' });
  }

  if (accion === 'obtener_info_cliente') {
    const cId = parseInt(req.body.cliente_id, 10);
    const cliente = store.clients.find(c => c.id === cId);
    if (!cliente) {
      return res.json({ status: 'error', message: 'Cliente no encontrado' });
    }

    const cms = store.clientmemberships
      .filter(cm => cm.clientId === cId)
      .sort((a, b) => b.endDate.localeCompare(a.endDate))
      .map(cm => {
        const m = store.memberships.find(plan => plan.id === cm.membershipId);
        return {
          ...cm,
          membership_name: m ? m.name : 'Plan',
          cost: m ? m.cost : 0,
          durationDays: m ? m.durationDays : 30,
          dias_restantes: getDaysDiff(cm.endDate, today)
        };
      });

    const cmActual = cms[0] || null;
    const deudas = store.client_debts.filter(d => d.clientId === cId);
    const abonos = store.debt_payments.filter(p => p.clientId === cId);
    const totalDeudaPendiente = deudas.filter(d => d.status !== 'liquidada').reduce((s, d) => s + (Number(d.remainingAmount) || 0), 0);

    return res.json({
      status: 'ok',
      cliente,
      membresiaActual: cmActual,
      historialMembresias: cms,
      deudas,
      abonos,
      totalDeudaPendiente
    });
  }

  // Standard Form submissions
  if (accion === 'crear') {
    const newClient = {
      id: nextId('clients'),
      fullName: (req.body.fullName || '').trim(),
      gender: req.body.gender || 'otro',
      phone: (req.body.phone || '').trim(),
      address: (req.body.address || '').trim(),
      dateOfBirth: req.body.dateOfBirth || null,
      fingerprint: (req.body.fingerprint || '').trim(),
      createdAt: now,
      updatedAt: now
    };
    store.clients.push(newClient);
    return res.redirect('/pages/clientes.php?msg=' + encodeURIComponent(`Socio "${newClient.fullName}" registrado exitosamente.`));
  }

  if (accion === 'editar') {
    const id = parseInt(req.body.id, 10);
    const client = store.clients.find(c => c.id === id);
    if (client) {
      client.fullName = (req.body.fullName || '').trim();
      client.gender = req.body.gender || 'otro';
      client.phone = (req.body.phone || '').trim();
      client.address = (req.body.address || '').trim();
      client.dateOfBirth = req.body.dateOfBirth || null;
      client.fingerprint = (req.body.fingerprint || '').trim();
      client.updatedAt = now;
    }
    return res.redirect('/pages/clientes.php?msg=' + encodeURIComponent('Datos del socio actualizados correctamente.'));
  }

  if (accion === 'eliminar') {
    const id = parseInt(req.body.id, 10);
    const idx = store.clients.findIndex(c => c.id === id);
    if (idx >= 0) {
      store.clients.splice(idx, 1);
    }
    return res.redirect('/pages/clientes.php?msg=' + encodeURIComponent('Socio eliminado del sistema.'));
  }

  if (accion === 'asignar_membresia') {
    const clientId = parseInt(req.body.clientId, 10);
    const membershipId = parseInt(req.body.membershipId, 10);
    const startDate = req.body.startDate || today;

    const plan = store.memberships.find(m => m.id === membershipId);
    if (plan) {
      const startD = new Date(startDate);
      const endD = new Date(startD);
      endD.setDate(endD.getDate() + (plan.durationDays || 30));
      const endDate = endD.toISOString().split('T')[0];

      // Mark older memberships as inactive
      store.clientmemberships.forEach(cm => {
        if (cm.clientId === clientId) cm.isActive = 0;
      });

      store.clientmemberships.push({
        id: nextId('clientmemberships'),
        clientId,
        membershipId,
        startDate,
        endDate,
        isActive: 1,
        createdAt: now,
        updatedAt: now
      });

      store.membership_sales.push({
        id: nextId('membership_sales'),
        clientId,
        membershipId,
        cost: plan.cost,
        saleDate: now
      });
    }

    return res.redirect('/pages/clientes.php?msg=' + encodeURIComponent('Membresía asignada y cobrada con éxito.'));
  }

  res.redirect('/pages/clientes.php');
});

// ========================
// VENTAS (POS)
// ========================
app.get(['/ventas', '/pages/ventas.php'], requireAuth, (req, res) => {
  res.render('ventas', {
    pagina_activa: 'ventas.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    productos: store.products,
    clientes: store.clients
  });
});

app.post(['/ventas', '/pages/ventas.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();

  if (accion === 'crear_venta') {
    let items = [];
    try {
      items = JSON.parse(req.body.items || '[]');
    } catch {
      items = [];
    }

    if (items.length === 0) {
      return res.redirect('/pages/ventas.php?msg=' + encodeURIComponent('El carrito está vacío.') + '&msg_type=danger');
    }

    const metodo = req.body.paymentMethod || 'efectivo';
    const clientId = req.body.clientId ? parseInt(req.body.clientId, 10) : null;

    if (metodo === 'credito' && !clientId) {
      return res.redirect(
        '/pages/ventas.php?msg=' + encodeURIComponent('Para ventas a crédito debes seleccionar a un socio.') + '&msg_type=danger'
      );
    }

    let totalInternal = 0;
    let totalExternal = 0;
    const internalItems = [];
    const externalItems = [];

    items.forEach(it => {
      if (it.isExternal) {
        externalItems.push(it);
        totalExternal += Number(it.subtotal) || 0;
      } else {
        internalItems.push(it);
        totalInternal += Number(it.subtotal) || 0;
      }
    });

    const totalGlobal = totalInternal + totalExternal;
    const maxSaleId = store.global_sales.length ? Math.max(...store.global_sales.map(s => s.id || 0)) : 0;
    const folio = String(maxSaleId + 1).padStart(4, '0');

    // Descontar inventario
    for (const it of internalItems) {
      const prod = store.products.find(p => p.id === it.productId);
      if (prod) {
        prod.quantity = Math.max(0, prod.quantity - it.quantity);
      }
    }
    for (const it of externalItems) {
      const prod = store.products.find(p => p.id === it.productId);
      if (prod) {
        prod.quantity = Math.max(0, prod.quantity - it.quantity);
      }
    }

    const globalSaleId = nextId('global_sales');
    store.global_sales.push({
      id: globalSaleId,
      folio,
      userId: req.session.usuario.id,
      clientId,
      totalAmount: totalGlobal,
      totalInternal,
      totalExternal,
      paymentMethod: metodo,
      notes: '',
      createdAt: now
    });

    if (internalItems.length > 0) {
      const saleId = nextId('sales');
      store.sales.push({
        id: saleId,
        globalSaleId,
        clientId,
        userId: req.session.usuario.id,
        totalAmount: totalInternal,
        discountAmount: 0,
        finalAmount: totalInternal,
        paymentMethod: metodo === 'credito' ? 'efectivo' : metodo,
        notes: metodo === 'credito' ? `A crédito Folio #${folio}` : '',
        createdAt: now
      });

      internalItems.forEach(it => {
        store.saleitems.push({
          id: nextId('saleitems'),
          saleId,
          globalSaleId,
          productId: it.productId,
          productName: it.productName,
          quantity: it.quantity,
          unitPrice: it.unitPrice,
          subtotal: it.subtotal,
          userId: req.session.usuario.id,
          createdAt: now
        });
      });
    }

    if (externalItems.length > 0) {
      externalItems.forEach(it => {
        store.ventas_externas_realizadas.push({
          id: nextId('ventas_externas_realizadas'),
          globalSaleId,
          productId: it.productId,
          productName: it.productName,
          quantity: it.quantity,
          unitPrice: it.unitPrice,
          subtotal: it.subtotal,
          userId: req.session.usuario.id,
          createdAt: now
        });
      });
    }

    // Registro de deuda si fue a crédito
    if (metodo === 'credito' && clientId) {
      const nombresProds = items.map(i => `${i.quantity}x ${i.productName}`).join(', ');
      store.client_debts.push({
        id: nextId('client_debts'),
        clientId,
        globalSaleId,
        description: `Venta a crédito #${folio}: ${nombresProds}`,
        totalAmount: totalGlobal,
        paidAmount: 0,
        remainingAmount: totalGlobal,
        status: 'pendiente',
        userId: req.session.usuario.id,
        createdAt: now,
        updatedAt: now
      });
    }

    return res.redirect(
      '/pages/ventas.php?msg=' +
        encodeURIComponent(`✅ Venta registrada con éxito (Folio #${folio}) por un total de $${totalGlobal.toFixed(2)}.`)
    );
  }

  res.redirect('/pages/ventas.php');
});

// ========================
// ABONOS Y DEUDAS
// ========================
app.get(['/abonos', '/pages/abonos.php'], requireAuth, (req, res) => {
  const deudas = store.client_debts.map(d => {
    const client = store.clients.find(c => c.id === d.clientId);
    return {
      ...d,
      cliente: client ? client.fullName : 'Socio Desconocido'
    };
  });

  const totalPendiente = deudas
    .filter(d => d.status !== 'liquidada')
    .reduce((acc, d) => acc + (Number(d.remainingAmount) || 0), 0);

  const abonosRecientes = store.debt_payments.slice(-20).reverse().map(a => {
    const client = store.clients.find(c => c.id === a.clientId);
    const cajero = store.users.find(u => u.id === a.userId);
    return {
      ...a,
      cliente: client ? client.fullName : 'Socio',
      cajero: cajero ? cajero.name : 'Admin'
    };
  });

  res.render('abonos', {
    pagina_activa: 'abonos.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    deudas,
    totalPendiente,
    abonosRecientes
  });
});

app.post(['/abonos', '/pages/abonos.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();

  if (accion === 'registrar_abono') {
    const debtId = parseInt(req.body.debtId, 10);
    const amount = parseFloat(req.body.amount) || 0;
    const metodo = req.body.paymentMethod || 'efectivo';
    const notes = (req.body.notes || '').trim();

    const deuda = store.client_debts.find(d => d.id === debtId);
    if (!deuda) {
      return res.redirect('/pages/abonos.php?msg=' + encodeURIComponent('Deuda no encontrada.') + '&msg_type=danger');
    }

    const remaining = Number(deuda.remainingAmount) || 0;
    const montoAbonar = Math.min(amount, remaining);

    deuda.paidAmount = (Number(deuda.paidAmount) || 0) + montoAbonar;
    deuda.remainingAmount = Math.max(0, remaining - montoAbonar);
    deuda.status = deuda.remainingAmount <= 0.001 ? 'liquidada' : 'parcial';
    deuda.updatedAt = now;

    store.debt_payments.push({
      id: nextId('debt_payments'),
      debtId,
      clientId: deuda.clientId,
      amount: montoAbonar,
      paymentMethod: metodo,
      notes,
      userId: req.session.usuario.id,
      createdAt: now
    });

    return res.redirect(
      '/pages/abonos.php?msg=' + encodeURIComponent(`Abono de $${montoAbonar.toFixed(2)} registrado correctamente.`)
    );
  }

  res.redirect('/pages/abonos.php');
});

// ========================
// MEMBRESIAS
// ========================
app.get(['/membresias', '/pages/membresias.php'], requireAuth, (req, res) => {
  const today = getTodayStr();

  const sociosMembresias = store.clientmemberships.map(cm => {
    const client = store.clients.find(c => c.id === cm.clientId);
    const plan = store.memberships.find(m => m.id === cm.membershipId);
    return {
      ...cm,
      fullName: client ? client.fullName : 'Desconocido',
      plan: plan ? plan.name : 'Plan',
      diasRestantes: getDaysDiff(cm.endDate, today)
    };
  });

  res.render('membresias', {
    pagina_activa: 'membresias.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    planes: store.memberships,
    sociosMembresias
  });
});

app.post(['/membresias', '/pages/membresias.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';

  if (accion === 'crear') {
    const newPlan = {
      id: nextId('memberships'),
      name: (req.body.name || '').trim(),
      cost: parseFloat(req.body.cost) || 0,
      durationDays: parseInt(req.body.durationDays, 10) || 30,
      isActive: req.body.isActive ? 1 : 0
    };
    store.memberships.push(newPlan);
    return res.redirect('/pages/membresias.php?msg=' + encodeURIComponent(`Plan "${newPlan.name}" creado con éxito.`));
  }

  if (accion === 'editar') {
    const id = parseInt(req.body.id, 10);
    const plan = store.memberships.find(m => m.id === id);
    if (plan) {
      plan.name = (req.body.name || '').trim();
      plan.cost = parseFloat(req.body.cost) || 0;
      plan.durationDays = parseInt(req.body.durationDays, 10) || 30;
      plan.isActive = req.body.isActive ? 1 : 0;
    }
    return res.redirect('/pages/membresias.php?msg=' + encodeURIComponent('Plan modificado con éxito.'));
  }

  if (accion === 'toggle_activa') {
    const id = parseInt(req.body.id, 10);
    const plan = store.memberships.find(m => m.id === id);
    if (plan) {
      plan.isActive = plan.isActive ? 0 : 1;
    }
    return res.redirect('/pages/membresias.php?msg=' + encodeURIComponent('Estado del plan actualizado.'));
  }

  res.redirect('/pages/membresias.php');
});

// ========================
// PRODUCTOS
// ========================
app.get(['/productos', '/pages/productos.php'], requireAuth, (req, res) => {
  res.render('productos', {
    pagina_activa: 'productos.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    productos: store.products
  });
});

app.post(['/productos', '/pages/productos.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();

  if (accion === 'escanear_codigo') {
    const barcode = (req.body.barcode || '').trim();
    const prod = store.products.find(p => p.barcode === barcode);
    if (prod) {
      prod.quantity += 1;
      return res.redirect(
        '/pages/productos.php?msg=' + encodeURIComponent(`✅ +1 unidad agregada a "${prod.name}" (Stock actual: ${prod.quantity})`)
      );
    } else {
      return res.redirect(
        '/pages/productos.php?msg=' + encodeURIComponent('⚠️ Código de barras no encontrado.') + '&msg_type=warning'
      );
    }
  }

  if (accion === 'ajuste_rapido') {
    const id = parseInt(req.body.id, 10);
    const delta = parseInt(req.body.delta, 10) || 0;
    const prod = store.products.find(p => p.id === id);
    if (prod) {
      prod.quantity = Math.max(0, prod.quantity + delta);
    }
    return res.redirect('/pages/productos.php?msg=' + encodeURIComponent('Existencia actualizada.'));
  }

  if (accion === 'crear') {
    const newProd = {
      id: nextId('products'),
      name: (req.body.name || '').trim(),
      barcode: (req.body.barcode || '').trim(),
      description: '',
      purchasePrice: parseFloat(req.body.purchasePrice) || 0,
      salePrice: parseFloat(req.body.salePrice) || 0,
      quantity: parseInt(req.body.quantity, 10) || 0,
      minStock: parseInt(req.body.minStock, 10) || 5,
      isExternal: req.body.isExternal ? 1 : 0,
      createdAt: now,
      updatedAt: now
    };
    store.products.push(newProd);
    return res.redirect('/pages/productos.php?msg=' + encodeURIComponent(`Producto "${newProd.name}" registrado correctamente.`));
  }

  if (accion === 'editar') {
    const id = parseInt(req.body.id, 10);
    const prod = store.products.find(p => p.id === id);
    if (prod) {
      prod.name = (req.body.name || '').trim();
      prod.barcode = (req.body.barcode || '').trim();
      prod.purchasePrice = parseFloat(req.body.purchasePrice) || 0;
      prod.salePrice = parseFloat(req.body.salePrice) || 0;
      prod.quantity = parseInt(req.body.quantity, 10) || 0;
      prod.minStock = parseInt(req.body.minStock, 10) || 5;
      prod.isExternal = req.body.isExternal ? 1 : 0;
      prod.updatedAt = now;
    }
    return res.redirect('/pages/productos.php?msg=' + encodeURIComponent('Producto actualizado correctamente.'));
  }

  if (accion === 'eliminar') {
    const id = parseInt(req.body.id, 10);
    const idx = store.products.findIndex(p => p.id === id);
    if (idx >= 0) {
      store.products.splice(idx, 1);
    }
    return res.redirect('/pages/productos.php?msg=' + encodeURIComponent('Producto eliminado del catálogo.'));
  }

  res.redirect('/pages/productos.php');
});

// ========================
// GASTOS
// ========================
app.get(['/gastos', '/pages/gastos.php'], requireAuth, (req, res) => {
  const gastos = store.expenses.map(e => {
    const u = store.users.find(user => user.id === e.userId);
    return {
      ...e,
      usuario_nombre: u ? u.name : 'Admin'
    };
  }).reverse();

  res.render('gastos', {
    pagina_activa: 'gastos.php',
    mensaje: req.query.msg || '',
    gastos
  });
});

app.post(['/gastos', '/pages/gastos.php'], requireAuth, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();

  if (accion === 'crear') {
    store.expenses.push({
      id: nextId('expenses'),
      description: (req.body.description || '').trim(),
      cost: parseFloat(req.body.cost) || 0,
      expenseDate: req.body.expenseDate || getTodayStr(),
      userId: req.session.usuario.id,
      createdAt: now,
      updatedAt: now
    });
    return res.redirect('/pages/gastos.php?msg=' + encodeURIComponent('Gasto registrado con éxito.'));
  }

  if (accion === 'eliminar') {
    const id = parseInt(req.body.id, 10);
    const idx = store.expenses.findIndex(e => e.id === id);
    if (idx >= 0) {
      store.expenses.splice(idx, 1);
    }
    return res.redirect('/pages/gastos.php?msg=' + encodeURIComponent('Gasto eliminado.'));
  }

  res.redirect('/pages/gastos.php');
});

// ========================
// BALANCE
// ========================
app.get(['/balance', '/pages/balance.php'], requireAdmin, (req, res) => {
  const today = getTodayStr();
  const inicio = req.query.fecha_inicio || today.slice(0, 8) + '01';
  const fin = req.query.fecha_fin || today;

  const ing_prod = store.sales
    .filter(s => {
      const d = (s.createdAt || '').split(' ')[0];
      return d >= inicio && d <= fin;
    })
    .reduce((acc, s) => acc + (Number(s.finalAmount) || 0), 0);

  const ing_mem = store.membership_sales
    .filter(m => {
      const d = (m.saleDate || '').split(' ')[0];
      return m.clientId !== null && d >= inicio && d <= fin;
    })
    .reduce((acc, m) => acc + (Number(m.cost) || 0), 0);

  const ing_temp = store.temp_visits
    .filter(v => {
      const d = (v.checkInTime || '').split(' ')[0];
      return d >= inicio && d <= fin;
    })
    .reduce((acc, v) => acc + (Number(v.membershipCost) || 0), 0);

  const total_ingresos_cobrados = ing_prod + ing_mem + ing_temp;

  const gastos = store.expenses
    .filter(e => e.expenseDate >= inicio && e.expenseDate <= fin)
    .reduce((acc, e) => acc + (Number(e.cost) || 0), 0);

  const deudasPeriodo = store.client_debts.filter(d => {
    const cDate = (d.createdAt || '').split(' ')[0];
    return cDate >= inicio && cDate <= fin;
  });

  const credito_otorgado = deudasPeriodo.reduce((acc, d) => acc + (Number(d.totalAmount) || 0), 0);
  const deudas_pendientes = deudasPeriodo.reduce((acc, d) => acc + (Number(d.remainingAmount) || 0), 0);
  const credito_abonado = deudasPeriodo.reduce((acc, d) => acc + (Number(d.paidAmount) || 0), 0);

  const balance_neto = total_ingresos_cobrados - gastos;

  res.render('balance', {
    pagina_activa: 'balance.php',
    inicio,
    fin,
    ing_prod,
    ing_mem,
    ing_temp,
    total_ingresos_cobrados,
    gastos,
    credito_otorgado,
    credito_abonado,
    deudas_pendientes,
    balance_neto
  });
});

// ========================
// USUARIOS
// ========================
app.get(['/usuarios', '/pages/usuarios.php'], requireAdmin, (req, res) => {
  res.render('usuarios', {
    pagina_activa: 'usuarios.php',
    mensaje: req.query.msg || '',
    usuarios: store.users
  });
});

app.post(['/usuarios', '/pages/usuarios.php'], requireAdmin, (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();

  if (accion === 'crear') {
    store.users.push({
      id: nextId('users'),
      openId: null,
      name: (req.body.name || '').trim(),
      email: (req.body.email || '').trim(),
      password: req.body.password || '123456',
      loginMethod: 'local',
      role: req.body.role || 'user',
      createdAt: now,
      updatedAt: now,
      lastSignedIn: null
    });
    return res.redirect('/pages/usuarios.php?msg=' + encodeURIComponent('Usuario creado exitosamente.'));
  }

  if (accion === 'eliminar') {
    const id = parseInt(req.body.id, 10);
    if (id !== req.session.usuario.id) {
      const idx = store.users.findIndex(u => u.id === id);
      if (idx >= 0) store.users.splice(idx, 1);
    }
    return res.redirect('/pages/usuarios.php?msg=' + encodeURIComponent('Usuario eliminado.'));
  }

  res.redirect('/pages/usuarios.php');
});

// ========================
// HISTORIAL VENTAS
// ========================
app.get(['/historial_ventas', '/pages/historial_ventas.php'], requireAuth, (req, res) => {
  const ventas = store.global_sales.map(gs => {
    const vendedor = store.users.find(u => u.id === gs.userId);
    const client = store.clients.find(c => c.id === gs.clientId);
    return {
      ...gs,
      vendedor: vendedor ? vendedor.name : 'Admin',
      cliente: client ? client.fullName : 'Público General'
    };
  }).reverse();

  res.render('historial_ventas', {
    pagina_activa: 'historial_ventas.php',
    ventas
  });
});

// ========================
// HISTORIAL MEMBRESIAS
// ========================
app.get(['/historial_membresias', '/pages/historial_membresias.php'], requireAuth, (req, res) => {
  const historial = store.clientmemberships.map(cm => {
    const client = store.clients.find(c => c.id === cm.clientId);
    const plan = store.memberships.find(m => m.id === cm.membershipId);
    return {
      ...cm,
      fullName: client ? client.fullName : 'Socio',
      plan: plan ? plan.name : 'Plan'
    };
  }).reverse();

  res.render('historial_membresias', {
    pagina_activa: 'historial_membresias.php',
    historial
  });
});

// ========================
// REPORTES
// ========================
app.get(['/reportes', '/pages/reportes.php'], requireAdmin, (req, res) => {
  const today = getTodayStr();
  const inicio = req.query.fecha_inicio || today.slice(0, 8) + '01';
  const fin = req.query.fecha_fin || today;
  const tipo = req.query.tipo || 'global_sales';

  let columnas = [];
  let registros = [];

  if (tipo === 'global_sales') {
    columnas = ['Folio', 'Fecha y Hora', 'Cajero', 'Cliente', 'Subtotal Interno', 'Subtotal Externo', 'Total Cobrado', 'Método Pago'];
    registros = store.global_sales
      .filter(s => {
        const d = (s.createdAt || '').split(' ')[0];
        return d >= inicio && d <= fin;
      })
      .map(s => {
        const cajero = store.users.find(u => u.id === s.userId);
        const c = store.clients.find(cl => cl.id === s.clientId);
        return [
          `#${s.folio}`,
          s.createdAt,
          cajero ? cajero.name : 'Admin',
          c ? c.fullName : 'Público General',
          `$${Number(s.totalInternal).toFixed(2)}`,
          `$${Number(s.totalExternal).toFixed(2)}`,
          `$${Number(s.totalAmount).toFixed(2)}`,
          s.paymentMethod
        ];
      });
  } else if (tipo === 'gastos') {
    columnas = ['ID', 'Fecha', 'Concepto', 'Monto', 'Registró'];
    registros = store.expenses
      .filter(e => e.expenseDate >= inicio && e.expenseDate <= fin)
      .map(e => {
        const u = store.users.find(user => user.id === e.userId);
        return [`#${e.id}`, e.expenseDate, e.description, `-$${Number(e.cost).toFixed(2)}`, u ? u.name : 'Admin'];
      });
  } else if (tipo === 'membresias') {
    columnas = ['ID', 'Fecha Venta', 'Socio', 'Plan', 'Costo'];
    registros = store.membership_sales
      .filter(m => {
        const d = (m.saleDate || '').split(' ')[0];
        return d >= inicio && d <= fin;
      })
      .map(m => {
        const c = store.clients.find(cl => cl.id === m.clientId);
        const p = store.memberships.find(pl => pl.id === m.membershipId);
        return [`#${m.id}`, m.saleDate, c ? c.fullName : 'Público General', p ? p.name : 'Plan', `$${Number(m.cost).toFixed(2)}`];
      });
  } else {
    columnas = ['ID', 'Fecha', 'Detalle', 'Total'];
    registros = [];
  }

  // Exportar CSV
  if (req.query.export === 'csv') {
    let csv = columnas.map(c => `"${c}"`).join(',') + '\n';
    registros.forEach(r => {
      csv += r.map(cel => `"${cel}"`).join(',') + '\n';
    });
    res.setHeader('Content-Type', 'text/csv; charset=utf-8');
    res.setHeader('Content-Disposition', `attachment; filename="Reporte_${tipo}_${inicio}_${fin}.csv"`);
    return res.send('\uFEFF' + csv);
  }

  res.render('reportes', {
    pagina_activa: 'reportes.php',
    tipo,
    inicio,
    fin,
    columnas,
    registros
  });
});

// ========================
// RESPALDOS
// ========================
app.get(['/respaldos', '/pages/respaldos.php'], requireAdmin, (req, res) => {
  const cfg = store.config_respaldos || {};
  const rutaConfigurada = cfg.rutaPersonalizada || '';
  const archivosEnDisco = obtenerArchivosEnRuta(rutaConfigurada);
  const historial = cfg.historialRespaldos || [];

  res.render('respaldos', {
    pagina_activa: 'respaldos.php',
    mensaje: req.query.msg || '',
    msg_type: req.query.msg_type || 'success',
    rutaPersonalizada: rutaConfigurada,
    respaldoAutomatico: cfg.respaldoAutomaticoDiario !== false,
    respaldoProgramado: cfg.respaldoProgramadoActivo !== false,
    horaRespaldo: cfg.horaRespaldo || '22:00',
    ultimoRespaldoRuta: cfg.ultimoRespaldoRuta || null,
    ultimoArchivoGenerado: cfg.ultimoArchivoGenerado || null,
    ultimoRespaldoProgramadoFecha: cfg.ultimoRespaldoProgramadoFecha || null,
    archivosEnDisco,
    historial
  });
});

app.post(['/respaldos', '/pages/respaldos.php'], requireAdmin, (req, res) => {
  const accion = req.body.accion || '';

  if (accion === 'descargar_json') {
    const ahora = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${ahora.getFullYear()}-${pad(ahora.getMonth() + 1)}-${pad(ahora.getDate())}`;
    const backupData = JSON.stringify({
      sistema: 'Gimnasio La Fábrica',
      version: '1.0.4',
      fechaRespaldo: getNowStr(),
      resumen: {
        socios: (store.clients || []).length,
        productos: (store.products || []).length,
        ventas: (store.global_sales || []).length,
        pagos: (store.member_payments || []).length
      },
      baseDeDatos: store
    }, null, 2);
    res.setHeader('Content-Type', 'application/json');
    res.setHeader('Content-Disposition', `attachment; filename="backup_gimnasio_${stamp}.json"`);
    return res.send(backupData);
  }

  if (accion === 'guardar_config_ruta') {
    const ruta = (req.body.ruta_personalizada || '').trim();
    const autoDiario = req.body.respaldo_automatico === '1' || req.body.respaldo_automatico === 'on';
    const autoProgramado = req.body.respaldo_programado === '1' || req.body.respaldo_programado === 'on';
    const hora = (req.body.hora_respaldo || '22:00').trim();

    if (!store.config_respaldos) {
      store.config_respaldos = {
        rutaPersonalizada: '',
        respaldoAutomaticoDiario: true,
        respaldoProgramadoActivo: true,
        horaRespaldo: '22:00',
        historialRespaldos: []
      };
    }
    store.config_respaldos.rutaPersonalizada = ruta;
    store.config_respaldos.respaldoAutomaticoDiario = autoDiario;
    store.config_respaldos.respaldoProgramadoActivo = autoProgramado;
    store.config_respaldos.horaRespaldo = hora;
    guardarBaseDatos();

    return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent(`Configuración guardada con éxito. Respaldo programado diario a las ${hora} hrs en: ${ruta || '(sin ruta asignada)'}`) + '&msg_type=success');
  }

  if (accion === 'respaldar_en_ruta') {
    const ruta = (req.body.ruta_personalizada || (store.config_respaldos && store.config_respaldos.rutaPersonalizada) || '').trim();
    if (!ruta) {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent('Por favor ingresa o selecciona una ruta de carpeta válida antes de respaldar.') + '&msg_type=danger');
    }

    const resultado = realizarRespaldoEnRuta(ruta, false, 'Manual a Ruta Específica');
    if (resultado.exito) {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent(`✅ ¡Respaldo completado! Se guardó la copia de la DB en: ${resultado.archivo} (${resultado.tamanoKb} KB)`) + '&msg_type=success');
    } else {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent(`❌ ${resultado.error}`) + '&msg_type=danger');
    }
  }

  if (accion === 'probar_respaldo_programado') {
    const ruta = (req.body.ruta_personalizada || (store.config_respaldos && store.config_respaldos.rutaPersonalizada) || '').trim();
    const hora = (store.config_respaldos && store.config_respaldos.horaRespaldo) || '22:00';
    if (!ruta) {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent('Por favor especifica una ruta antes de probar el respaldo.') + '&msg_type=danger');
    }

    const resultado = realizarRespaldoEnRuta(ruta, true, `Prueba de Respaldo Programado (${hora} hrs)`);
    if (resultado.exito) {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent(`✅ ¡Prueba de respaldo programado exitosa! Archivo generado: ${resultado.archivo} (${resultado.tamanoKb} KB). El programador automático funcionará exactamente así a las ${hora} hrs cada día.`) + '&msg_type=success');
    } else {
      return res.redirect('/pages/respaldos.php?msg=' + encodeURIComponent(`❌ ${resultado.error}`) + '&msg_type=danger');
    }
  }

  res.redirect('/pages/respaldos.php');
});

// ========================
// TABLAS
// ========================
app.get(['/tablas', '/pages/tablas.php'], requireAdmin, (req, res) => {
  const listaTablas = Object.keys(store);
  const tablaSeleccionada = req.query.tabla && store[req.query.tabla] ? req.query.tabla : listaTablas[0];
  const filas = store[tablaSeleccionada] || [];

  res.render('tablas', {
    pagina_activa: 'tablas.php',
    listaTablas,
    tablaSeleccionada,
    filas
  });
});

// ========================
// ACTUALIZACIONES (GITHUB)
// ========================
async function consultarGitHub(repo) {
  try {
    const headers = {
      'User-Agent': 'GimnasioLaFabrica-Updater',
      'Accept': 'application/vnd.github.v3+json'
    };

    // Normaliza una versión remota: si no contiene x.y.z devuelve null (las
    // etiquetas tipo "build-ecd6395" NO son versiones instalables y antes
    // provocaban descargas infinitas y registros corruptos).
    const normVer = (vStr) => {
      const arr = parsearVersion(vStr);
      return arr ? formatearVersion(arr) : null;
    };

    // A. Consultar version.json remoto en GitHub
    try {
      const resVer = await fetch(`https://raw.githubusercontent.com/${repo}/main/data/version.json`, {
        signal: AbortSignal.timeout(2500)
      });
      if (resVer.ok) {
        const vData = await resVer.json();
        const vNorm = vData && vData.version ? normVer(vData.version) : null;
        if (vNorm) {
          return {
            tipo: 'json',
            version: vNorm,
            descripcion: vData.descripcion || 'Actualización publicada en GitHub.',
            fecha: vData.fechaActualizacion || getNowStr(),
            url: `https://github.com/${repo}`
          };
        }
      }
    } catch (_) {}

    // B. Intentar obtener último release formal
    try {
      const resRelease = await fetch(`https://api.github.com/repos/${repo}/releases/latest`, {
        headers,
        signal: AbortSignal.timeout(2500)
      });
      if (resRelease.ok) {
        const data = await resRelease.json();
        const vNorm = normVer(data.tag_name || data.name);
        if (vNorm) {
          return {
            tipo: 'release',
            version: vNorm,
            descripcion: data.body || 'Nueva versión publicada en GitHub.',
            fecha: data.published_at || getNowStr(),
            url: data.html_url
          };
        }
      }
    } catch (_) {}

    // C. Si no hay release formal, consultar últimos commits
    const resCommits = await fetch(`https://api.github.com/repos/${repo}/commits?per_page=1`, {
      headers,
      signal: AbortSignal.timeout(2500)
    });
    if (resCommits.ok) {
      const commits = await resCommits.json();
      if (Array.isArray(commits) && commits.length > 0) {
        const latest = commits[0];
        const commitMsg = latest.commit && latest.commit.message ? latest.commit.message.split('\n')[0] : 'Actualización de repositorio';
        const matchVer = commitMsg.match(/(\d+\.\d+(?:\.\d+)?)/);
        if (matchVer) {
          return {
            tipo: 'commit',
            version: formatearVersion(parsearVersion(matchVer[1])),
            descripcion: `Último cambio en GitHub: "${commitMsg}"`,
            fecha: latest.commit?.committer?.date || getNowStr(),
            url: latest.html_url
          };
        }
        // Commit sin número de versión (p. ej. "build-ecd6395"): se reporta
        // solo como información de sincronización, SIN disparar descargas.
        return {
          tipo: 'sin_version',
          version: null,
          descripcion: `Último cambio en GitHub: "${commitMsg}" (no es una versión numérica instalable)`,
          fecha: latest.commit?.committer?.date || getNowStr(),
          url: latest.html_url
        };
      }
    }

    return null;
  } catch (error) {
    console.warn('[Actualizador] Sin conexión a GitHub o sin acceso a Internet:', error.message);
    return { offline: true, error: error.message };
  }
}

app.get(['/actualizaciones', '/pages/actualizaciones.php'], requireAdmin, (req, res) => {
  const updater = store.sistema_actualizaciones;
  updater.versionActual = obtenerVersionLocal();
  const flagPath = path.join(__dirname, 'data', 'simular_actualizacion.flag');
  const banderaBatActiva = fs.existsSync(flagPath);

  const historial = store.historial_actualizaciones.slice().reverse();
  const totalClientes = store.clients.length;
  const totalVentas = store.global_sales.length;
  const totalProductos = store.products.length;

  res.render('actualizaciones', {
    pagina_activa: 'actualizaciones.php',
    mensaje: req.query.msg || '',
    tipo_mensaje: req.query.msg_type || 'success',
    updater,
    banderaBatActiva,
    historial,
    totalClientes,
    totalVentas,
    totalProductos
  });
});

app.post(['/actualizaciones', '/pages/actualizaciones.php'], requireAdmin, async (req, res) => {
  const accion = req.body.accion || '';
  const now = getNowStr();
  const updater = store.sistema_actualizaciones;
  const versionLocal = obtenerVersionLocal();
  updater.versionActual = versionLocal;

  if (accion === 'cerrar_alerta') {
    updater.alertaNotificacion = null;
    return res.redirect(req.headers.referer || '/pages/dashboard.php');
  }

  if (accion === 'comprobar_ahora') {
    updater.ultimaComprobacion = now;
    const resultado = await consultarGitHub(updater.repo);

    if (resultado && resultado.offline) {
      return res.redirect(
        '/pages/actualizaciones.php?msg=' +
          encodeURIComponent('ℹ️ Modo sin conexión: No se detectó acceso a Internet en este momento. La comprobación se ha saltado y puedes seguir utilizando todo el software con normalidad.') +
          '&msg_type=info'
      );
    }

    if (resultado && resultado.version) {
      const diff = compararVersiones(resultado.version, versionLocal);
      if (diff > 0) {
        // La versión remota es ESTRICTAMENTE MAYOR
        updater.actualizacionDisponible = {
          version: resultado.version,
          descripcion: resultado.descripcion,
          fecha: resultado.fecha,
          url: resultado.url
        };
        updater.alertaNotificacion = {
          tipo: 'info',
          titulo: 'Actualización Disponible',
          mensaje: `Se ha detectado una nueva versión en GitHub (${resultado.version}). Puedes instalarla manteniendo todos tus datos seguros.`
        };
        return res.redirect(
          '/pages/actualizaciones.php?msg=' +
            encodeURIComponent(`🔔 ¡Nueva versión detectada en GitHub: ${resultado.version}!`) +
            '&msg_type=warning'
        );
      } else {
        // La versión local es mayor o igual. No descargar para no retroceder
        updater.actualizacionDisponible = null;
        const msgTexto = diff < 0
          ? `🛡️ Protección anti-retrocesos activa: Tu versión local (${versionLocal}) es superior a la de GitHub (${resultado.version}). No se requiere ninguna descarga para evitar un retroceso.`
          : `✅ Tu sistema está al día con la última versión (${versionLocal}).`;
        return res.redirect(
          '/pages/actualizaciones.php?msg=' + encodeURIComponent(msgTexto) + '&msg_type=success'
        );
      }
    } else if (resultado && resultado.tipo === 'sin_version') {
      // El último commit de GitHub no es una versión numérica instalable
      // (p. ej. "build-ecd6395"). NO ofrecer descarga: solo informar.
      updater.actualizacionDisponible = null;
      return res.redirect(
        '/pages/actualizaciones.php?msg=' +
          encodeURIComponent(`✅ Tu sistema está al día (${versionLocal}). ${resultado.descripcion}`) +
          '&msg_type=success'
      );
    } else {
      updater.actualizacionDisponible = null;
      return res.redirect(
        '/pages/actualizaciones.php?msg=' +
          encodeURIComponent(`✅ Tu sistema cuenta con la versión más reciente (${versionLocal}).`) +
          '&msg_type=success'
      );
    }
  }

  if (accion === 'simular_nueva_version') {
    // Generar siguiente versión según regla personalizada: 1.0.2 -> 1.0.3, 1.1.9 -> 1.2.0, 1.9.9 -> 2.0.0
    const nuevaVer = incrementarVersion(versionLocal);

    updater.ultimaComprobacion = now;
    updater.actualizacionDisponible = {
      version: nuevaVer,
      descripcion: 'Actualización incremental calculada: ' + nuevaVer,
      fecha: now,
      url: `https://github.com/${updater.repo}`
    };
    updater.alertaNotificacion = {
      tipo: 'info',
      titulo: 'Nueva Versión Detectada',
      mensaje: `Nueva versión ${nuevaVer} lista para comprobar el ciclo de actualización.`
    };
    return res.redirect(
      '/pages/actualizaciones.php?msg=' +
        encodeURIComponent(`Simulación incremental activada: se ha detectado la versión ${nuevaVer}.`) +
        '&msg_type=warning'
    );
  }

  if (accion === 'simular_para_bat') {
    const flagPath = path.join(__dirname, 'data', 'simular_actualizacion.flag');
    fs.writeFileSync(flagPath, '1', 'utf-8');
    return res.redirect(
      '/pages/actualizaciones.php?msg=' +
        encodeURIComponent('⚙️ Simulación programada. Al ejecutar iniciar_gimnasio.bat (o iniciar_gimnasio.sh), detectará la nueva versión, la descargará e instalará sin tocar tu base de datos y se relanzará automáticamente.') +
        '&msg_type=info'
    );
  }

  if (accion === 'cancelar_simulacion_bat') {
    const flagPath = path.join(__dirname, 'data', 'simular_actualizacion.flag');
    if (fs.existsSync(flagPath)) {
      try { fs.unlinkSync(flagPath); } catch (_) {}
    }
    return res.redirect(
      '/pages/actualizaciones.php?msg=' +
        encodeURIComponent('✅ Simulación de iniciar_gimnasio.bat cancelada.') +
        '&msg_type=success'
    );
  }

  if (accion === 'instalar_actualizacion') {
    const actualizacion = updater.actualizacionDisponible;
    if (!actualizacion) {
      return res.redirect(
        '/pages/actualizaciones.php?msg=' + encodeURIComponent('No hay ninguna actualización pendiente de instalar.') + '&msg_type=warning'
      );
    }

    // 1. RESPALDO AUTOMÁTICO DE SEGURIDAD PREVIO (Punto de restauración)
    const snapshot = {
      users: JSON.parse(JSON.stringify(store.users)),
      clients: JSON.parse(JSON.stringify(store.clients)),
      memberships: JSON.parse(JSON.stringify(store.memberships)),
      clientmemberships: JSON.parse(JSON.stringify(store.clientmemberships)),
      products: JSON.parse(JSON.stringify(store.products)),
      sales: JSON.parse(JSON.stringify(store.sales)),
      global_sales: JSON.parse(JSON.stringify(store.global_sales)),
      saleitems: JSON.parse(JSON.stringify(store.saleitems)),
      ventas_externas_realizadas: JSON.parse(JSON.stringify(store.ventas_externas_realizadas)),
      client_debts: JSON.parse(JSON.stringify(store.client_debts)),
      debt_payments: JSON.parse(JSON.stringify(store.debt_payments)),
      membership_sales: JSON.parse(JSON.stringify(store.membership_sales)),
      visits: JSON.parse(JSON.stringify(store.visits)),
      temp_visits: JSON.parse(JSON.stringify(store.temp_visits)),
      expenses: JSON.parse(JSON.stringify(store.expenses))
    };

    updater.puntosDeRestauracion.unshift({
      fecha: now,
      etiqueta: `Respaldo Previo a ${actualizacion.version}`,
      resumen: `${store.clients.length} socios, ${store.products.length} productos, ${store.global_sales.length} ventas`,
      snapshot
    });

    // 2. APLICAR ACTUALIZACIÓN (solo en la web local: NO hay descarga .zip)
    const versionAnterior = updater.versionActual;

    // Proteccion anti-retrocesos: si por datos residuales del estado llegara
    // una "actualizacion" con version IGUAL o INFERIOR a la instalada, no
    // instalar nada (eso era lo que permitia el bucle de re-descargas).
    if (compararVersiones(actualizacion.version, versionLocal) <= 0) {
      updater.actualizacionDisponible = null;
      return res.redirect(
        '/pages/actualizaciones.php?msg=' +
          encodeURIComponent(`🛡️ No se instaló nada: la versión pendiente (${actualizacion.version}) no es superior a la instalada (${versionLocal}).`) +
          '&msg_type=info'
      );
    }

    updater.versionActual = actualizacion.version;
    updater.actualizacionDisponible = null;

    // PERSISTIR la nueva versión en data/version.json. Sin esto, la versión
    // instalada solo vivía en la base de datos (memoria) y data/version.json
    // seguía reportando la versión vieja, haciendo que tanto esta página como
    // comprobar_y_actualizar.js volvieran a marcar siempre una versión
    // superior disponible en el repositorio aunque ya estuviera instalada.
    try {
      guardarVersionLocal(actualizacion.version, {
        descripcion: actualizacion.descripcion || 'Actualización instalada desde la webapp.',
        ultimoCommitSha: actualizacion.commitSha || ''
      });
      updater.versionActual = obtenerVersionLocal();
    } catch (errGuardarVer) {
      console.warn('[Actualizador] No se pudo persistir la versión instalada:', errGuardarVer.message);
    }

    store.historial_actualizaciones.push({
      id: nextId('historial_actualizaciones'),
      version: actualizacion.version,
      fecha: now,
      descripcion: actualizacion.descripcion || 'Actualización automática sincronizada desde GitHub.',
      origen: `GitHub (${updater.repo})`,
      estado: 'instalada',
      baseDeDatosPreservada: true
    });

    // Regenerar el archivo zip del proyecto actualizado
    generarZipProyecto();

    // 3. GENERAR ALERTA VISIBLE PARA EL USUARIO
    updater.alertaNotificacion = {
      tipo: 'exito',
      titulo: '¡Actualización Exitosa!',
      mensaje: `El sistema se actualizó correctamente de ${versionAnterior} a ${actualizacion.version}. Tus socios, cobros, adeudos e inventario fueron respaldados y preservados al 100%.`
    };

    // Si la instalación reemplazó archivos propios del servidor (server.js o
    // views/), ofrecer el reinicio en caliente para cargar los archivos nuevos.
    const archivosRecibidos = Array.isArray(actualizacion.archivos) ? actualizacion.archivos : [];
    const tocaArchivosServidor =
      fs.existsSync(RUTA_MARCADOR_REINICIO) ||
      archivosRecibidos.some(a => /(^|\/)(server\.js|package(-lock)?\.json|views\/)/i.test(String(a)));

    if (tocaArchivosServidor) {
      return res.send(`<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Actualización instalada</title>
<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.card{text-align:center;max-width:560px;padding:2rem}.ok{color:#22c55e;font-size:3rem}.btn{display:inline-block;margin-top:1rem;padding:.6rem 1.4rem;border-radius:.5rem;text-decoration:none;font-weight:600}
.verde{background:#22c55e;color:#fff}.gris{background:#334155;color:#cbd5e1;margin-left:.5rem}</style></head>
<body><div class="card"><div class="ok">✅</div><h2>Actualización ${actualizacion.version} instalada</h2>
<p>La nueva versión incluye cambios en el propio servidor. Para dejarla funcionando al 100%, pulsa <b>Reiniciar ahora</b>.<br>
<b>No cerrarás sesión</b>: el navegador se reconecta solo en unos segundos y tus datos quedan intactos.</p>
<form method="POST" action="/pages/actualizaciones.php" style="margin-top:1rem">
<input type="hidden" name="accion" value="reiniciar_servidor">
<button type="submit" class="btn verde" style="border:0;cursor:pointer;font-size:1rem">🔄 Reiniciar ahora</button>
<a class="btn gris" href="/pages/actualizaciones.php">Reiniciar más tarde</a>
</form></div></body></html>`);
    }

    return res.redirect(
      '/pages/actualizaciones.php?msg=' +
        encodeURIComponent(`🎉 ¡Actualización a ${actualizacion.version} instalada con éxito! La base de datos local fue preservada intacta.`) +
        '&msg_type=success'
    );
  }

  if (accion === 'reiniciar_servidor') {
    // Reinicio manual/programado tras instalar una actualización "en caliente"
    // desde la webapp con el servidor abierto. Las sesiones viven en cookies
    // firmadas, así que el usuario NO tendrá que volver a iniciar sesión.
    try { guardarBaseDatos(); } catch (_) {}
    res.send(`<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Reiniciando...</title>
<style>body{font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;height:100vh;margin:0}
.card{text-align:center;max-width:520px;padding:2rem}.spin{font-size:3rem;animation:g 1s linear infinite;display:inline-block}
@keyframes g{to{transform:rotate(360deg)}}.btn{display:inline-block;margin-top:1rem;padding:.6rem 1.4rem;background:#22c55e;color:#fff;border-radius:.5rem;text-decoration:none;font-weight:600}</style></head>
<body><div class="card"><span class="spin">⚙️</span><h2>Reiniciando el servidor...</h2>
<p>Se están cargando los archivos de la nueva versión. Esta ventana se actualizará automáticamente en unos segundos.</p>
<a class="btn" href="/" id="reintento" style="display:none">Abrir el sistema ahora</a></div>
<script>
const inicio = Date.now();
const comprobar = setInterval(() => {
  fetch('/login.php', { cache: 'no-store' })
    .then(r => {
      if (r.ok || r.status < 500) {
        clearInterval(comprobar);
        location.replace('/pages/dashboard.php');
      }
    })
    .catch(() => {});
  if (Date.now() - inicio > 45000) {
    clearInterval(comprobar);
    document.getElementById('reintento').style.display = 'inline-block';
  }
}, 1200);
</script></body></html>`);
    // Darse 800ms para enviar la respuesta al navegador antes de relanzarse.
    setTimeout(() => relanzarServidor('Reinicio solicitado desde la webapp tras actualizar.'), 800);
    return;
  }

  if (accion === 'restaurar_punto') {
    const indice = parseInt(req.body.indice, 10);
    const punto = updater.puntosDeRestauracion[indice];
    if (punto && punto.snapshot) {
      const snap = punto.snapshot;
      Object.keys(snap).forEach(key => {
        if (store[key]) {
          store[key] = JSON.parse(JSON.stringify(snap[key]));
        }
      });
      return res.redirect(
        '/pages/actualizaciones.php?msg=' +
          encodeURIComponent(`✅ Base de datos restaurada al punto de seguridad del ${punto.fecha}.`) +
          '&msg_type=success'
      );
    }
  }

  res.redirect('/pages/actualizaciones.php');
});

// 404 fallback to dashboard
app.use((req, res) => {
  if (req.session && req.session.usuario) {
    res.redirect('/pages/dashboard.php');
  } else {
    res.redirect('/login.php');
  }
});

function iniciarServidor(puertoInicio, intentosMax = 5) {
  const puerto = parseInt(puertoInicio, 10) || 3000;
  const srv = app.listen(puerto, '0.0.0.0', () => {
    console.log(`[Gimnasio La Fabrica] Servidor iniciado con éxito en http://0.0.0.0:${puerto}`);
    console.log(`[Gimnasio La Fabrica] Acceso local disponible en: http://localhost:${puerto}`);
  });

  srv.on('error', (err) => {
    if (err.code === 'EADDRINUSE') {
      console.warn(`[Gimnasio La Fabrica] Puerto ${puerto} en uso.`);
      if (process.env.APPLET_ID || process.env.DEFAULT_APP_PORT) {
        if (intentosMax > 0) {
          console.log(`[Gimnasio La Fabrica] Reintentando en puerto ${puerto} en 1 segundo...`);
          setTimeout(() => {
            iniciarServidor(puerto, intentosMax - 1);
          }, 1000);
          return;
        }
      } else if (intentosMax > 0) {
        const siguientePuerto = puerto + 1;
        console.log(`[Gimnasio La Fabrica] Probando puerto alternativo ${siguientePuerto}...`);
        iniciarServidor(siguientePuerto, intentosMax - 1);
        return;
      }
      console.error('[Gimnasio La Fabrica] No se pudo encontrar un puerto libre disponible.');
      process.exit(1);
    } else {
      console.error('[Gimnasio La Fabrica] Error en servidor:', err);
      process.exit(1);
    }
  });

  return srv;
}

let server = null;
if (esScriptPrincipal) {
  server = iniciarServidor(PORT);
}
export { server, app, iniciarServidor };

