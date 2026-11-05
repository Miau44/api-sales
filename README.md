# API REST de ventas

Backend de la prueba técnica Full Stack. Esta aplicación usa Laravel 11 y ofrece endpoints JSON para consultar productos, registrar ventas, consultar el historial y eliminar una venta con devolución de inventario.

> Esta guía explica el código que existe hoy. Los descuentos opcionales del enunciado original no están implementados.

## 1. Requisitos

| Herramienta | Requisito del proyecto |
| --- | --- |
| PHP | 8.2 o superior; Composer declara `^8.2` |
| Composer | Composer 2 recomendado |
| Base de datos de ejecución | MySQL; el esquema correctivo también admite SQLite |
| Extensiones PHP | PDO y el driver de la base elegida (`pdo_mysql` o `pdo_sqlite`) |

Laravel resuelto por `composer.lock`: **11.48.0**. En el ambiente de trabajo de la prueba se utiliza PHP 8.3 y MySQL 8.4. Las pruebas automatizadas usan SQLite en memoria, no la base configurada en el `.env`.

## 2. Arquitectura para orientarse

- `routes/api.php` registra las rutas con prefijo `/api`.
- `app/Http/Controllers/Api` contiene los controladores HTTP. Estos traducen la solicitud al formato de respuesta JSON.
- `app/Http/Requests/StoreSaleRequest.php` valida la entrada de `POST /api/sales`.
- `app/Services/SaleService.php` contiene las operaciones de negocio de venta, inventario, consulta y eliminación.
- `app/Models` contiene los modelos Eloquent y sus relaciones con la base de datos.
- `app/Support/Money.php` convierte importes decimales a centavos enteros para hacer cálculos sin depender de operaciones binarias de punto flotante.
- `database/migrations` crea y prepara las tablas.
- `database/seeders` agrega datos de demostración en ambientes apropiados.
- `tests/Feature` prueba los endpoints y reglas principales.

La API no expone endpoints para crear, editar ni borrar productos. Se utilizan los productos que ya existen en la base, tal como pide la prueba original.

## 3. Instalación local

Abre una terminal dentro de `api-sales`.

### 3.1 Instalar paquetes de PHP

```bash
composer install
```

Composer instala exactamente las versiones fijadas en `composer.lock` y ejecuta el descubrimiento de paquetes de Laravel.

### 3.2 Preparar `.env`

Crea una copia local del ejemplo. En PowerShell:

```powershell
Copy-Item .env.example .env
```

En macOS, Linux o Git Bash:

```bash
cp .env.example .env
```

Edita las variables de conexión para que correspondan a una base de desarrollo que ya hayas creado:

```dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nombre_de_tu_base_local
DB_USERNAME=tu_usuario_local
DB_PASSWORD=tu_clave_local
```

No copies credenciales reales a este README, al código fuente ni a Git. El archivo `.env` está ignorado por Git; `.env.example` es una plantilla y no debe contener claves reales.

Genera una clave para la aplicación:

```bash
php artisan key:generate
```

Este comando escribe `APP_KEY` en tu `.env`. No publiques su valor. Esta clave cifra datos de Laravel; si se pierde, los datos cifrados con la anterior no se podrán descifrar.

### 3.3 Crear el esquema

Confirma que `DB_DATABASE` apunta a tu base local correcta y ejecuta:

```bash
php artisan migrate:status
php artisan migrate
```

No uses `php artisan migrate:fresh` en una base que contenga información que quieras conservar: ese comando elimina las tablas y las crea de nuevo.

La migración `2026_11_04_000000_prepare_sales_schema.php` admite MySQL y SQLite. Antes de cambiar columnas, revisa relaciones huérfanas, stock negativo y cantidades no positivas. Si encuentra datos incompatibles, se detiene y explica qué debe corregirse; no los borra automáticamente. Otros drivers se rechazan antes de modificar el esquema. El rollback también se detiene si los datos exceden el rango de las columnas originales.

### 3.4 Datos para desarrollo

En una base local nueva, ejecuta:

```bash
php artisan db:seed
```

`DatabaseSeeder` garantiza el usuario de ventas con ID 1:

- Si el ID 1 ya existe, lo conserva sin cambiar sus datos.
- En `local` y `testing`, si no existe, crea el usuario de demostración definido por el seeder.
- Si el correo de demostración ya pertenece a otra persona, informa el conflicto y no sobrescribe ese usuario.
- En producción, no crea credenciales de demostración: si falta el ID 1, se detiene y requiere provisionarlo explícitamente.

El usuario de demostración solo sirve para desarrollo y pruebas; no es una cuenta para producción. La API actual no tiene inicio de sesión ni necesita enviar contraseña para vender. No reutilices credenciales de ejemplo.

Los productos se agregan solo cuando la tabla `products` está vacía. Son fixtures de demostración, con imágenes externas de Unsplash; no representan inventario comercial real. Ejecuta el seeder general solo en una base que hayas confirmado como local o de pruebas. Ejecutar `ProductSeeder` directamente sobre una tabla ya poblada puede insertar duplicados.

### 3.5 Levantar el servidor

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

La API queda disponible en `http://127.0.0.1:8000/api`. La ruta `GET /api/test` devuelve un indicador de estado y datos de diagnóstico de la aplicación; no debe exponerse públicamente en producción sin revisar esa información.

## 4. Esquema y modelos

Las claves primarias de las tablas de negocio usan `BIGINT UNSIGNED`. Las claves foráneas tienen tipos compatibles.

| Tabla | Datos importantes |
| --- | --- |
| `users` | ID `BIGINT UNSIGNED`; usuario de ventas con ID 1 |
| `products` | `name`, `price DECIMAL(10,2)`, `stock UNSIGNED INT`, `image` opcional y timestamps |
| `sales` | `user_id BIGINT UNSIGNED`, `total DECIMAL(10,2)`, timestamps y `deleted_at` para borrado lógico |
| `sale_details` | `sale_id` y `product_id` `BIGINT UNSIGNED`, `quantity UNSIGNED INT`, precio histórico y subtotal `DECIMAL(10,2)`; no tiene timestamps |

Relaciones Eloquent:

- `User hasMany Sale` y `Sale belongsTo User`.
- `Sale hasMany SaleDetail` y `SaleDetail belongsTo Sale`.
- `Product hasMany SaleDetail` y `SaleDetail belongsTo Product`.

Las ventas impiden borrar físicamente el usuario asociado y los productos que tienen detalles. Los detalles tienen una clave foránea con borrado en cascada si se borra físicamente una venta; la operación pública de borrar una venta no hace eso: usa `SoftDeletes` y conserva los detalles históricos.

La base protege además los valores de inventario y cantidad. MySQL usa columnas `UNSIGNED` y triggers para impedir `sale_details.quantity <= 0`; SQLite usa triggers compatibles para impedir cantidades no positivas y stock negativo.

## 5. Endpoints y contratos JSON

Envía `Accept: application/json` en las solicitudes. Laravel también configura las rutas bajo `/api/*` para responder en JSON cuando ocurre una excepción.

### 5.1 Consultar productos

```http
GET /api/products
```

Responde `200 OK` con un array. Cada producto incluye `id`, `name`, `price`, `stock` e `image`. El precio viene de la base de datos con dos decimales y `image` puede ser `null` o una URL/ruta almacenada.

```json
[
  {
    "id": 1,
    "name": "Laptop Lenovo ThinkPad",
    "price": "7500.00",
    "stock": 10000,
    "image": "https://images.unsplash.com/photo-1517336714731-489689fd1ca8"
  }
]
```

La prueba original enumera `id`, `name`, `price` y `stock`; la implementación añade `image` para que el frontend pueda mostrar fotografías.

### 5.2 Registrar una venta

```http
POST /api/sales
Content-Type: application/json
```

El formato original de la prueba incluye el usuario estático:

```json
{
  "user_id": 1,
  "items": [
    { "product_id": 1, "quantity": 2 },
    { "product_id": 3, "quantity": 1 }
  ]
}
```

El frontend actual envía solo `items`; el backend asigna internamente `user_id = 1`. Si se envía `user_id`, debe ser el entero `1`. El cliente no debe enviar precios, subtotales ni total: el servidor los calcula desde la base.

Respuesta `201 Created`:

```json
{
  "message": "Venta creada correctamente",
  "sale_id": 1,
  "total": 15300.00
}
```

Reglas de entrada: debe existir al menos un artículo; cada producto debe existir; `quantity` debe ser un entero entre 1 y 4,294,967,295. Si se repite un producto, el backend suma las cantidades antes de comprobar y descontar el stock.

### 5.3 Consultar ventas

```http
GET /api/sales
```

Responde `200 OK` con un array ordenado de más reciente a más antiguo, usando el ID como desempate. Las ventas eliminadas lógicamente no se incluyen. Se cargan los detalles y el producto asociado sin hacer una consulta separada por cada fila.

```json
[
  {
    "id": 1,
    "total": 15300,
    "created_at": "2026-11-05",
    "details": [
      {
        "product": "Laptop Lenovo ThinkPad",
        "quantity": 2,
        "price": 7500,
        "subtotal": 15000
      }
    ]
  }
]
```

`price` es el precio histórico de la línea, no el precio actual del catálogo. `created_at` es una fecha (`YYYY-MM-DD`) o `null`.

### 5.4 Eliminar una venta

```http
DELETE /api/sales/1
```

Responde `200 OK`:

```json
{ "message": "Venta eliminada correctamente" }
```

El borrado es lógico. Los detalles y precios históricos se conservan y el stock vendido se restituye una sola vez. Una venta inexistente o ya eliminada responde `404 Not Found`.

### 5.5 Errores esperados

| HTTP | Cuándo ocurre | Respuesta habitual |
| --- | --- | --- |
| `404` | Venta inexistente o eliminada previamente | `{"message":"..."}` |
| `422` | Request inválido, producto inexistente, cantidad inválida o stock insuficiente | `{"message":"...","errors":{"items":[...]}}` |
| `409` | No existe el usuario obligatorio ID 1 o no puede completarse la restitución de stock | `{"message":"..."}` |
| `500` | Error inesperado de servidor | Respuesta JSON de error; los detalles dependen de `APP_DEBUG` |

No se guarda una venta parcial cuando falla una validación o escritura. Revisa los logs del servidor para investigar errores 500; no muestres trazas ni detalles internos al cliente.

## 6. Reglas y transacciones

### Crear venta

1. `StoreSaleRequest` valida los artículos y sus cantidades.
2. `SaleService` combina líneas repetidas y ordena los IDs de producto.
3. Dentro de una transacción, bloquea cada fila de producto con `lockForUpdate()` en orden determinista.
4. Comprueba stock y lee los precios actuales desde la base.
5. Convierte importes a centavos enteros, calcula subtotales y total, y verifica el rango permitido por `DECIMAL(10,2)`.
6. Inserta venta y detalles históricos y descuenta stock.
7. La transacción se reintenta hasta tres veces ante errores transitorios compatibles con Laravel.

Si una escritura falla, la transacción revierte tanto la venta como los detalles y cambios de stock.

### Eliminar venta

El servicio bloquea la venta, luego sus detalles, agrupa cantidades repetidas por producto y bloquea/actualiza productos en orden de ID. La devolución del stock y el `SoftDelete` de la venta ocurren en una transacción. Si algo falla, ambas operaciones se revierten. Una segunda solicitud encuentra la venta borrada y responde 404, sin devolver stock otra vez.

## 7. Requisitos originales que siguen fuera de alcance

La prueba original prohíbe crear, editar o borrar productos. La API actual sigue siendo de solo lectura para el catálogo.

El enunciado también describe descuentos opcionales por producto (0–10%). **No están implementados**: no se aceptan como funcionalidad de negocio ni se incluyen en la respuesta. No agregues descuentos al cliente esperando que el total cambie.

## 8. Pruebas automatizadas

Ejecuta:

```bash
php artisan test
```

`phpunit.xml` fuerza `APP_ENV=testing`, `DB_CONNECTION=sqlite` y `DB_DATABASE=:memory:`. `RefreshDatabase` prepara una base temporal para las pruebas Feature; no usa tu base MySQL local. Las pruebas cubren validación, precio histórico, consolidación, stock, rollback, listado, orden, exclusión de borrado lógico, restitución y errores de eliminación.

No cambies la configuración de PHPUnit para apuntar a una base con datos reales.

## 9. Seguridad y revisión de dependencias

### Variables de entorno y errores

- `.env` está ignorado por Git y no forma parte del repositorio; `.env.example` es una plantilla.
- El `.env.example` revisado tiene `APP_DEBUG=true`, y el `.env` local del workspace también lo tenía. Es útil para desarrollo, pero **debe ser `false` en producción**: con debug activo los errores pueden revelar rutas, consultas y valores internos.
- Mantén `APP_KEY`, contraseñas, tokens y credenciales solo en el entorno de despliegue. No las pegues en issues, logs compartidos ni este README.
- `bootstrap/app.php` hace que las rutas `/api/*` devuelvan JSON incluso para excepciones.

### Autorización y CORS

Las cuatro rutas de negocio no tienen middleware de autenticación/autorización configurado. El usuario ID 1 es una asignación fija de negocio, **no autentica al cliente**. Si la API se publica en una red accesible, cualquier cliente que alcance el servidor puede consultar productos/ventas, registrar ventas o solicitar eliminaciones. Protege las rutas con autenticación y autorización antes de exponer datos reales.

No existe `config/cors.php` propio en este repositorio. La configuración efectiva de Laravel inspeccionada permite `*` para orígenes, métodos y headers en `api/*`; `supports_credentials` está desactivado. CORS no sustituye autenticación. Para producción, configura solo los orígenes y métodos necesarios; una opción más sencilla es servir el frontend y enrutar `/api` por un proxy del mismo origen.

`GET /api/test` devuelve el entorno y la versión de Laravel. Retírala o protégela antes de publicar si no se necesita como health check.

### Asignación masiva y validación

Los modelos tienen listas `$fillable`. La entrada de ventas pasa por `StoreSaleRequest`; el controlador entrega al servicio solo `items`, y el servicio lee precio/stock desde la base y fija el usuario en ID 1. La operación no confía en `price`, `subtotal`, `total` ni en el `user_id` enviado. Mantén esta regla si se agregan endpoints nuevos; nunca pases todo `$request->all()` a `create()`.

### Auditoría de Composer — corte 2026-11-05

Se ejecutó `composer audit --locked`; terminó con código 1 por avisos en el lockfile. El análisis encontró **56 avisos en 15 paquetes**: 50 avisos de paquetes de producción y 6 de desarrollo. Por severidad, el reporte contiene 1 crítico, 16 altos, 25 medios, 6 bajos y 8 sin severidad informada. No se ejecutó `composer update` ni se aplicaron correcciones.

Paquetes de producción afectados: `laravel/framework`, `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `league/commonmark`, `league/flysystem`, `psy/psysh` y componentes Symfony (`http-foundation`, `http-kernel`, `mailer`, `mime`, `polyfill-intl-idn`, `process`, `routing`). Paquetes de desarrollo afectados: `phpunit/phpunit` y `symfony/yaml`.

Laravel 11 dejó de recibir correcciones de seguridad el **12 de marzo de 2026**. Este repositorio ya necesita un plan de actualización a una versión mantenida, además de revisar cada aviso de Composer. No intentes resolver el lote con una actualización masiva sin probar cambios mayores y compatibilidad de PHP/base de datos. Consulta la [política oficial de soporte de Laravel](https://laravel.com/docs/11.x/releases).

Comando para volver a revisar sin modificar dependencias:

```bash
composer audit --locked
```

## 10. Estado del repositorio y archivos locales

El remoto de esta auditoría es `https://github.com/Miau44/api-sales`, rama `mauricio.morales`. Se verificó que la referencia remota coincide con la rama local y que no están rastreados `.env`, `vendor/`, claves privadas ni credenciales. `.env.example` sí está versionado y contiene valores de plantilla.

En el workspace, `.env` y `vendor/` existen localmente pero están ignorados. No los subas. Antes de publicar revisa siempre `git status` y el contenido staged.

---

## Breve original de la prueba técnica

La prueba solicita consultar productos, registrar una venta con usuario estático ID 1, validar stock, calcular subtotales/total, consultar ventas y eliminar una venta. Exige relaciones entre modelos, validación, transacciones, control de stock, lógica de negocio, JSON y pruebas HTTP. La implementación actual cubre esos requisitos principales; el catálogo no permite mantenimiento de productos.

El brief original muestra un request con `user_id: 1`, `items`, `product_id` y `quantity`, y ejemplos de respuestas JSON. La implementación actual mantiene ese usuario estático, aunque el frontend omite el campo y el servidor lo asigna.

### Descuento opcional del brief — no implementado

El texto original propone `discount_percent` de 0 a 10, cálculo de descuento por línea y campos adicionales en el historial. Esta funcionalidad sigue siendo opcional y no existe en el código actual. Requeriría un cambio aprobado de esquema, validación, cálculos, contrato y pruebas.
