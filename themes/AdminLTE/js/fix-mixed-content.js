/**
 * Script para corregir problemas de contenido mixto
 */
$(document).ready(function() {
    // Buscar y reemplazar todas las referencias a imágenes HTTP inseguras
    const insecureUrls = [
        'http://i.imgur.com/XtwPPAJ.png',
        'http://files.softicons.com/download/toolbar-icons/flatastic-icons-part-2-by-custom-icon-design/png/512x512/data-add.png'
    ];
    
    // Reemplazar en atributos de estilo
    $('[style*="http://"]').each(function() {
        let style = $(this).attr('style');
        if (style) {
            insecureUrls.forEach(url => {
                // Reemplazar con una versión HTTPS o remover
                const secureUrl = url.replace('http://', 'https://');
                style = style.replace(url, secureUrl);
            });
            $(this).attr('style', style);
        }
    });
    
    // Reemplazar en atributos de imagen
    $('img[src^="http://"]').each(function() {
        let src = $(this).attr('src');
        if (src) {
            insecureUrls.forEach(url => {
                if (src === url) {
                    // Reemplazar con una versión HTTPS
                    const secureUrl = url.replace('http://', 'https://');
                    $(this).attr('src', secureUrl);
                }
            });
        }
    });
    
    // Reemplazar en elementos data
    $('[data-src^="http://"], [data-background^="http://"]').each(function() {
        const attrs = ['data-src', 'data-background'];
        
        attrs.forEach(attr => {
            let value = $(this).attr(attr);
            if (value && value.startsWith('http://')) {
                $(this).attr(attr, value.replace('http://', 'https://'));
            }
        });
    });
    
    // Corregir elementos con fondo establecido vía CSS
    $('.content-wrapper, .right-side, .main-footer, .box').each(function() {
        const bg = $(this).css('background-image');
        if (bg && bg.indexOf('http://') > -1) {
            const secureUrl = bg.replace('http://', 'https://');
            $(this).css('background-image', secureUrl);
        }
    });
    
    // Interceptar cualquier solicitud AJAX para corregir URLs HTTP
    const originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url, async, user, password) {
        if (url && typeof url === 'string' && url.startsWith('http://')) {
            const secureUrl = url.replace('http://', 'https://');
            arguments[1] = secureUrl;
        }
        
        return originalOpen.apply(this, arguments);
    };
}); 