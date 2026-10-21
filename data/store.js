// In-memory data store replicating the SQLite schema & seed data
// for Gimnasio La Fábrica Portable
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
export const RUTA_BD_LOCAL = path.join(__dirname, 'basedatos_local.json');

function formatDate(date) {
  const d = new Date(date);
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatDateTime(date) {
  const d = new Date(date);
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  const hours = String(d.getHours()).padStart(2, '0');
  const minutes = String(d.getMinutes()).padStart(2, '0');
  const seconds = String(d.getSeconds()).padStart(2, '0');
  return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
}

function addDays(baseDate, days) {
  const d = new Date(baseDate);
  d.setDate(d.getDate() + days);
  return d;
}

const now = new Date();
const todayStr = formatDate(now);
const nowStr = formatDateTime(now);

export const store = {
  users: [
    {
      id: 1,
      openId: null,
      name: 'Administrador',
      email: 'admin@gimnasio.local',
      password: 'admin123',
      loginMethod: 'local',
      role: 'admin',
      createdAt: nowStr,
      updatedAt: nowStr,
      lastSignedIn: nowStr
    },
    {
      id: 2,
      openId: null,
      name: 'Carlos Recepción',
      email: 'carlos@gimnasio.local',
      password: 'user123',
      loginMethod: 'local',
      role: 'user',
      createdAt: nowStr,
      updatedAt: nowStr,
      lastSignedIn: nowStr
    }
  ],

  memberships: [
    { id: 1, name: 'Mensual', cost: 50.0, durationDays: 30, isActive: 1 },
    { id: 2, name: 'Trimestral', cost: 130.0, durationDays: 90, isActive: 1 },
    { id: 3, name: 'Semestral', cost: 250.0, durationDays: 180, isActive: 1 },
    { id: 4, name: 'Anual', cost: 450.0, durationDays: 365, isActive: 1 },
    { id: 5, name: 'Diaria', cost: 25.0, durationDays: 1, isActive: 1 }
  ],

  products: [
    {
      id: 1,
      name: 'Proteína Whey 1kg Vainilla',
      barcode: '7501001',
      description: 'Concentrado 24g',
      purchasePrice: 45.0,
      salePrice: 75.0,
      quantity: 8,
      minStock: 3,
      isExternal: 0,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 2,
      name: 'Agua Purificada 500ml',
      barcode: '7501002',
      description: 'Bebida interna gimnasio',
      purchasePrice: 0.5,
      salePrice: 1.5,
      quantity: 25,
      minStock: 5,
      isExternal: 0,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 3,
      name: 'Bebida Isotónica 600ml',
      barcode: '7501003',
      description: 'Sabor moras',
      purchasePrice: 1.2,
      salePrice: 2.5,
      quantity: 18,
      minStock: 5,
      isExternal: 0,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 4,
      name: 'Coca Cola 600ml (Externa)',
      barcode: '7501004',
      description: 'Venta externa concesión',
      purchasePrice: 1.0,
      salePrice: 2.0,
      quantity: 20,
      minStock: 4,
      isExternal: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 5,
      name: 'Scoop de Proteína (Externo)',
      barcode: '7501005',
      description: 'Dosis individual externa',
      purchasePrice: 0.8,
      salePrice: 2.5,
      quantity: 30,
      minStock: 5,
      isExternal: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    }
  ],

  clients: [
    {
      id: 1,
      fullName: 'Juan Pérez Silva',
      gender: 'masculino',
      phone: '555-123-4567',
      address: 'Av. Siempre Viva 123',
      dateOfBirth: '1995-04-12',
      fingerprint: 'FP-8841',
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 2,
      fullName: 'María Gómez Rivas',
      gender: 'femenino',
      phone: '555-987-6543',
      address: 'Calle Juárez 45',
      dateOfBirth: '1998-08-22',
      fingerprint: 'FP-9923',
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 3,
      fullName: 'Roberto Sánchez Vela',
      gender: 'masculino',
      phone: '555-333-8822',
      address: 'Col. Centro #89',
      dateOfBirth: '1990-11-05',
      fingerprint: 'FP-4412',
      createdAt: nowStr,
      updatedAt: nowStr
    }
  ],

  clientmemberships: [
    {
      id: 1,
      clientId: 1,
      membershipId: 1,
      startDate: formatDate(addDays(now, -5)),
      endDate: formatDate(addDays(now, 25)),
      isActive: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 2,
      clientId: 2,
      membershipId: 1,
      startDate: formatDate(addDays(now, -27)),
      endDate: formatDate(addDays(now, 3)),
      isActive: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 3,
      clientId: 3,
      membershipId: 1,
      startDate: formatDate(addDays(now, -35)),
      endDate: formatDate(addDays(now, -5)),
      isActive: 0,
      createdAt: nowStr,
      updatedAt: nowStr
    }
  ],

  global_sales: [
    {
      id: 1,
      folio: '0001',
      userId: 1,
      clientId: 1,
      totalAmount: 76.5,
      totalInternal: 76.5,
      totalExternal: 0.0,
      paymentMethod: 'efectivo',
      notes: 'Venta inicial de apertura',
      createdAt: nowStr
    }
  ],

  sales: [
    {
      id: 1,
      globalSaleId: 1,
      clientId: 1,
      userId: 1,
      totalAmount: 76.5,
      discountAmount: 0.0,
      finalAmount: 76.5,
      paymentMethod: 'efectivo',
      notes: '',
      createdAt: nowStr
    }
  ],

  saleitems: [
    {
      id: 1,
      saleId: 1,
      globalSaleId: 1,
      productId: 1,
      productName: 'Proteína Whey 1kg Vainilla',
      quantity: 1,
      unitPrice: 75.0,
      subtotal: 75.0,
      userId: 1,
      createdAt: nowStr
    },
    {
      id: 2,
      saleId: 1,
      globalSaleId: 1,
      productId: 2,
      productName: 'Agua Purificada 500ml',
      quantity: 1,
      unitPrice: 1.5,
      subtotal: 1.5,
      userId: 1,
      createdAt: nowStr
    }
  ],

  ventas_externas_realizadas: [],

  client_debts: [
    {
      id: 1,
      clientId: 3,
      globalSaleId: null,
      description: 'Compra a crédito: 1 Bebida Isotónica + 1 Scoop',
      totalAmount: 5.0,
      paidAmount: 0.0,
      remainingAmount: 5.0,
      status: 'pendiente',
      userId: 2,
      createdAt: nowStr,
      updatedAt: nowStr
    }
  ],

  debt_payments: [],

  membership_sales: [
    {
      id: 1,
      clientId: 1,
      membershipId: 1,
      cost: 50.0,
      saleDate: formatDate(addDays(now, -5)) + ' 10:00:00'
    },
    {
      id: 2,
      clientId: 2,
      membershipId: 1,
      cost: 50.0,
      saleDate: formatDate(addDays(now, -27)) + ' 11:30:00'
    }
  ],

  visits: [
    {
      id: 1,
      clientId: 1,
      visitDate: todayStr,
      checkInTime: nowStr,
      checkOutTime: null,
      createdAt: nowStr
    }
  ],

  temp_visits: [
    {
      id: 1,
      visitorName: 'Carlos Gómez (Pase Diario)',
      checkInTime: nowStr,
      membershipCost: 25.0
    }
  ],

  expenses: [
    {
      id: 1,
      description: 'Compra de productos de limpieza y desinfectante',
      cost: 35.0,
      expenseDate: todayStr,
      userId: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    },
    {
      id: 2,
      description: 'Mantenimiento de cables en polea multifuncional',
      cost: 60.0,
      expenseDate: todayStr,
      userId: 1,
      createdAt: nowStr,
      updatedAt: nowStr
    }
  ],

  sistema_actualizaciones: {
    repo: 'YaxkinCortez/GYM',
    // La versión real se sincroniza desde data/version.json al arrancar el
    // servidor. El seed ya NO inventa versiones falsas tipo "v1.2.0" que
    // corrompían el historial de actualizaciones en instalaciones nuevas.
    versionActual: 'desconocida',
    ultimaComprobacion: nowStr,
    actualizacionDisponible: null,
    comprobacionAutomatica: true,
    alertaNotificacion: null,
    puntosDeRestauracion: []
  },

  // Historial de actualizaciones: inicia vacío. Solo se rellena con
  // versiones REALMENTE instaladas (ver server.js / comprobar_y_actualizar.js).
  // Antes venía con un registro inventado "v1.2.0" que corrompía la página
  // de actualizaciones en instalaciones nuevas.
  historial_actualizaciones: []
};

// Auto-increment sequence generator
export function nextId(collectionName) {
  const collection = store[collectionName] || [];
  if (collection.length === 0) return 1;
  return Math.max(...collection.map(item => item.id || 0)) + 1;
}

export function getTodayStr() {
  return formatDate(new Date());
}

export function getNowStr() {
  return formatDateTime(new Date());
}

// FIX v1.3.5: parseo MANUAL de fechas "YYYY-MM-DD" en hora LOCAL.
// new Date('2026-10-22') se interpreta como medianoche UTC y en zonas
// horarias de America (UTC-6 etc.) la fecha "retrocede un dia" al leer
// getDate()/getMonth(). Este parser evita por completo esa conversion UTC.
export function parseFechaLocal(str) {
  if (!str) return null;
  const s = String(str).trim();
  let m = s.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
  if (m) {
    return new Date(+m[1], +m[2] - 1, +m[3], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
  }
  // Formato latino dd/mm/yyyy (con o sin hora)
  m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})(?: (\d{1,2}):(\d{2})(?::(\d{2}))?)?/);
  if (m) {
    return new Date(+m[3], +m[2] - 1, +m[1], +(m[4] || 0), +(m[5] || 0), +(m[6] || 0));
  }
  // ISO con zona (committer.date de GitHub, p. ej. 2026-10-20T16:25:43Z)
  if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/.test(s)) {
    const d = new Date(s);
    return isNaN(d) ? null : d;
  }
  return null;
}

export function getDaysDiff(futureDateStr, baseDateStr = getTodayStr()) {
  const f = parseFechaLocal(futureDateStr);
  const b = parseFechaLocal(baseDateStr);
  if (!f || !b) return NaN;
  // Diferencia de dias calendarios usando solo anio/mes/dia (sin horas ni UTC)
  const fa = Date.UTC(f.getFullYear(), f.getMonth(), f.getDate());
  const ba = Date.UTC(b.getFullYear(), b.getMonth(), b.getDate());
  return Math.round((fa - ba) / (1000 * 60 * 60 * 24));
}

// Formateador latino dd/mm/aaaa (opcional + hh:mm:ss) para TODA la interfaz.
// Acepta internamente tanto ISO (yyyy-mm-dd) como el propio formato latino.
export function formatearFechaLatina(str, conHora = false) {
  if (!str) return '';
  const d = parseFechaLocal(str);
  if (!d || isNaN(d.getTime())) return String(str);
  const pad = (n) => String(n).padStart(2, '0');
  const base = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
  if (!conHora) return base;
  return `${base} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

// Inicializar configuración de respaldos si no existe
if (!store.config_respaldos) {
  store.config_respaldos = {
    rutaPersonalizada: '',
    respaldoAutomaticoDiario: true,
    respaldoProgramadoActivo: true,
    horaRespaldo: '22:00',
    ultimoRespaldoRuta: null,
    ultimoArchivoGenerado: null,
    ultimoRespaldoDiarioFecha: null,
    ultimoRespaldoProgramadoFecha: null,
    historialRespaldos: []
  };
} else {
  if (typeof store.config_respaldos.respaldoProgramadoActivo === 'undefined') {
    store.config_respaldos.respaldoProgramadoActivo = true;
  }
  if (!store.config_respaldos.horaRespaldo) {
    store.config_respaldos.horaRespaldo = '22:00';
  }
}

// Persistencia segura de la base de datos local
function marcarReinicioPendiente() {
  // Deja constancia en la BD de que hace falta reiniciar el servidor para
  // cargar archivos nuevos (server.js/views). El lanzador .bat/.sh y el
  // propio server.js lo procesan; si no se reinicia aun, el contador
  // GF_REINTENTO evita bucles infinitos.
  try {
    if (!store.config_respaldos) store.config_respaldos = {};
    store.config_respaldos.reinicioPendiente = true;
  } catch (_) {}
}

export function guardarBaseDatos() {
  try {
    const dir = path.dirname(RUTA_BD_LOCAL);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
    }
    fs.writeFileSync(RUTA_BD_LOCAL, JSON.stringify(store, null, 2), 'utf-8');
  } catch (error) {
    console.warn('[BD Local] No se pudo guardar la base de datos localmente:', error.message);
  }
}

export function realizarRespaldoEnRuta(rutaDestino, esAutomatico = false, etiquetaTipo = null) {
  if (!rutaDestino || typeof rutaDestino !== 'string' || !rutaDestino.trim()) {
    return {
      exito: false,
      error: 'Debe especificar una ruta de carpeta válida (por ejemplo: D:\\Respaldos_Gimnasio o E:\\USB_Seguridad).'
    };
  }

  const rutaLimpia = rutaDestino.trim();

  try {
    if (!fs.existsSync(rutaLimpia)) {
      fs.mkdirSync(rutaLimpia, { recursive: true });
    }

    const ahora = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    const stamp = `${ahora.getFullYear()}-${pad(ahora.getMonth() + 1)}-${pad(ahora.getDate())}_${pad(ahora.getHours())}-${pad(ahora.getMinutes())}-${pad(ahora.getSeconds())}`;
    const nombreArchivo = `backup_gimnasio_${stamp}.json`;
    const rutaCompleta = path.join(rutaLimpia, nombreArchivo);

    const tipoFinal = etiquetaTipo || (esAutomatico ? 'Automático Diario' : 'Manual a Ruta Específica');

    const snapshot = {
      sistema: 'Gimnasio La Fábrica',
      version: '1.0.4',
      fechaRespaldo: formatDateTime(ahora),
      tipoRespaldo: tipoFinal,
      resumen: {
        socios: (store.clients || []).length,
        productos: (store.products || []).length,
        ventasTotales: (store.global_sales || []).length,
        pagosMembresia: (store.member_payments || []).length,
        usuarios: (store.users || []).length
      },
      datos: store
    };

    fs.writeFileSync(rutaCompleta, JSON.stringify(snapshot, null, 2), 'utf-8');
    const stats = fs.statSync(rutaCompleta);

    if (!store.config_respaldos) {
      store.config_respaldos = {
        rutaPersonalizada: rutaLimpia,
        respaldoAutomaticoDiario: true,
        respaldoProgramadoActivo: true,
        horaRespaldo: '22:00',
        historialRespaldos: []
      };
    }
    if (!Array.isArray(store.config_respaldos.historialRespaldos)) {
      store.config_respaldos.historialRespaldos = [];
    }

    store.config_respaldos.rutaPersonalizada = rutaLimpia;
    store.config_respaldos.ultimoRespaldoRuta = formatDateTime(ahora);
    store.config_respaldos.ultimoArchivoGenerado = rutaCompleta;
    if (esAutomatico) {
      store.config_respaldos.ultimoRespaldoDiarioFecha = formatDate(ahora);
    }

    store.config_respaldos.historialRespaldos.unshift({
      id: Date.now(),
      nombreArchivo,
      rutaCompleta,
      fecha: formatDateTime(ahora),
      tamanoKb: (stats.size / 1024).toFixed(1),
      tipo: tipoFinal,
      socios: (store.clients || []).length,
      ventas: (store.global_sales || []).length
    });

    if (store.config_respaldos.historialRespaldos.length > 30) {
      store.config_respaldos.historialRespaldos = store.config_respaldos.historialRespaldos.slice(0, 30);
    }

    // Persistir estado en base local sin disparar bucle
    try {
      fs.writeFileSync(RUTA_BD_LOCAL, JSON.stringify(store, null, 2), 'utf-8');
    } catch (_) {}

    return {
      exito: true,
      archivo: rutaCompleta,
      nombreArchivo,
      tamanoKb: (stats.size / 1024).toFixed(1),
      fecha: formatDateTime(ahora),
      tipo: tipoFinal
    };
  } catch (err) {
    console.error('[Respaldo en Ruta] Error al escribir respaldo:', err.message);
    return {
      exito: false,
      error: `No se pudo guardar el archivo en '${rutaLimpia}': ${err.message}`
    };
  }
}

/**
 * Comprueba si corresponde disparar el respaldo automático programado según la hora configurada.
 * Se ejecuta periódicamente cada 30 segundos.
 */
export function verificarYEjecutarRespaldoProgramado() {
  if (!store.config_respaldos) return null;
  const cfg = store.config_respaldos;

  if (!cfg.respaldoProgramadoActivo || !cfg.rutaPersonalizada || !cfg.rutaPersonalizada.trim()) {
    return null;
  }

  const horaObjetivo = (cfg.horaRespaldo || '22:00').trim();
  const ahora = new Date();
  const pad = (n) => String(n).padStart(2, '0');
  const horaActualStr = `${pad(ahora.getHours())}:${pad(ahora.getMinutes())}`;
  const hoyStr = `${ahora.getFullYear()}-${pad(ahora.getMonth() + 1)}-${pad(ahora.getDate())}`;

  // Si la hora coincide y no se ha ejecutado hoy en esta fecha
  if (horaActualStr === horaObjetivo && cfg.ultimoRespaldoProgramadoFecha !== hoyStr) {
    console.log(`[Auto-Respaldo Programado] Ejecutando respaldo automático a las ${horaObjetivo} en ${cfg.rutaPersonalizada}...`);
    const res = realizarRespaldoEnRuta(cfg.rutaPersonalizada, true, `Automático Programado (${horaObjetivo} hrs)`);
    if (res.exito) {
      cfg.ultimoRespaldoProgramadoFecha = hoyStr;
      try {
        fs.writeFileSync(RUTA_BD_LOCAL, JSON.stringify(store, null, 2), 'utf-8');
      } catch (_) {}
      console.log(`[Auto-Respaldo Programado] Respaldo finalizado con éxito: ${res.nombreArchivo}`);
    }
    return res;
  }
  return null;
}

export function obtenerArchivosEnRuta(rutaDirectorio) {
  if (!rutaDirectorio || typeof rutaDirectorio !== 'string' || !rutaDirectorio.trim()) {
    return [];
  }
  try {
    const dir = rutaDirectorio.trim();
    if (!fs.existsSync(dir)) return [];
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    return entries
      .filter(e => e.isFile() && e.name.startsWith('backup_gimnasio_') && e.name.endsWith('.json'))
      .map(e => {
        const full = path.join(dir, e.name);
        const stats = fs.statSync(full);
        return {
          nombre: e.name,
          ruta: full,
          fecha: formatDateTime(stats.mtime),
          tamanoKb: (stats.size / 1024).toFixed(1)
        };
      })
      .sort((a, b) => b.nombre.localeCompare(a.nombre));
  } catch (e) {
    return [];
  }
}

export function cargarBaseDatos() {
  try {
    if (fs.existsSync(RUTA_BD_LOCAL)) {
      const raw = fs.readFileSync(RUTA_BD_LOCAL, 'utf-8');
      const datos = JSON.parse(raw);
      if (datos && typeof datos === 'object') {
        Object.keys(datos).forEach(key => {
          store[key] = datos[key];
        });
      }
    } else {
      guardarBaseDatos();
    }
  } catch (error) {
    console.warn('[BD Local] Advertencia al leer basedatos_local.json:', error.message);
  }

  // Asegurar que config_respaldos siempre exista tras cargar
  if (!store.config_respaldos) {
    store.config_respaldos = {
      rutaPersonalizada: '',
      respaldoAutomaticoDiario: true,
      respaldoProgramadoActivo: true,
      horaRespaldo: '22:00',
      ultimoRespaldoRuta: null,
      ultimoArchivoGenerado: null,
      ultimoRespaldoDiarioFecha: null,
      ultimoRespaldoProgramadoFecha: null,
      historialRespaldos: []
    };
  } else {
    if (typeof store.config_respaldos.respaldoProgramadoActivo === 'undefined') {
      store.config_respaldos.respaldoProgramadoActivo = true;
    }
    if (!store.config_respaldos.horaRespaldo) {
      store.config_respaldos.horaRespaldo = '22:00';
    }
  }
}

// FIX v1.3.9: NORMALIZACION AUTOMATICA DE FLAGS isActive AL CADA ARRANQUE.
// El flag isActive se deriva SIEMPRE de las fechas reales (startDate/endDate)
// comparadas con hoy, y se persiste en basedatos_local.json. Repara BD antiguas
// con isActive=0 en vigencias futuras (causa del "no se acumulan los dias" y de
// historiales que marcaban "Concluida" una membresia aun vigente).
function normalizarFlagsMembresias() {
  try {
    if (Array.isArray(store.clientmemberships)) {
      const hoyNorm = getTodayStr();
      let cambios = 0;
      store.clientmemberships.forEach(cm => {
        const diasRestantes = getDaysDiff(cm.endDate, hoyNorm);
        const inicioFuturo = getDaysDiff(hoyNorm, cm.startDate) < 0;
        const estadoReal = diasRestantes < 0 ? 'Concluida' : (inicioFuturo ? 'Programada' : 'Vigente');
        const flagReal = (diasRestantes >= 0 && !inicioFuturo) ? 1 : 0;
        if ((Number(cm.isActive) || 0) !== flagReal || cm.estado !== estadoReal) {
          cm.isActive = flagReal;
          cm.estado = estadoReal;
          cambios++;
        }
      });
      if (cambios > 0) {
        guardarBaseDatos();
        console.log(`[BD Local] Normalizadas ${cambios} membresia(s): flags isActive/estado recalculados desde fechas reales.`);
      }
    }
  } catch (errNorm) {
    console.warn('[BD Local] No se pudo normalizar flags de membresias:', errNorm.message);
  }
}

// Cargar la base de datos local al iniciar
cargarBaseDatos();
normalizarFlagsMembresias();

