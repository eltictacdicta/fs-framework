#!/bin/bash
# Script de configuración rápida del portal

echo "🚀 Configurando Portal Público..."

# 1. Crear directorio tmp si no existe
mkdir -p tmp

# 2. Crear configuración inicial del portal
cat > tmp/portal_config.json << 'EOF'
{
    "contenido_antes": "<div style=\"text-align: center; padding: 2rem;\"><h2>🎉 Bienvenido a nuestro portal</h2><p>Este contenido es editable desde el panel de administración.</p></div>",
    "contenido_despues": "<div style=\"text-align: center; padding: 1rem; color: #666;\"><p>Para más información, contáctanos.</p></div>"
}
EOF

echo "✅ Configuración del portal creada en tmp/portal_config.json"

# 3. Configurar homepage (opcional)
if [ -f "tmp/config2.ini" ]; then
    if grep -q "^homepage" tmp/config2.ini; then
        sed -i "s/^homepage.*/homepage = 'portal';/" tmp/config2.ini
        echo "✅ Homepage actualizada a 'portal' en config2.ini"
    else
        echo "homepage = 'portal';" >> tmp/config2.ini
        echo "✅ Homepage configurada como 'portal' en config2.ini"
    fi
fi

echo ""
echo "🎊 ¡Configuración completada!"
echo ""
echo "Próximos pasos:"
echo "1. Activa el plugin 'hola_mundo' desde el panel de administración"
echo "2. Visita index.php (logout primero para ver la vista pública)"
echo "3. Personaliza el contenido desde Portal > Portada en el admin"
echo ""
echo "📖 Documentación completa en: CAMBIOS_PORTAL.md"
