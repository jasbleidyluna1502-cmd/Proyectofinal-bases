# Proyecto Final de Bases de Datos — EPS Villavicencio

Sistema de reserva de citas médicas desarrollado con PHP y PostgreSQL, sin framework.

## Requisitos

- Linux
- PHP 7.4 o superior con las extensiones `pdo` y `pdo_pgsql`
- PostgreSQL y permiso para habilitar la extensión `btree_gist` usada por el SQL

## Primera instalación local

1. Cree una base de datos vacía llamada `eps_villavicencio`. Por ejemplo: `sudo -u postgres createdb eps_villavicencio`.
2. Importe el archivo SQL una sola vez: `sudo -u postgres psql -d eps_villavicencio -f proyecto.sql`. El SQL crea tablas, relaciones, reglas, disparadores y datos de demostración.
3. Cree el usuario de aplicación con permisos limitados: `sudo -u postgres psql -d postgres -f crear_usuario_app.sql`. El script pedirá la contraseña de `eps_app`; este usuario no es superusuario y solo tiene permisos de lectura y escritura sobre los objetos del proyecto.
4. Configure `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` y `DB_PASSWORD`, o use `DATABASE_URL`. Por defecto, la aplicación usa `eps_app`; `DB_PASSWORD` debe coincidir con la clave definida en el paso anterior.
5. Desde esta carpeta, inicie PHP: `php -S 127.0.0.1:8000`. Abra `http://127.0.0.1:8000`.

El inicio tiene un solo formulario. Un afiliado entra con tipo de documento, número y nombre completo. La administración se reconoce con `APP_ADMIN_TIPO_DOC`, `APP_ADMIN_DOCUMENTO` y `APP_ADMIN_NOMBRE`, y además exige `APP_ACCESS_PASSWORD`. Configura esas cuatro variables en el entorno local o en Render; no guardes datos personales ni claves en GitHub.

Para cargar las claves localmente sin mostrarlas en pantalla, en Bash puede usar `export DB_USER=eps_app; read -s -p "Clave de PostgreSQL: " DB_PASSWORD; export DB_PASSWORD; echo; read -s -p "Clave administrativa: " APP_ACCESS_PASSWORD; export APP_ACCESS_PASSWORD; echo; export APP_ADMIN_TIPO_DOC=CC; read -r -p "Documento del administrador: " APP_ADMIN_DOCUMENTO; export APP_ADMIN_DOCUMENTO; read -r -p "Nombre del administrador: " APP_ADMIN_NOMBRE; export APP_ADMIN_NOMBRE` antes de iniciar PHP.

## Módulos

- Resumen de afiliados, citas y horarios.
- Registro, actualización y consulta de afiliados.
- Registro y actualización de médicos, consultorios/salas y horarios.
- Reserva de citas seleccionando médico, fecha y hora disponible.
- Consulta de citas, marcado de atención y eliminación con liberación del horario.

## Reglas destacadas

- CC, TI y RC: solo dígitos, máximo 10. CE y PA: letras y dígitos, máximo 20.
- Nombres: no aceptan números.
- Ingresos y capacidad: no pueden ser negativos.
- Las citas toman su valor del costo del servicio y la tarifa del afiliado.
- Los disparadores del proyecto permanecen en `proyecto.sql`.
- `Database.php` aplica el patrón Singleton y entrega una única instancia PDO durante cada petición PHP.
- Las claves primarias y restricciones `UNIQUE` ya tienen índices implícitos. Se recomienda evaluar nuevas consultas con `EXPLAIN ANALYZE` antes de agregar índices, porque estos ocupan espacio y pueden hacer más costosas las escrituras.
