-- ====================================================================
-- ESQUEMA DDL SQLite3 - GIMNASIO LA FÁBRICA
-- ====================================================================
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    openId TEXT UNIQUE,
    name TEXT NOT NULL,
    email TEXT UNIQUE,
    password TEXT NOT NULL,
    loginMethod TEXT,
    role TEXT CHECK(role IN ('admin', 'user')) NOT NULL DEFAULT 'user',
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime')),
    lastSignedIn DATETIME
);

CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    fullName TEXT NOT NULL,
    gender TEXT CHECK(gender IN ('masculino', 'femenino', 'otro')) NOT NULL,
    phone TEXT,
    address TEXT,
    dateOfBirth DATE,
    fingerprint TEXT,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS memberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    cost REAL NOT NULL,
    durationDays INTEGER NOT NULL,
    isActive INTEGER DEFAULT 1,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    barcode TEXT UNIQUE,
    description TEXT,
    purchasePrice REAL NOT NULL,
    salePrice REAL NOT NULL,
    quantity INTEGER NOT NULL DEFAULT 0 CHECK(quantity >= 0),
    minStock INTEGER NOT NULL DEFAULT 5,
    isExternal INTEGER NOT NULL DEFAULT 0,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS global_sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folio TEXT UNIQUE NOT NULL,
    userId INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    clientId INTEGER REFERENCES clients(id) ON DELETE SET NULL,
    totalAmount REAL NOT NULL,
    totalInternal REAL NOT NULL DEFAULT 0.00,
    totalExternal REAL NOT NULL DEFAULT 0.00,
    paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
    notes TEXT,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE CASCADE,
    clientId INTEGER REFERENCES clients(id) ON DELETE SET NULL,
    userId INTEGER REFERENCES users(id) ON DELETE CASCADE,
    totalAmount REAL NOT NULL,
    discountAmount REAL DEFAULT 0.00,
    finalAmount REAL NOT NULL,
    paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
    notes TEXT,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS saleitems (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    saleId INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE CASCADE,
    productId INTEGER NOT NULL REFERENCES products(id) ON DELETE CASCADE,
    productName TEXT,
    quantity INTEGER NOT NULL,
    unitPrice REAL NOT NULL,
    subtotal REAL NOT NULL,
    userId INTEGER REFERENCES users(id) ON DELETE SET NULL,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS ventas_externas_realizadas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    globalSaleId INTEGER NOT NULL REFERENCES global_sales(id) ON DELETE CASCADE,
    productId INTEGER REFERENCES products(id) ON DELETE SET NULL,
    productName TEXT NOT NULL,
    quantity INTEGER NOT NULL,
    unitPrice REAL NOT NULL,
    subtotal REAL NOT NULL,
    userId INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS client_debts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    globalSaleId INTEGER REFERENCES global_sales(id) ON DELETE SET NULL,
    description TEXT NOT NULL,
    totalAmount REAL NOT NULL,
    paidAmount REAL NOT NULL DEFAULT 0.00,
    remainingAmount REAL NOT NULL,
    status TEXT NOT NULL DEFAULT 'pendiente',
    userId INTEGER REFERENCES users(id) ON DELETE SET NULL,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS debt_payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    debtId INTEGER NOT NULL REFERENCES client_debts(id) ON DELETE CASCADE,
    clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    amount REAL NOT NULL,
    paymentMethod TEXT NOT NULL DEFAULT 'efectivo',
    notes TEXT,
    userId INTEGER REFERENCES users(id) ON DELETE SET NULL,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS clientmemberships (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    membershipId INTEGER NOT NULL REFERENCES memberships(id) ON DELETE CASCADE,
    startDate DATE NOT NULL,
    endDate DATE NOT NULL,
    isActive INTEGER NOT NULL DEFAULT 1,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS membership_sales (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clientId INTEGER REFERENCES clients(id) ON DELETE CASCADE,
    membershipId INTEGER NOT NULL REFERENCES memberships(id) ON DELETE CASCADE,
    cost REAL NOT NULL,
    saleDate DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    clientId INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
    visitDate DATE NOT NULL,
    checkInTime DATETIME DEFAULT (datetime('now', 'localtime')),
    checkOutTime DATETIME,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE TABLE IF NOT EXISTS temp_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitorName TEXT DEFAULT 'Visitante Temporal',
    checkInTime DATETIME DEFAULT (datetime('now', 'localtime')),
    membershipCost REAL NOT NULL DEFAULT 25.00
);

CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    description TEXT NOT NULL,
    cost REAL NOT NULL,
    expenseDate DATE NOT NULL,
    userId INTEGER REFERENCES users(id) ON DELETE CASCADE,
    createdAt DATETIME DEFAULT (datetime('now', 'localtime')),
    updatedAt DATETIME DEFAULT (datetime('now', 'localtime'))
);

CREATE INDEX IF NOT EXISTS idx_sales_created ON sales(createdAt);
CREATE INDEX IF NOT EXISTS idx_visits_date ON visits(visitDate, clientId);
CREATE INDEX IF NOT EXISTS idx_cm_client_end ON clientmemberships(clientId, endDate, isActive);
CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);

-- DATOS INICIALES
INSERT INTO users (id, name, email, password, role) VALUES 
(1, 'Administrador', 'admin@gimnasio.local', 'admin123', 'admin'),
(2, 'Carlos Recepción', 'carlos@gimnasio.local', 'user123', 'user');

INSERT INTO memberships (id, name, cost, durationDays, isActive) VALUES 
(1, 'Mensual', 50.00, 30, 1),
(2, 'Trimestral', 130.00, 90, 1),
(3, 'Semestral', 250.00, 180, 1),
(4, 'Anual', 450.00, 365, 1),
(5, 'Diaria', 25.00, 1, 1);

INSERT INTO products (id, name, barcode, description, purchasePrice, salePrice, quantity, minStock, isExternal) VALUES 
(1, 'Proteína Whey 1kg Vainilla', '7501001', 'Concentrado 24g', 45.00, 75.00, 8, 3, 0),
(2, 'Agua Purificada 500ml', '7501002', 'Bebida interna gimnasio', 0.50, 1.50, 25, 5, 0),
(3, 'Bebida Isotónica 600ml', '7501003', 'Sabor moras', 1.20, 2.50, 18, 5, 0),
(4, 'Coca Cola 600ml (Externa)', '7501004', 'Venta externa concesión', 1.00, 2.00, 20, 4, 1),
(5, 'Scoop de Proteína (Externo)', '7501005', 'Dosis individual externa', 0.80, 2.50, 30, 5, 1);

INSERT INTO clients (id, fullName, gender, phone, dateOfBirth, fingerprint) VALUES
(1, 'Juan Pérez Silva', 'masculino', '555-123-4567', '1995-04-12', 'FP-8841'),
(2, 'María Gómez Rivas', 'femenino', '555-987-6543', '1998-08-22', 'FP-9923'),
(3, 'Roberto Sánchez Vela', 'masculino', '555-333-8822', '1990-11-05', 'FP-4412');

INSERT INTO clientmemberships (clientId, membershipId, startDate, endDate, isActive) VALUES
(1, 1, date('now', '-5 days'), date('now', '+25 days'), 1),
(2, 1, date('now', '-27 days'), date('now', '+3 days'), 1),
(3, 1, date('now', '-35 days'), date('now', '-5 days'), 0);
