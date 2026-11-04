# Favicon de Ava Platform: qué entregar y dónde ponerlo

**No se creó ni se inventó ningún favicon.** La plataforma ya está preparada: cuando coloques los archivos en la carpeta indicada, se usan directamente (no hay que tocar código).

## 1. Carpeta y nombres exactos (única ubicación)

`public/brand/` (en la raíz del repositorio: `ccg-ava-platform/public/brand/`)

| Archivo (nombre exacto) | Formato | Tamaño | Obligatorio |
|---|---|---|---|
| `favicon.ico` | ICO con **16×16, 32×32 y 48×48** dentro del mismo archivo | 48 px máx. | **Sí** (cubre navegadores antiguos, pestañas y la petición automática a `/favicon.ico`) |
| `favicon.svg` | SVG (vector, sin texto ni fuentes externas, con `viewBox` cuadrado) | escalable | Recomendado (nítido en pantallas de alta densidad; permite modo oscuro con `prefers-color-scheme`) |
| `apple-touch-icon.png` | PNG sin transparencia | **180×180** | Recomendado (iPhone/iPad al «Añadir a pantalla de inicio») |

Con **ICO + SVG + PNG de 180** se cubre todo lo necesario hoy. No hace falta más.

## 2. Qué ya está hecho

- `resources/views/partials/favicon.blade.php` emite las etiquetas `<link>` **solo de los archivos que existen y no están vacíos**, con `?v=<fecha del archivo>` para que el navegador se actualice al cambiarlos.
- Se incluye en la aplicación y en las pantallas de acceso (`resources/views/app.blade.php`) y en las páginas de error (`resources/views/errors/layout.blade.php` y `minimal.blade.php`). El sitio público (registro, login, legal) usa la misma plantilla `app.blade.php`: un solo lugar.
- `GET /favicon.ico` redirige (301) a `/brand/favicon.ico` si existe; si no, responde 404. (Se eliminó el `public/favicon.ico` anterior, que estaba **vacío (0 bytes)** y sin licencia documentada.)
- Prueba: `tests/Feature/FaviconTest.php`.
- Vite no interviene: los archivos de `public/` se sirven tal cual, sin pasar por el build.

## 3. Cómo comprobarlo

1. Copia los archivos a `public/brand/`.
2. Abre `http://localhost:8000/brand/favicon.svg` (debe verse) y `http://localhost:8000/favicon.ico` (debe redirigir al ICO).
3. Carga cualquier página con **Ctrl+F5** y mira la pestaña. Si no cambia, el navegador cachea favicons agresivamente: cierra la pestaña, borra la caché del sitio o prueba en una ventana privada.
4. Ver el origen de la página (`Ctrl+U`) y buscar `rel="icon"`: deben aparecer las líneas de los archivos que pusiste.
5. En móvil: «Añadir a pantalla de inicio» debe mostrar el `apple-touch-icon.png`.

## 4. Favicon, icono de PWA y logotipo no son lo mismo

| Pieza | Para qué | Dónde está en Ava |
|---|---|---|
| **Favicon** | Icono pequeño de la pestaña y marcadores | `public/brand/` (este documento) |
| **Icono de PWA** (192 y 512 px, `manifest.webmanifest`) | Instalar la web como aplicación | **No existe**: Ava no es una PWA. Si algún día se decide, se añade el manifiesto y los PNG 192/512 en la misma carpeta |
| **Logotipo** | Marca completa en pantallas, correos y documentos | El de cada Workspace lo sube el cliente en Configuraciones → Apariencia (PNG/JPG/WebP ≤ 1 MB, nunca SVG); el logotipo institucional de CCG/Ava **no existe en el repositorio** |

## 5. Recomendaciones para el diseño

Usa un símbolo simple que se lea a 16 px (sin texto), fondo transparente en SVG/ICO, y contrastado en pestañas claras y oscuras. Confirma que tienes **la licencia** del diseño (la del `favicon.ico` anterior era un pendiente de licencia, P16 de `pendientes.md`).
