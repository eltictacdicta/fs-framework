#FSframework, FacturaScripts 2017 
Este es un fork de FacturaScripts versión 2017 para uso general.

Software libre bajo licencia GNU/LGPL.

## Requisitos
- PHP 8.1 o superior
- Composer
- npm
- Servidor web (Apache, Nginx, etc.)
- Base de datos MySQL o PostgreSQL

## Instalación

### 1. Instalar dependencias PHP
Ejecuta el siguiente comando en la raíz del proyecto para instalar las dependencias PHP:
```bash
composer install
```

Esto instalará las siguientes librerías:
- Symfony 6.4 (http-foundation, http-kernel, routing, dependency-injection, config, yaml, twig-bridge, framework-bundle, twig-bundle, asset)
- Twig 3.0
- PHPMailer 6.8
- PHP XLSXWriter 0.38

### 2. Instalar dependencias JavaScript
Ejecuta el siguiente comando para instalar las dependencias JavaScript:
```bash
npm install
```

Esto instalará:
- Bootstrap 5.3
- Bootswatch 5.3
- jQuery 3.7
- Font Awesome 4.7
- Bootbox 5.5

### 3. Construir assets
Para copiar los archivos necesarios a la carpeta public/assets, ejecuta:
```bash
npm run build
```

### 4. Configuración
Accede a la aplicación a través del navegador y completa el asistente de instalación. El sistema creará automáticamente el archivo `config.php` con los parámetros de conexión a la base de datos y otras configuraciones necesarias.

## Contacto
Para cualquier consulta, visita: https://misterdigital.es/contacto/
