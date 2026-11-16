"""Arma la copia lista de la tienda (sitio.zip) que importa el blueprint principal.

Levanta WordPress Playground en Edge (sin ventana), instala WooCommerce, Storefront y el idioma es_CL,
copia el plugin local, ejecuta la instalación de datos y empaqueta wp-content (incluida la base de datos SQLite).

Uso: python wordpress/herramientas/instantanea.py
Requiere: Python con Playwright y Microsoft Edge.
"""
import base64
import os
import time

from playwright.sync_api import sync_playwright

AQUI = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(AQUI)
PLUGIN = os.path.join(REPO, "patitas-felices")
SALIDA = os.path.join(REPO, "sitio.zip")

BLUEPRINT = {
    "landingPage": "/",
    "preferredVersions": {"php": "8.3", "wp": "latest"},
    "steps": [
        {"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"}, "options": {"activate": True}},
        {"step": "installTheme", "themeData": {"resource": "wordpress.org/themes", "slug": "storefront"}, "options": {"activate": True}},
        {"step": "setSiteLanguage", "language": "es_CL"},
    ],
}

EMPAQUETAR = r"""<?php
require_once '/wordpress/wp-load.php';
wc_delete_product_transients();
delete_transient( 'patitas_catalogo' );
$zip = new ZipArchive();
$zip->open( '/tmp/sitio.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( '/wordpress/wp-content', FilesystemIterator::SKIP_DOTS ) );
$n = 0;
foreach ( $it as $f ) {
	$ruta = $f->getPathname();
	$rel  = substr( $ruta, strlen( '/wordpress/' ) );
	if ( preg_match( '#(\.lock$|debug\.log$|/upgrade/|/cache/|patitas-instalacion\.log$|/themes/twenty)#', $rel ) ) {
		continue;
	}
	$zip->addFile( $ruta, $rel );
	$n++;
}
$zip->close();
echo $n, ' archivos, ', round( filesize( '/tmp/sitio.zip' ) / 1048576, 1 ), ' MB';
"""


def correr_php(pg, codigo):
    return pg.evaluate("async c => { const r = await client.run({code: c}); return r.text + (r.errors || ''); }", codigo)


with sync_playwright() as p:
    br = p.chromium.launch(channel="msedge", args=["--headless=new"])
    pg = br.new_page()
    pg.goto("file:///" + os.path.join(AQUI, "arnes.html").replace("\\", "/"))
    pg.wait_for_function("window.listo === true", timeout=60000)
    t0 = time.time()
    pg.evaluate("b => iniciar(b)", BLUEPRINT)
    print(f"WordPress + WooCommerce + Storefront listos ({time.time() - t0:.0f} s)")

    destino = "/wordpress/wp-content/plugins/patitas-felices/"
    for base, _, nombres in os.walk(PLUGIN):
        for n in nombres:
            ruta = os.path.join(base, n)
            rel = os.path.relpath(ruta, PLUGIN).replace("\\", "/")
            with open(ruta, "rb") as f:
                b64 = base64.b64encode(f.read()).decode()
            pg.evaluate("""async ([ruta, b64]) => {
                await client.mkdir(ruta.substring(0, ruta.lastIndexOf('/')));
                await client.writeFile(ruta, Uint8Array.from(atob(b64), c => c.charCodeAt(0)));
            }""", [destino + rel, b64])
    print(correr_php(pg, "<?php require_once '/wordpress/wp-load.php'; $a = activate_plugin('patitas-felices/patitas-felices.php'); echo is_wp_error($a) ? $a->get_error_message() : 'plugin activado';"))

    t0 = time.time()
    print(correr_php(pg, "<?php require_once '/wordpress/wp-load.php'; echo implode(\"\\n\", patitas_instalar());"))
    print(f"(datos cargados en {time.time() - t0:.0f} s)")

    t0 = time.time()
    print(correr_php(pg, EMPAQUETAR), f"({time.time() - t0:.0f} s)")
    largo = pg.evaluate("async () => { window.__zip = await client.readFileAsBuffer('/tmp/sitio.zip'); return window.__zip.length; }")
    partes = []
    paso = 4 * 1024 * 1024
    for i in range(0, largo, paso):
        b64 = pg.evaluate("""([i, n]) => { const b = window.__zip.subarray(i, i + n); let s = '';
            for (let j = 0; j < b.length; j += 32768) s += String.fromCharCode.apply(null, b.subarray(j, j + 32768));
            return btoa(s); }""", [i, paso])
        partes.append(base64.b64decode(b64))
    with open(SALIDA, "wb") as f:
        f.write(b"".join(partes))
    print("guardado", SALIDA, os.path.getsize(SALIDA) // 1024, "KB")
    br.close()
