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
    versionActual: 'v1.2.0',
    ultimaComprobacion: nowStr,
    actualizacionDisponible: null,
    comprobacionAutomatica: true,
    alertaNotificacion: null,
    puntosDeRestauracion: []
  },

  historial_actualizaciones: [
    {
      id: 1,
      version: 'v1.2.0',
      fecha: todayStr + ' 09:00:00',
      descripcion: 'Versión base estabilizada con soporte autónomo y motor portable.',
      origen: 'GitHub (YaxkinCortez/GYM)',
      estado: 'instalada',
      baseDeDatosPreservada: true
    }
  ]
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

export function getDaysDiff(futureDateStr, baseDateStr = getTodayStr()) {
  const f = new Date(futureDateStr);
  const b = new Date(baseDateStr);
  const diffTime = f.getTime() - b.getTime();
  return Math.round(diffTime / (1000 * 60 * 60 * 24));
}

// Persistencia segura de la base de datos local
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
}

// Cargar la base de datos local al iniciar
cargarBaseDatos();
