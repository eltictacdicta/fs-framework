<?php
/**
 * Hola Mundo - Plugin de demostración
 * 
 * Este archivo registra una sección pública en el portal.
 * El portal lo cargará automáticamente si este plugin está activo.
 * 
 * @return array Información de la sección a mostrar en el portal
 */
function hola_mundo_portal_section()
{
    return [
        'titulo' => '👋 Hola Mundo',
        'contenido' => '
            <div style="text-align: center; padding: 2rem;">
                <h1 style="font-size: 2.5rem; color: #667eea; margin-bottom: 1rem;">
                    ¡Hola Mundo!
                </h1>
                <p style="font-size: 1.2rem; color: #666;">
                    Este es un plugin de demostración que muestra cómo los plugins 
                    pueden registrar contenido público en el portal.
                </p>
                <div style="margin-top: 2rem; padding: 1.5rem; background: #f0f4ff; border-radius: 8px;">
                    <p style="margin: 0;">
                        <strong>✨ Funcionalidad:</strong> Este contenido se genera desde el plugin 
                        <code>hola_mundo</code> sin necesidad de base de datos.
                    </p>
                </div>
                <div style="margin-top: 1rem; font-size: 0.9rem; color: #999;">
                    Generado en: ' . date('Y-m-d H:i:s') . '
                </div>
            </div>
        ',
        'orden' => 10  // Orden de aparición (menor = primero)
    ];
}
