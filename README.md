# Patitas Felices: tienda WooCommerce (prototipo académico)

Tienda para mascotas de Copiapó montada sobre **WordPress + WooCommerce** con el tema **Storefront**.
Es un proyecto académico (INACAP, E-Commerce, Evaluación N.º 3): los datos, marcas, precios y pagos son ficticios.

## Abrir la tienda (sin instalar nada)

**[Abrir en WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/jrmxs/patitas-felices/main/blueprint.json)**

WordPress Playground ejecuta WordPress dentro del navegador. La tienda abre en unos 20 a 40 segundos, con la sesión
del cliente demo iniciada. Cada visita parte con la tienda limpia y las fechas de los pedidos se ajustan al día actual.

| Usuario | Contraseña | Para qué |
|---|---|---|
| `felipe` | `patitas2026` | Cliente demo con mascotas (Rocky y Luna) e historial de compras. Se entra con este usuario. |
| `admin` | `password` | Administrador: pedidos, productos, envíos, pagos y el panel de ML. |

También se puede cambiar de usuario con el botón **Guía del prototipo** (esquina inferior derecha).

## Qué incluye

- **Catálogo**: 53 productos con imágenes, formatos (variaciones), stock, ofertas y reseñas verificadas.
- **Filtros nativos de WooCommerce**: especie, etapa de vida, marca, necesidad especial, categoría y precio.
- **Despacho**: zona Copiapó, Caldera y Tierra Amarilla (mismo día, $2.990, gratis sobre $40.000), retiro en tienda y courier a regiones.
- **Pagos simulados**: Webpay Plus y Mercado Pago (aprueban sin cobrar) y transferencia bancaria.
- **Checkout**: RUT para boleta, consentimiento de marketing separado y aviso de derecho a retracto.
- **Mi cuenta**: pedidos, *Mis mascotas*, *Suscripciones* y *Privacidad* (Ley N.º 21.719: preferencias y solicitudes de copia o eliminación de datos).
- **Machine learning** (WooCommerce › Patitas Felices (ML)):
  - Predicción de recompra de alimento según el perfil de la mascota.
  - Recomendador híbrido: 50 % compras conjuntas, 30 % contenido y 20 % popularidad, con explicación «¿Por qué veo esto?».
  - Segmentación K-means sobre RFM, con k elegido por coeficiente de silueta.
  - Recálculo diario con WP-Cron.
- **Marketing social**: galería #PatitasFelicesCPY con «Comprar el look», botones para compartir, campaña y programa «Invita a un amigo».

## Cómo está construido

| Archivo | Qué es |
|---|---|
| `blueprint.json` | Receta principal de Playground: importa la copia lista del sitio (`sitio.zip`) y actualiza las fechas. |
| `sitio.zip` | Copia de `wp-content` (WooCommerce, Storefront, idioma es_CL, plugin, imágenes y base de datos) generada con `herramientas/instantanea.py`. |
| `blueprint-desde-cero.json` | Alternativa: instala WooCommerce y Storefront desde WordPress.org y construye toda la tienda con el plugin (tarda 2 a 3 minutos). |
| `patitas-felices/` | Código fuente del plugin (`patitas-felices.zip` es el mismo plugin empaquetado). |
| `build.py` | Genera imágenes, datos, zip del plugin y blueprints a partir del prototipo HTML. |

Archivos principales del plugin:

```
includes/instalar.php      configuración de la tienda, datos de ejemplo y ajuste de fechas
includes/tienda.php        portada, ficha, carrito, checkout y confirmación
includes/cuenta.php        Mi cuenta: mascotas, suscripciones y privacidad
includes/recomendador.php  recomendador, predicción de recompra y K-means
includes/pagos.php         pasarelas simuladas
includes/admin.php         panel de ML para el administrador
includes/demo.php          guía del prototipo y cambio de usuario (solo en Playground)
```

Para regenerar: `python build.py` y luego `python herramientas/instantanea.py` (requiere Python con Playwright,
Microsoft Edge y la carpeta `mockup` del prototipo HTML junto a esta carpeta).
