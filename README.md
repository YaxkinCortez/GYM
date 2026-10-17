# Gimnasio La Fábrica - Sistema Integral de Gestión y Punto de Venta (POS)

Sistema completo y autónomo para la administración, control de acceso de socios, gestión de membresías, punto de venta y control financiero para el gimnasio **La Fábrica**.

---

## 🚀 Inicio Rápido y Actualización Inteligente (`iniciar_gimnasio.bat`)

El archivo ejecutable principal para arrancar el sistema en entornos Windows es:

```bat
iniciar_gimnasio.bat
```

*(Para sistemas Linux y macOS se incluye el equivalente `./iniciar_gimnasio.sh`).*

### 🔄 Flujo de Ejecución del Lanzador:
1. **Comprobación Automática en GitHub**:
   Al iniciar `iniciar_gimnasio.bat`, lo primero que hace es consultar el repositorio oficial de GitHub (`YaxkinCortez/GYM`) para verificar si existe una nueva versión o versión más reciente disponible.
2. **Descarga e Instalación Automática**:
   Si detecta una versión más actualizada, descarga automáticamente el paquete de actualización y procede a instalarlo de inmediato.
3. **Preservación Total de la Base de Datos Local**:
   Durante el proceso de actualización, **la base de datos local no se modifica ni se reinicia**. Todos los datos existentes (socios, ventas, asistencias, adeudos, abonos e inventario) permanecen 100% seguros e intactos. Además, se genera un respaldo preventivo automático antes de cualquier cambio.
4. **Auto-Relanzamiento del Sistema**:
   En cuanto concluye la instalación de la actualización, `iniciar_gimnasio.bat` se vuelve a lanzar a sí mismo automáticamente para comenzar la aplicación web actualizada de inmediato.

---

## 📋 Módulos del Sistema

1. **Panel Principal (Dashboard)**:
   - Resumen en tiempo real de ingresos diarios, socios activos, inventario bajo mínimos y asistencias del día.
   - Gráficas interactivas y accesos rápidos a cobro y registro.

2. **Control de Acceso y Visitas**:
   - Registro inmediato por número de socio, nombre o huella.
   - Validación de vigencia de membresía (activa, vencida o por vencer).
   - Registro de pases de visita individuales con cobro directo.

3. **Socios y Clientes**:
   - Padrón completo de miembros con fotografía/avatar, teléfono y ficha médica.
   - Historial de pagos, renovaciones de membresías y cálculo automático de adeudos.

4. **Punto de Venta (POS) e Inventario**:
   - Venta de productos con lector de código de barras o búsqueda rápida.
   - Clasificación entre productos del gimnasio y productos de venta externa.
   - Alertas automáticas de stock mínimo.

5. **Membresías**:
   - Configuración de planes: Diaria, Mensual, Trimestral, Semestral y Anual con precios y días de duración personalizables.

6. **Adeudos y Cobranza**:
   - Seguimiento detallado de saldos pendientes por socio y registro de abonos parciales o liquidaciones totales.

7. **Gastos del Gimnasio**:
   - Registro de egresos operativos (mantenimiento de equipo, limpieza, servicios, etc.).

8. **Actualizaciones GitHub y Respaldos**:
   - Estado de la versión instalada y sincronización con GitHub.
   - Puntos de restauración de base de datos y descarga directa del paquete completo en archivo `.zip`.

---

## 👥 Credenciales de Acceso

| Rol | Usuario / Correo | Contraseña | Permisos |
| :--- | :--- | :--- | :--- |
| **Administrador** | `admin@gimnasio.local` | `admin123` | Control total, finanzas, usuarios y actualizaciones |
| **Recepción** | `carlos@gimnasio.local` | `user123` | Registro de socios, cobros de POS y asistencias |

---

## 📦 Paquete Completo (.zip)

El archivo `Gimnasio_La_Fabrica_Completo.zip` contiene la totalidad del proyecto empaquetado para su distribución o respaldo fuera de línea, y se regenera automáticamente tras cada actualización o cambio realizado.

---

## 🛠️ Solución al error: "no se reconoce como un comando interno o externo"

Si al ejecutar `iniciar_gimnasio.bat` en Windows te aparece el mensaje:
> *"node no se reconoce como un comando interno o externo, programa o archivo por lotes ejecutable"*

Significa que tu equipo con Windows aún no tiene instalado **Node.js** o no está en la variable PATH. Para solucionarlo dispones de dos métodos rápidos:

1. **Descarga Automática Portable (Recomendado - 20 segundos)**:
   - Simplemente haz doble clic en `iniciar_gimnasio.bat`.
   - Cuando te pregunte, presiona la tecla **Enter** (Opción 1).
   - O bien haz doble clic en **`descargar_node_portable.bat`**.
   - El script descargará automáticamente el ejecutable oficial `node.exe` de nodejs.org en la subcarpeta `nodejs\`. No requiere privilegios de administrador ni alterar la configuración de tu PC.

2. **Instalador Oficial de Windows**:
   - Descarga el instalador oficial de Node.js (versión LTS) desde [https://nodejs.org/](https://nodejs.org/).
   - Instálalo asegurándote de dejar marcada la casilla *"Add to PATH"*.
   - Vuelve a ejecutar `iniciar_gimnasio.bat`.

