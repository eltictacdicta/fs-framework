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
    
    str = str.toString().replace(new RegExp('\\' + thousands_sep, 'g'), '');
    str = str.replace(new RegExp('\\' + dec_point), '.');
    
    return parseFloat(str) || 0;
}

/**
 * Format a price with currency symbol
 * @param {number} precio - Price to format
 * @param {string} coddivisa - Currency code (optional)
 * @returns {string} Formatted price
 */
function show_precio(precio, coddivisa) {
    coddivisa = coddivisa || (typeof empresa_coddivisa !== 'undefined' ? empresa_coddivisa : 'EUR');
    var simbolo = typeof empresa_simbolo !== 'undefined' ? empresa_simbolo : '€';
    
    var formatted = number_format(precio, 2, ',', '.');
    
    if (typeof FS_POS_DIVISA !== 'undefined' && FS_POS_DIVISA === 'left') {
        return simbolo + formatted;
    }
    return formatted + simbolo;
}

/**
 * Show confirmation dialog
 * @param {string} message - Confirmation message
 * @param {function} callback - Callback on confirm
 */
function fs_confirm(message, callback) {
    if (typeof bootbox !== 'undefined') {
        bootbox.confirm({
            message: message,
            buttons: {
                cancel: {
                    label: 'Cancelar',
                    className: 'btn-default'
                },
                confirm: {
                    label: 'Aceptar',
                    className: 'btn-primary'
                }
            },
            callback: function(result) {
                if (result && typeof callback === 'function') {
                    callback();
                }
            }
        });
    } else if (confirm(message)) {
        if (typeof callback === 'function') {
            callback();
        }
    }
}

/**
 * Show alert dialog
 * @param {string} message - Alert message
 * @param {function} callback - Optional callback on close
 */
function fs_alert(message, callback) {
    if (typeof bootbox !== 'undefined') {
        bootbox.alert({
            message: message,
            callback: callback
        });
    } else {
        alert(message);
        if (typeof callback === 'function') {
            callback();
        }
    }
}

/**
 * Show prompt dialog
 * @param {string} message - Prompt message
 * @param {function} callback - Callback with value
 * @param {string} defaultValue - Default value
 */
function fs_prompt(message, callback, defaultValue) {
    if (typeof bootbox !== 'undefined') {
        bootbox.prompt({
            title: message,
            value: defaultValue || '',
            callback: function(result) {
                if (result !== null && typeof callback === 'function') {
                    callback(result);
                }
            }
        });
    } else {
        var result = prompt(message, defaultValue);
        if (result !== null && typeof callback === 'function') {
            callback(result);
        }
    }
}

/**
 * Initialize modal iframe
 */
function init_modal_iframe() {
    $('#modal_iframe').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget);
        var url = button.data('url');
        var title = button.data('title') || 'Modal';
        
        var modal = $(this);
        modal.find('.modal-title').text(title);
        modal.find('iframe').attr('src', url);
    });
    
    $('#modal_iframe').on('hidden.bs.modal', function() {
        $(this).find('iframe').attr('src', '');
    });
}

/**
 * AJAX form submission helper
 * @param {string} formSelector - Form selector
 * @param {object} options - Options (success, error callbacks)
 */
function ajax_form(formSelector, options) {
    options = options || {};
    
    $(formSelector).on('submit', function(e) {
        e.preventDefault();
        
        var $form = $(this);
        var $btn = $form.find('[type="submit"]');
        
        $btn.prop('disabled', true);
        
        $.ajax({
            url: $form.attr('action'),
            method: $form.attr('method') || 'POST',
            data: $form.serialize(),
            success: function(response) {
                if (typeof options.success === 'function') {
                    options.success(response);
                }
            },
            error: function(xhr, status, error) {
                if (typeof options.error === 'function') {
                    options.error(xhr, status, error);
                } else {
                    fs_alert('Error: ' + error);
                }
            },
            complete: function() {
                $btn.prop('disabled', false);
            }
        });
    });
}

/**
 * Document ready initialization
 */
$(document).ready(function() {
    // Initialize modal iframe
    init_modal_iframe();
    
    // Initialize datepickers
    if (typeof $.fn.datepicker !== 'undefined') {
        $('.datepicker, input[type="date"]').each(function() {
            if ($(this).attr('type') === 'date') {
                $(this).attr('type', 'text');
            }
            $(this).datepicker({
                format: 'dd-mm-yyyy',
                autoclose: true,
                todayHighlight: true,
                language: 'es'
            });
        });
    }
    
    // Initialize tooltips
    if (typeof $.fn.tooltip !== 'undefined') {
        $('[data-toggle="tooltip"]').tooltip();
    }
    
    // Initialize popovers
    if (typeof $.fn.popover !== 'undefined') {
        $('[data-toggle="popover"]').popover();
    }
    
    // Auto-focus first input in modals
    $('.modal').on('shown.bs.modal', function() {
        $(this).find('input:visible:first').focus();
    });
    
    // Confirm delete actions
    $('[data-confirm]').on('click', function(e) {
        var message = $(this).data('confirm') || '¿Está seguro?';
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
    });
});
