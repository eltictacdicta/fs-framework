# FSFramework
Este es un fork de FSFramework versión 2017 para uso general.

Software libre bajo licencia GNU/LGPL.

## Advertencia
Este framework, aunque mantiene la misma lógica de FSFramework y preserva su compatibilidad básica, tiene eliminados algunos componentes como los datos de empresas y otras funcionalidades que no se necesitan en este proyecto. Esto puede provocar errores si se espera una funcionalidad completa de FSFramework. **No es 100% compatible con la funcionalidad base de facturación de FSFramework.**

En un futuro podría desarrollarse un plugin para hacerlo completamente compatible, pero actualmente no es una prioridad. **Animo a los programadores a realizar un fork y enviar un pull request para aumentar su compatibilidad.**

## Mejoras
- Compatibilidad con PHP 8.1
- Sistema de temas con auto-activación
- AdminLTE como tema por defecto

## Sistema de Temas

FSFramework incluye un sistema de temas que permite personalizar la interfaz de usuario. El tema **AdminLTE** se activa automáticamente en nuevas instalaciones, proporcionando una interfaz moderna y profesional.

Para más información, consulta la [Documentación del Sistema de Temas](THEME_SYSTEM.md).

### Características del Tema AdminLTE
- ✨ Interfaz moderna basada en AdminLTE
- 📱 Diseño responsive
- 🎨 Múltiples skins de color
- 🔧 Menú lateral colapsable

### Configuración
El tema por defecto se puede cambiar en `config.php`:
```php
define('FS_DEFAULT_THEME', 'AdminLTE');
```

## Contribuciones
Se anima a quien quiera contribuir al proyecto a realizar pull requests.

## Contacto
Para cualquier consulta, visita: https://misterdigital.es/contacto/
