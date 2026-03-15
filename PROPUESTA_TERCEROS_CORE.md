# Propuesta de Extraccion y Modernizacion de Terceros Core

## Resumen

Se propone extraer la gestion de clientes fuera de `facturacion_base` y convertirla en un plugin compartido, con el nombre recomendado de `clientes_core`.

El objetivo de esta fase es desacoplar el dominio de clientes para que pueda ser consumido por `facturacion_base` y por `presupuestos_y_pedidos` sin arrastrar dependencias innecesarias, y aprovechar la extraccion para crear un plugin nuevo ya modernizado, sin depender de `legacy_support`.

La idea funcional es esta:

- `cliente` deja de pertenecer a `facturacion_base`.
- `pedido_cliente`, `pedido_proveedor`, `presupuesto_cliente` y sus lineas siguen perteneciendo a `presupuestos_y_pedidos`.
- `facturacion_base` y `presupuestos_y_pedidos` pasan a depender de `clientes_core`.
- El nuevo plugin se implementa ya con Twig nativo y traducciones YAML, evitando nacer sobre compatibilidad legacy.

## Objetivo principal

Separar el dominio de clientes de la facturacion para obtener una arquitectura mas limpia, modular y reutilizable.

Esto permitiria:

- reutilizar clientes desde varios plugins sin duplicar codigo;
- reducir el acoplamiento historico entre `presupuestos_y_pedidos` y `facturacion_base`;
- preparar el sistema para futuras extracciones de otros maestros compartidos;
- modernizar una parte importante del sistema sin tener que reescribir todo de golpe.

## Contexto actual

Hoy `presupuestos_y_pedidos` depende de `facturacion_base` para acceder a modelos y estructuras que conceptualmente no forman parte del dominio de facturacion, sino del dominio de terceros.

Por ejemplo, usa `cliente` y `grupo_clientes` de forma directa en varios controladores:

- [plugins/presupuestos_y_pedidos/controller/ventas_pedido.php](plugins/presupuestos_y_pedidos/controller/ventas_pedido.php#L59)
- [plugins/presupuestos_y_pedidos/controller/ventas_presupuesto.php](plugins/presupuestos_y_pedidos/controller/ventas_presupuesto.php#L61)
- [plugins/presupuestos_y_pedidos/controller/ventas_pedidos.php](plugins/presupuestos_y_pedidos/controller/ventas_pedidos.php#L62)
- [plugins/presupuestos_y_pedidos/controller/ventas_presupuestos.php](plugins/presupuestos_y_pedidos/controller/ventas_presupuestos.php#L61)

Al mismo tiempo, la compatibilidad legacy esta planteada como transitoria. `legacy_support` se declara como una via de compatibilidad que debe retirarse:

- [plugins/legacy_support/Init.php](plugins/legacy_support/Init.php#L48)
- [plugins/legacy_support/Template/RainToTwig.php](plugins/legacy_support/Template/RainToTwig.php#L16)

Eso hace poco aconsejable crear un plugin nuevo que siga apoyandose en RainTPL o en traduccion automatica desde legacy.

## Nombre recomendado

Se recomienda usar `clientes_core`.

Es el nombre mas equilibrado por estas razones:

- no limita el plugin a clientes, aunque en esta fase el foco principal sea `cliente`;
- permite incorporar mas adelante `proveedor` sin tener que renombrar el plugin;
- encaja bien con la organizacion ya existente del proyecto, junto a `catalogo_core` y `business_data`.

Alternativas validas, pero menos recomendables:

- `clientes_core`
- `comercial_terceros`
- `crm_core`

## Alcance de esta fase

Esta propuesta esta deliberadamente acotada. La prioridad no es construir una nueva API ni reescribir todos los flujos, sino consolidar una primera extraccion util y segura.

### Incluido en esta fase

- creacion del plugin `clientes_core`;
- extraccion de `cliente`;
- extraccion de `direccion_cliente`;
- extraccion de `grupo_clientes`;
- movimiento de sus tablas XML asociadas;
- adaptacion de dependencias de `facturacion_base` y `presupuestos_y_pedidos`;
- modernizacion del nuevo plugin con Twig nativo y traducciones YAML;
- reduccion o eliminacion de dependencia con `legacy_support`;
- limpieza del dominio de `cliente` para aislar dependencias no propias.

### Fuera de alcance en esta fase

- endpoints JSON;
- API REST;
- servicios HTTP de busqueda publica;
- reescritura completa de todos los controladores consumidores;
- reescritura de todos los flujos documentales;
- modernizacion total de `facturacion_base` o `presupuestos_y_pedidos`.

Esto debe quedar expresamente fuera para que la extraccion no se convierta en una refactorizacion sin fin.

## Que debe moverse a `clientes_core`

### Modelos

En esta fase deben moverse al menos estos modelos:

- `cliente`
- `direccion_cliente`
- `grupo_clientes`

Referencias actuales:

- [plugins/facturacion_base/model/core/cliente.php](plugins/facturacion_base/model/core/cliente.php)
- [plugins/facturacion_base/model/core/direccion_cliente.php](plugins/facturacion_base/model/core/direccion_cliente.php#L26)
- [plugins/facturacion_base/model/core/grupo_clientes.php](plugins/facturacion_base/model/core/grupo_clientes.php#L26)
- [plugins/facturacion_base/model/cliente.php](plugins/facturacion_base/model/cliente.php)
- [plugins/facturacion_base/model/direccion_cliente.php](plugins/facturacion_base/model/direccion_cliente.php#L27)
- [plugins/facturacion_base/model/grupo_clientes.php](plugins/facturacion_base/model/grupo_clientes.php#L27)

### Esquema de tablas

Tambien deben moverse sus definiciones XML:

- [plugins/facturacion_base/model/table/clientes.xml](plugins/facturacion_base/model/table/clientes.xml)
- [plugins/facturacion_base/model/table/dirclientes.xml](plugins/facturacion_base/model/table/dirclientes.xml)
- [plugins/facturacion_base/model/table/gruposclientes.xml](plugins/facturacion_base/model/table/gruposclientes.xml)

Esto es obligatorio. Mover las clases sin mover el esquema no resuelve la extraccion.

### Traducciones

El nuevo plugin debe incorporar desde el inicio traducciones modernas en YAML:

- `plugins/clientes_core/translations/messages.es.yaml`
- `plugins/clientes_core/translations/messages.en.yaml`

La referencia funcional actual esta documentada en [docs/TRANSLATION.md](docs/TRANSLATION.md).

## Que no debe moverse en esta fase

No deben moverse a `clientes_core` los modelos documentales de pedidos y presupuestos.

Deben permanecer en `presupuestos_y_pedidos`:

- `pedido_cliente`
- `pedido_proveedor`
- `presupuesto_cliente`
- `linea_pedido_cliente`
- `linea_pedido_proveedor`
- `linea_presupuesto_cliente`

Referencias actuales:

- [plugins/presupuestos_y_pedidos/model/pedido_cliente.php](plugins/presupuestos_y_pedidos/model/pedido_cliente.php#L20)
- [plugins/presupuestos_y_pedidos/model/pedido_proveedor.php](plugins/presupuestos_y_pedidos/model/pedido_proveedor.php#L20)
- [plugins/presupuestos_y_pedidos/model/presupuesto_cliente.php](plugins/presupuestos_y_pedidos/model/presupuesto_cliente.php#L20)
- [plugins/presupuestos_y_pedidos/model/linea_pedido_cliente.php](plugins/presupuestos_y_pedidos/model/linea_pedido_cliente.php#L20)
- [plugins/presupuestos_y_pedidos/model/linea_pedido_proveedor.php](plugins/presupuestos_y_pedidos/model/linea_pedido_proveedor.php#L20)
- [plugins/presupuestos_y_pedidos/model/linea_presupuesto_cliente.php](plugins/presupuestos_y_pedidos/model/linea_presupuesto_cliente.php#L20)

La razon es simple: esos modelos pertenecen al dominio documental del plugin y no al dominio maestro de terceros.

## Dependencias reales de `cliente`

### Dependencias que deben ir juntas

Extraer solo la clase `cliente` no es suficiente.

El modelo `cliente` depende hoy de:

- `grupo_clientes`, por ejemplo en su instalacion y consistencia interna, visible en [plugins/facturacion_base/model/core/cliente.php](plugins/facturacion_base/model/core/cliente.php#L238)
- `direccion_cliente`, visible en [plugins/facturacion_base/model/core/cliente.php](plugins/facturacion_base/model/core/cliente.php#L356)

Ademas, `presupuestos_y_pedidos` usa `grupo_clientes` para filtros y consultas:

- [plugins/presupuestos_y_pedidos/controller/ventas_pedidos.php](plugins/presupuestos_y_pedidos/controller/ventas_pedidos.php#L373)
- [plugins/presupuestos_y_pedidos/controller/ventas_presupuestos.php](plugins/presupuestos_y_pedidos/controller/ventas_presupuestos.php#L375)

Por tanto, el minimo viable real es:

- `cliente`
- `direccion_cliente`
- `grupo_clientes`
- tablas XML asociadas

### Dependencias que conviene aislar

El modelo `cliente` tambien conserva acoplamientos historicos que no deberian formar parte del nucleo nuevo tal cual estan:

- logica de subcuentas contables en [plugins/facturacion_base/model/core/cliente.php](plugins/facturacion_base/model/core/cliente.php#L366)
- limpieza de relacion con proveedor en [plugins/facturacion_base/model/core/cliente.php](plugins/facturacion_base/model/core/cliente.php#L697)

Esto no impide la extraccion, pero obliga a revisar el modelo al moverlo.

La recomendacion es que `clientes_core` no copie `cliente` sin adaptacion, sino que lo refactorice para:

- aislar la parte contable;
- hacer opcional la relacion con proveedor;
- dejar el dominio de cliente centrado en terceros y no en integraciones colaterales.

## Arquitectura objetivo

### Dependencias deseadas

`clientes_core` deberia depender solo de:

- `catalogo_core`
- `business_data`

Y no deberia depender de:

- `facturacion_base`
- `legacy_support`

Despues de la extraccion:

- `facturacion_base` deberia depender de `clientes_core`
- `presupuestos_y_pedidos` deberia depender de `clientes_core`

De este modo, `facturacion_base` deja de ser el propietario del maestro de clientes y pasa a ser un consumidor mas.

## Estructura sugerida del plugin nuevo

La estructura recomendada para esta primera fase es la siguiente:

```text
plugins/clientes_core/
├── fsframework.ini
├── Init.php
├── model/
│   ├── cliente.php
│   ├── direccion_cliente.php
│   ├── grupo_clientes.php
│   ├── core/
│   │   ├── cliente.php
│   │   ├── direccion_cliente.php
│   │   └── grupo_clientes.php
│   └── table/
│       ├── clientes.xml
│       ├── dirclientes.xml
│       └── gruposclientes.xml
├── translations/
│   ├── messages.es.yaml
│   └── messages.en.yaml
├── src/
│   └── Twig/
│       └── TercerosExtension.php
└── themes/
    └── AdminLTE/
        └── view/
            └── terceros/
                ├── cliente_show.html.twig
                ├── cliente_list.html.twig
                └── grupo_list.html.twig
```

La carpeta `controller/` puede incorporarse en esta fase solo si realmente es necesaria para la compatibilidad transitoria. No hace falta forzar una reescritura completa de pantallas desde el dia uno.

## Propuesta de modernizacion

La extraccion no deberia consistir en copiar archivos sin mas. Conviene usarla como punto de entrada para mejorar la base tecnica del nuevo plugin.

### 1. No depender de `legacy_support`

El nuevo plugin no deberia usar:

- plantillas RainTPL `.html`;
- conversion automatica RainTPL -> Twig;
- funciones PHP registradas solo por compatibilidad legacy.

Esto es coherente con la deprecacion ya expresada en:

- [plugins/legacy_support/Init.php](plugins/legacy_support/Init.php#L48)
- [plugins/legacy_support/Template/RainToTwig.php](plugins/legacy_support/Template/RainToTwig.php#L16)

La decision recomendada es clara: el plugin nuevo nace directamente con Twig nativo.

### 2. Migrar las vistas a Twig nativo

Todas las vistas nuevas del plugin deben escribirse directamente como `.html.twig`.

No se recomienda trasladar vistas legacy tal cual ni confiar en capas de traduccion automatica.

Hay soporte real ya operativo en el proyecto:

- [themes/AdminLTE/view/admin_agente.html.twig](themes/AdminLTE/view/admin_agente.html.twig#L1)
- [themes/AdminLTE/view/master/edit_controller.html.twig](themes/AdminLTE/view/master/edit_controller.html.twig#L1)
- [src/Controller/BaseController.php](src/Controller/BaseController.php#L27)

Beneficios de hacerlo asi:

- plantillas mas legibles y mantenibles;
- menos comportamiento magico;
- menos dependencia del pasado de FS2017;
- mejor alineacion con la arquitectura actual del framework.

### 3. Usar traducciones YAML desde el inicio

El nuevo plugin debe usar el sistema moderno de traducciones basado en YAML y Twig:

- `messages.es.yaml`
- `messages.en.yaml`

Con soporte directo en Twig mediante `trans()` y `|trans`, tal y como ya ofrece [src/Twig/TranslationExtension.php](src/Twig/TranslationExtension.php#L60).

Esto evita:

- textos embebidos en vistas;
- formatos legacy de traduccion;
- dependencias de compatibilidad innecesarias.

### 4. Introducir estructura moderna sin obligar a reescribir todo

No es necesario convertir toda la gestion de clientes a controladores modernos en esta fase, pero si conviene que el plugin nazca con una estructura preparada para ello.

Referencias modernas disponibles:

- [src/Controller/BaseController.php](src/Controller/BaseController.php#L18)
- [src/Controller/TestModernController.php](src/Controller/TestModernController.php#L10)

La recomendacion pragmatica es:

- permitir una capa transitoria compatible donde haga falta;
- pero escribir las nuevas vistas y piezas nuevas de forma moderna;
- evitar añadir mas deuda legacy en el plugin nuevo.

### 5. Separar el dominio de cliente de integraciones externas

El modelo nuevo de `cliente` deberia centrarse en:

- identidad del cliente;
- datos fiscales y comerciales;
- direcciones;
- pertenencia a grupos;
- configuraciones comerciales basicas.

Y no deberia mezclar de forma obligatoria:

- contabilidad;
- relaciones con proveedor;
- reglas transversales que dependan de otros plugins.

Cuando esas relaciones existan, deben quedar mejor aisladas o marcadas como integraciones opcionales.

### 6. Preparar componentes Twig reutilizables

Aunque en esta fase no se planteen endpoints ni una nueva API, si es conveniente que las vistas del plugin se organicen en bloques reutilizables.

Ejemplos razonables:

- resumen de cliente;
- tabla de direcciones;
- selector de grupo;
- formulario base de cliente.

Esto ayudara despues a que `facturacion_base` y `presupuestos_y_pedidos` compartan UI sin duplicar demasiada logica.

### 7. Introducir pruebas desde el inicio

El nuevo plugin deberia nacer con pruebas basicas al menos para:

- carga de `cliente`;
- validaciones;
- persistencia basica;
- relacion con `direccion_cliente`;
- consistencia con `grupo_clientes`;
- funcionamiento correcto cuando ciertas integraciones no estan presentes.

Esto es especialmente importante porque la extraccion va a tocar una pieza muy usada del sistema.

## Plan de migracion por fases

### Fase 1. Extraccion minima segura

Objetivo:

- crear `clientes_core`;
- mover `cliente`, `direccion_cliente`, `grupo_clientes` y tablas;
- hacer que el resto del sistema los consuma desde ahi.

Tareas:

- crear el plugin nuevo;
- mover modelos y wrappers;
- mover XML;
- ajustar `require` y dependencias;
- validar compatibilidad con `facturacion_base` y `presupuestos_y_pedidos`.

Resultado esperado:

- `cliente` deja de estar implementado dentro de `facturacion_base`.

### Fase 2. Limpieza del dominio

Objetivo:

- eliminar o aislar acoplamientos no propios del dominio de cliente.

Tareas:

- revisar `get_subcuenta()` y `get_subcuentas()`;
- revisar `fix_db()`;
- convertir dependencias duras en opcionales cuando proceda;
- reducir el acoplamiento con proveedor y contabilidad.

Resultado esperado:

- `clientes_core` queda centrado en terceros, no en integraciones ajenas.

### Fase 3. Modernizacion de vistas

Objetivo:

- consolidar el plugin nuevo fuera del circuito legacy.

Tareas:

- crear vistas Twig nativas;
- incorporar traducciones YAML;
- evitar RainTPL y `legacy_support`;
- ordenar los componentes visuales reutilizables.

Resultado esperado:

- `clientes_core` no necesita `legacy_support` para funcionar.

### Fase 4. Adaptacion progresiva de consumidores

Objetivo:

- que `facturacion_base` y `presupuestos_y_pedidos` consuman el plugin compartido de forma estable.

Tareas:

- cambiar referencias y dependencias;
- eliminar duplicaciones de logica cuando sea razonable;
- adaptar pantallas consumidoras segun necesidad.

Resultado esperado:

- el maestro de clientes queda centralizado y reutilizable.

## Riesgos

### Riesgo 1. Extraer solo `cliente`

Problema:

- se romperan direcciones, grupos o partes de instalacion.

Mitigacion:

- mover siempre `cliente`, `direccion_cliente`, `grupo_clientes` y XML asociados juntos.

### Riesgo 2. Copiar el modelo sin limpiar acoplamientos

Problema:

- el plugin nuevo heredara deuda tecnica de contabilidad y compras.

Mitigacion:

- revisar el modelo durante la extraccion, no despues.

### Riesgo 3. Crear el plugin nuevo sobre legacy

Problema:

- naceria ya atado a una capa de compatibilidad deprecada.

Mitigacion:

- usar Twig nativo y traducciones YAML desde el inicio.

### Riesgo 4. Hacer crecer demasiado el alcance

Problema:

- si se mezcla extraccion con API, endpoints, modernizacion completa y reescritura de consumidores, el trabajo se vuelve dificil de cerrar.

Mitigacion:

- mantener esta fase centrada en el dominio de clientes y su modernizacion basica.

## Resultado esperado

Si esta propuesta se ejecuta correctamente, el sistema deberia quedar asi:

- `cliente` deja de ser responsabilidad de `facturacion_base`;
- `presupuestos_y_pedidos` conserva sus documentos y flujos propios;
- aparece un plugin compartido reutilizable para terceros;
- el nuevo plugin nace ya con una base moderna;
- se reduce la dependencia estructural con `legacy_support`.

## Recomendacion final

La estrategia recomendada es esta:

- crear `clientes_core`;
- mover `cliente`, `direccion_cliente`, `grupo_clientes` y sus tablas;
- dejar pedidos y presupuestos en `presupuestos_y_pedidos`;
- evitar endpoints y API en esta fase;
- modernizar el nuevo plugin con Twig nativo y traducciones YAML;
- usar la extraccion para limpiar dependencias historicas del dominio.

Es una propuesta realista, incremental y con buen equilibrio entre impacto funcional y mejora arquitectonica.