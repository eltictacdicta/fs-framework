/**
 * FSFramework - Base JavaScript
 * Core functions used across the application
 */

/**
 * Number formatting function
 * @param {number} number - The number to format
 * @param {number} decimals - Number of decimal places
 * @param {string} dec_point - Decimal separator
 * @param {string} thousands_sep - Thousands separator
 * @returns {string} Formatted number
 */
function number_format(number, decimals, dec_point, thousands_sep) {
    decimals = decimals || 0;
    dec_point = dec_point || '.';
    thousands_sep = thousands_sep || ',';
    
    number = parseFloat(number);
    if (isNaN(number)) {
        return '0';
    }
    
    var sign = number < 0 ? '-' : '';
    number = Math.abs(number);
    
    var intPart = Math.floor(number).toString();
    var decPart = decimals > 0 ? dec_point + (number - Math.floor(number)).toFixed(decimals).slice(2) : '';
    
    // Add thousands separator
    var regex = /(\d+)(\d{3})/;
    while (regex.test(intPart)) {
        intPart = intPart.replace(regex, '$1' + thousands_sep + '$2');
    }
    
    return sign + intPart + decPart;
}

/**
 * Parse a formatted number back to float
 * @param {string} str - Formatted number string
 * @param {string} dec_point - Decimal separator
 * @param {string} thousands_sep - Thousands separator
 * @returns {number} Parsed number
 */
function parse_number(str, dec_point, thousands_sep) {
    dec_point = dec_point || '.';
    thousands_sep = thousands_sep || ',';
    
    if (typeof str === 'number') {
        return str;
    }
    
    str = str.toString().split(thousands_sep).join('');
    if (dec_point !== '.') {
        str = str.replace(dec_point, '.');
    }
    
    return parseFloat(str) || 0;
}

/**
 * Document ready initialization
 */
$(document).ready(function() {
    // Auto-focus first input in modals
    $('.modal').on('shown.bs.modal', function() {
        $(this).find('input:visible:first').focus();
    });

    // Clickable table rows (FacturaScripts / tpvmod list pattern)
    $(document).on('click', 'tr.clickableRow[href]', function(e) {
        if ($(e.target).closest('.cancel_clickable, a, button, input, select, textarea, label').length) {
            return;
        }
        var href = $(this).attr('href');
        if (fsIsSafeClickableHref(href)) {
            window.location.href = href;
        }
    });
});

/**
 * Allows only same-origin http(s) navigation; blocks javascript:/data:/vbscript: and external URLs.
 *
 * @param {string} href
 * @returns {boolean}
 */
function fsIsSafeClickableHref(href) {
    if (!href || href === '#') {
        return false;
    }

    var trimmed = $.trim(href);
    if (/^(javascript|data|vbscript):/i.test(trimmed)) {
        return false;
    }

    try {
        var url = new URL(trimmed, window.location.href);
        if (url.origin !== window.location.origin) {
            return false;
        }

        var scheme = url.protocol.replace(':', '').toLowerCase();
        return scheme === 'http' || scheme === 'https';
    } catch (e) {
        return false;
    }
}
