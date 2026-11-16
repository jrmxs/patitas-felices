"""Prepara el plugin de WordPress a partir del prototipo HTML.

Genera:
  patitas-felices/data/productos.json      catálogo (mismo que el prototipo)
  patitas-felices/assets/img/...           imágenes PNG de productos, comunidad, portada y logo
  patitas-felices.zip                      plugin listo para instalar
  blueprint.json                           receta de WordPress Playground

Uso:  python wordpress/build.py            (regenera todo)
      python wordpress/build.py --sin-img  (solo datos, zip y blueprint)
"""
import json
import os
import sys
import time
import zipfile

AQUI = os.path.dirname(os.path.abspath(__file__))
RAIZ = os.path.dirname(AQUI)
PLUGIN = os.path.join(AQUI, "patitas-felices")
IMG = os.path.join(PLUGIN, "assets", "img")
REPO_RAW = "https://raw.githubusercontent.com/jrmxs/patitas-felices/main"

LOGO_HTML = """<!doctype html><html><head>
<link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@800&family=Nunito+Sans:wght@700&display=swap" rel="stylesheet">
<style>body{margin:0;background:transparent}
#l{display:inline-flex;align-items:center;gap:14px;padding:6px 10px 6px 4px}
b{font-family:'Baloo 2';font-size:44px;font-weight:800;color:#1E6B66;line-height:1;display:block}
span{font-family:'Nunito Sans';font-size:13px;letter-spacing:.16em;color:#4A6168;font-weight:700}</style></head>
<body><div id="l"><svg width="64" height="64" viewBox="0 0 40 40"><circle cx="20" cy="20" r="20" fill="#1E6B66"/>
<g fill="#FFC94A"><ellipse cx="20" cy="25" rx="7.5" ry="6.5"/><circle cx="11" cy="17" r="3.2"/><circle cx="16.5" cy="11.5" r="3.2"/>
<circle cx="23.5" cy="11.5" r="3.2"/><circle cx="29" cy="17" r="3.2"/></g></svg>
<div><b>Patitas Felices</b><span>COPIAPÓ · ATACAMA</span></div></div></body></html>"""


def imagenes_y_datos(con_img=True):
    from playwright.sync_api import sync_playwright
    os.makedirs(os.path.join(IMG, "productos"), exist_ok=True)
    with sync_playwright() as p:
        br = p.chromium.launch(channel="msedge")
        pg = br.new_page()
        pg.goto("file:///" + os.path.join(RAIZ, "mockup", "index.html").replace("\\", "/"))
        pg.wait_for_function("typeof P !== 'undefined' && P.length > 50")
        productos = pg.evaluate("P.map(p => ({...p}))")
        if con_img:
            lienzo = br.new_page(viewport={"width": 900, "height": 900}, device_scale_factor=1)

            def png(svg, ruta, ancho, alto):
                lienzo.set_content(f'<html><body style="margin:0;background:transparent">{svg}</body></html>')
                lienzo.locator("svg").first.screenshot(path=ruta, omit_background=True)

            for prod in productos:
                svg = pg.evaluate("id => art(byId(id), 600)", prod["id"])
                png(svg, os.path.join(IMG, "productos", prod["id"] + ".png"), 600, 600)
            ugc = [["dog", "#FFE2B8", "#C98A4B"], ["cat", "#E3F0EE", "#6B6B6B"],
                   ["dog", "#FBE6F1", "#3B3B3B"], ["cat", "#FFF1D6", "#E9853A"]]
            for i, (k, bg, fur) in enumerate(ugc, 1):
                svg = pg.evaluate("a => pet(a[0], a[1], a[2], 480, desierto)", [k, bg, fur])
                png(svg, os.path.join(IMG, f"comunidad-{i}.png"), 480, 480)
            hero = pg.evaluate("""() => { const d = document.createElement('div'); d.innerHTML = V.inicio();
                const s = d.querySelector('.hero-art svg'); s.setAttribute('width', 800); s.setAttribute('height', 520);
                s.removeAttribute('style'); return s.outerHTML; }""")
            png(hero, os.path.join(IMG, "portada.png"), 800, 520)
            logo = br.new_page(device_scale_factor=2)
            logo.set_content(LOGO_HTML)
            logo.wait_for_timeout(1500)
            logo.locator("#l").screenshot(path=os.path.join(IMG, "logo.png"), omit_background=True)
        br.close()
    os.makedirs(os.path.join(PLUGIN, "data"), exist_ok=True)
    limpio = [{k: v for k, v in prod.items() if k not in ("c", "c2", "type")} for prod in productos]
    with open(os.path.join(PLUGIN, "data", "productos.json"), "w", encoding="utf-8") as f:
        json.dump(limpio, f, ensure_ascii=False, indent=1)
    print(len(limpio), "productos")


def empaquetar():
    destino = os.path.join(AQUI, "patitas-felices.zip")
    with zipfile.ZipFile(destino, "w", zipfile.ZIP_DEFLATED) as z:
        for base, _, archivos in os.walk(PLUGIN):
            for a in archivos:
                ruta = os.path.join(base, a)
                z.write(ruta, os.path.relpath(ruta, AQUI).replace("\\", "/"))
    print("zip:", os.path.getsize(destino) // 1024, "KB")


def blueprint():
    v = time.strftime("%Y%m%d%H%M%S")
    bp = {
        "$schema": "https://playground.wordpress.net/blueprint-schema.json",
        "meta": {
            "title": "Patitas Felices: tienda WooCommerce",
            "description": "Prototipo académico (INACAP, E-Commerce) de tienda para mascotas en Copiapó sobre WordPress + WooCommerce.",
            "author": "jrmxs",
            "categories": ["WooCommerce"],
        },
        "landingPage": "/",
        "preferredVersions": {"php": "8.3", "wp": "latest"},
        "steps": [
            {"step": "installPlugin", "pluginData": {"resource": "wordpress.org/plugins", "slug": "woocommerce"},
             "options": {"activate": True}},
            {"step": "installTheme", "themeData": {"resource": "wordpress.org/themes", "slug": "storefront"},
             "options": {"activate": True}},
            {"step": "setSiteLanguage", "language": "es_CL"},
            {"step": "installPlugin", "pluginData": {"resource": "url", "url": f"{REPO_RAW}/patitas-felices.zip?v={v}"},
             "options": {"activate": True}},
            {"step": "runPHP", "code": "<?php require_once '/wordpress/wp-load.php'; patitas_instalar();"},
            {"step": "login", "username": "felipe", "password": "patitas2026"},
        ],
    }
    with open(os.path.join(AQUI, "blueprint.json"), "w", encoding="utf-8") as f:
        json.dump(bp, f, ensure_ascii=False, indent=2)
    print("blueprint v", v)


if __name__ == "__main__":
    imagenes_y_datos(con_img="--sin-img" not in sys.argv)
    empaquetar()
    blueprint()
