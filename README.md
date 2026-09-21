# Mi rincón

Una página familiar de favoritos de YouTube, en PHP 8.4, sin dependencias ni compilación. Mosaico a todo el ancho con scroll infinito, filtro por persona, nombres y colores editables, imágenes por URL o subidas desde el dispositivo. Los videos y canales se abren en YouTube en la misma pestaña; el botón Atrás vuelve al mosaico.

## Instalar

1. Descomprimí el ZIP en el hosting. Incluye `index.php`, `app.php`, `login.php`, `.htaccess` y las carpetas `install`, `assets`, `data` y `uploads` con sus archivos `.htaccess`. No subas `.git` ni `.local`.
2. Seleccioná PHP 8.4 con `pdo_mysql` para MySQL, o `pdo_sqlite` para SQLite. PHP necesita escritura en `data` y `uploads`.
3. Abrí `https://tudominio.com/install/` (o `https://tudominio.com/favoritos/install/` si lo subiste a esa subcarpeta).
4. Elegí **MySQL del hosting** e ingresá servidor, puerto, nombre de la base, usuario y clave de la base. Creá previamente una base vacía dedicada a esta app desde el panel del hosting y asignale un usuario con permisos para crear y modificar tablas. También podés elegir SQLite, que no requiere credenciales.
5. Creá el primer usuario administrador y su contraseña. Al terminar, entrás automáticamente a la edición y `/install/` queda bloqueado.
6. Añadí las personas y sus favoritos. **Salir** cierra la sesión; para volver a editar, usá **Editar** e ingresá tus credenciales.

En un hosting con cPanel, descomprimí el ZIP dentro de `public_html` para usar el dominio principal, o dentro de `public_html/favoritos` para usar `https://tudominio.com/favoritos/`. Funciona en ambos casos sin cambiar rutas. No requiere Node.js, Composer, consola ni procesos en segundo plano en el hosting. Activá la extensión elegida desde el selector de PHP del panel. MySQL 8 y MariaDB con InnoDB son compatibles.

La misma dirección funciona desde computadora, Android y iPhone. No hay que instalar una app: el mosaico se adapta al ancho, los botones son táctiles y los formularios permiten seleccionar fotos del teléfono.

El instalador crea las tablas automáticamente y guarda la conexión en `data/config.php`. La contraseña del administrador se guarda como hash en la base de datos. Una instalación completada no puede repetirse desde la web ni reemplazar al administrador. No incluye videos de ejemplo ni personas precargadas.

### Actualizar desde la versión anterior

Subí todos los archivos del nuevo ZIP conservando `data` y `uploads`, y abrí `/install/` para crear el administrador. Si elegís SQLite, conserva la base anterior. Si elegís MySQL y existe `data/favorites.sqlite`, copia automáticamente personas, favoritos y el título a la base nueva; para esa importación necesitás ambas extensiones PDO. Las fotos continúan en `uploads` y la base SQLite original no se borra. Una vez instalado, conservá también `data/config.php` al actualizar.

## Uso

- Acepta videos y canales, por ejemplo `https://www.youtube.com/@GameToonsEspanol`. También admite canales con rutas `/channel/`, `/c/` y `/user/`. Se detecta el tipo automáticamente y la tarjeta abre el video o el canal correspondiente.
- En los canales podés subir una foto o pegar la dirección de una imagen. Sin imagen personalizada se muestra una portada genérica; no se descarga automáticamente el avatar del canal.
- El instalador también adapta las bases SQLite de la versión que solo admitía videos, conservando los favoritos existentes.

- Cada favorito pertenece a una persona. Para compartir el mismo video, añadilo para cada persona.
- Podés editar el texto, el enlace, la persona, la imagen y el orden. Los números menores aparecen primero; en un empate, el más reciente aparece primero.
- Si no elegís imagen, se usa la miniatura de YouTube. Una foto subida tiene prioridad sobre el enlace de imagen. Para volver a la miniatura automática, vaciá el campo de imagen y guardá sin subir un archivo.
- Fotos JPG, PNG, WebP y GIF de hasta 5 MB. El hosting también debe permitir ese tamaño: `upload_max_filesize=5M` o mayor y `post_max_size=8M` o mayor. Una carga rechazada requiere volver a seleccionar el archivo.
- Eliminar una persona también elimina sus favoritos. Se pide confirmación antes de eliminar.
- El mosaico tiene 2 columnas en celulares, 3 en computadora y hasta 5 en pantallas grandes. Al acercarte al final, carga automáticamente otros 9 favoritos respetando el filtro activo. Si falla la conexión, aparece Reintentar; sin JavaScript, el enlace Cargar más permite avanzar de página.
- El encabezado dice **Mis videos**, con icono rojo y fondo blanco. La opción de nombre editable cambia el título de la pestaña del navegador.

## Edición y alojamiento

Los favoritos son públicos. Toda edición, borrado, cambio de personas y subida de fotos requiere una sesión de administrador. Las sesiones caducan tras 12 horas sin actividad. Usá HTTPS en el hosting para que las credenciales viajen cifradas. Los formularios incluyen protección CSRF y la sesión se renueva al iniciar y cerrar sesión.

En Apache 2.4 los `.htaccess` incluidos bloquean el acceso web a la base y deshabilitan el listado de carpetas. El servidor debe permitir esas directivas (`AllowOverride`). En Nginx los `.htaccess` no se aplican: configurá una regla que deniegue el acceso a `/data/` (adaptá el prefijo si instalás en una subcarpeta), deshabilitá el listado y la ejecución de scripts en `/uploads/`. Ejemplo para instalación en la raíz: `location ^~ /data/ { deny all; }`. No expongas la base como archivo público.

Hacé copias de `data` y `uploads` cuando no estés editando; si usás MySQL, exportá también la base desde el panel del hosting. Para actualizar, reemplazá el código y los estilos conservando esas carpetas y `data/config.php`. No hay recursos externos salvo las imágenes y los enlaces elegidos. El instalador puede eliminarse una vez finalizado; queda bloqueado aunque lo conserves.

## Probar localmente

Con PHP y la extensión PDO elegida: `php -S 127.0.0.1:8080`, y abrí `http://127.0.0.1:8080/install/`. El servidor integrado es solo para pruebas locales; no aplica `.htaccess`.
