/**
 * FSFramework Native Dialogs
 * Bootbox-compatible shim built on native <dialog> elements.
 * Replaces view/js/bootbox.min.js (jQuery + Bootstrap JS based dialogs) with a
 * dependency-free implementation that reuses the Bootstrap 3 modal classes
 * already shipped by the theme for visual parity.
 *
 * Security:
 * - CSP-safe: no script evaluation, no inline event handlers (addEventListener only)
 * - title, message and button labels are rendered as HTML, matching the trust
 *   model of the bootbox dialogs this shim replaces (callers pass trusted
 *   markup, same as with bootbox). No sanitization is applied.
 *
 * @author Javier Trujillo <mistertekcom@gmail.com>
 * @license LGPL-3.0
 */

(function(global) {
    'use strict';

    var LOCALES = {
        en: { OK: 'OK', CANCEL: 'Cancel', CONFIRM: 'OK' }
    };
    var currentLocale = 'en';
    var globalDefaults = {};
    // Entries: { element: HTMLDialogElement, dismiss: Function }
    var openDialogs = [];
    var stylesInjected = false;
    var dialogSequence = 0;
    var nativeDialogSupported = typeof global.HTMLDialogElement !== 'undefined';

    var PROMPT_INPUT_TYPES = ['text', 'password', 'email', 'number'];

    var SHIM_STYLES =
        'dialog.modal-dialog { position: fixed; margin: auto; border: 0; padding: 0; ' +
        'background: transparent; overflow: auto; max-width: calc(100% - 16px); } ' +
        'dialog.modal-dialog:focus { outline: none; } ' +
        'dialog.modal-dialog::backdrop { background: rgba(0, 0, 0, 0.5); }';

    function injectStyles() {
        if (stylesInjected) {
            return;
        }
        var style = document.createElement('style');
        style.id = 'fs-dialogs-styles';
        style.textContent = SHIM_STYLES;
        (document.head || document.documentElement).appendChild(style);
        stylesInjected = true;
    }

    function locale() {
        return LOCALES[currentLocale] || LOCALES.en;
    }

    /**
     * Normalize the (options-object | message-string, callback) argument forms
     * on top of the global defaults set via setDefaults().
     */
    function mergeArgs(arg1, arg2) {
        var callOptions = {};
        if (arg1 && typeof arg1 === 'object') {
            for (var key in arg1) {
                if (Object.prototype.hasOwnProperty.call(arg1, key)) {
                    callOptions[key] = arg1[key];
                }
            }
        } else {
            callOptions.message = arg1;
        }
        if (typeof arg2 === 'function') {
            callOptions.callback = arg2;
        }
        var options = {};
        for (var defaultKey in globalDefaults) {
            if (Object.prototype.hasOwnProperty.call(globalDefaults, defaultKey)) {
                options[defaultKey] = globalDefaults[defaultKey];
            }
        }
        for (var callKey in callOptions) {
            options[callKey] = callOptions[callKey];
        }
        return options;
    }

    function buttonLabel(kind, fallback, options) {
        var config = (options.buttons && options.buttons[kind]) || {};
        return config.label || fallback;
    }

    function buttonClassName(kind, fallback, options) {
        var config = (options.buttons && options.buttons[kind]) || {};
        return ('btn ' + (config.className || fallback)).trim();
    }

    function sizeClass(size) {
        switch (size) {
            case 'small':
            case 'sm':
                return ' modal-sm';
            case 'large':
            case 'lg':
                return ' modal-lg';
            default:
                return '';
        }
    }

    function createDialog(dialogType, options) {
        return new Promise(function(resolve) {
            injectStyles();

            var settled = false;
            var input = null;

            var dialog = document.createElement('dialog');
            dialog.className = 'modal-dialog' + sizeClass(options.size);
            dialog.setAttribute('tabindex', '-1');

            function release() {
                for (var i = 0; i < openDialogs.length; i++) {
                    if (openDialogs[i].element === dialog) {
                        openDialogs.splice(i, 1);
                        return;
                    }
                }
            }

            /**
             * Single exit point: closes + removes the dialog, resolves the
             * Promise, then invokes the bootbox callback. The Promise resolves
             * before the callback so a throwing callback cannot block it.
             */
            function settle(result) {
                if (settled) {
                    return;
                }
                settled = true;
                release();
                if (dialog.open) {
                    dialog.close();
                }
                if (dialog.parentNode) {
                    dialog.parentNode.removeChild(dialog);
                }
                resolve(result);
                if (typeof options.callback === 'function') {
                    if (dialogType === 'alert') {
                        options.callback();
                    } else {
                        options.callback(result);
                    }
                }
            }

            function cancel() {
                settle(dialogType === 'confirm' ? false : dialogType === 'prompt' ? null : undefined);
            }

            function accept() {
                settle(dialogType === 'confirm' ? true : dialogType === 'prompt' ? input.value : undefined);
            }

            // ESC: take over the default close and route it through the shared
            // cancel semantics so callback + Promise stay consistent. A truthy
            // onEscape keeps the bootbox contract: returning false keeps the
            // dialog open.
            dialog.addEventListener('cancel', function(event) {
                event.preventDefault();
                if (settled) {
                    return;
                }
                if (typeof options.onEscape === 'function' && options.onEscape() === false) {
                    return;
                }
                cancel();
            });

            // Safety net for closes not routed through the handlers above.
            dialog.addEventListener('close', function() {
                cancel();
            });

            // Clicks on the ::backdrop target the dialog element itself; the
            // modal-content box fills the dialog, so only backdrop clicks can
            // hit the dialog element directly.
            dialog.addEventListener('click', function(event) {
                if (options.backdrop === false || options.backdrop === 'static') {
                    return;
                }
                if (event.target === dialog) {
                    cancel();
                }
            });

            var content = document.createElement('div');
            content.className = 'modal-content';

            if (options.title) {
                var header = document.createElement('div');
                header.className = 'modal-header';

                var closeButton = document.createElement('button');
                closeButton.type = 'button';
                closeButton.className = 'close';
                closeButton.setAttribute('aria-label', 'Close');
                var closeSymbol = document.createElement('span');
                closeSymbol.setAttribute('aria-hidden', 'true');
                closeSymbol.textContent = '\u00D7';
                closeButton.appendChild(closeSymbol);
                closeButton.addEventListener('click', cancel);
                header.appendChild(closeButton);

                var title = document.createElement('h5');
                title.className = 'modal-title';
                title.id = 'fs-dialog-title-' + (++dialogSequence);
                title.innerHTML = options.title;
                header.appendChild(title);

                content.appendChild(header);
                dialog.setAttribute('aria-labelledby', title.id);
            }

            var body = document.createElement('div');
            body.className = 'modal-body';

            if (dialogType === 'prompt') {
                if (options.message) {
                    var promptMessage = document.createElement('div');
                    promptMessage.innerHTML = options.message;
                    body.appendChild(promptMessage);
                }
                input = document.createElement('input');
                input.className = 'form-control';
                input.type = PROMPT_INPUT_TYPES.indexOf(options.inputType) !== -1 ? options.inputType : 'text';
                input.value = options.value === null || options.value === undefined ? '' : String(options.value);
                if (options.placeholder) {
                    input.placeholder = options.placeholder;
                }
                // Enter submits the prompt with the current input value.
                input.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        accept();
                    }
                });
                body.appendChild(input);
            } else if (options.message) {
                var message = document.createElement('div');
                message.className = 'bootbox-body';
                message.innerHTML = options.message;
                body.appendChild(message);
            }

            content.appendChild(body);

            var footer = document.createElement('div');
            footer.className = 'modal-footer';
            var labels = locale();

            if (dialogType !== 'alert') {
                var cancelButton = document.createElement('button');
                cancelButton.type = 'button';
                cancelButton.className = buttonClassName('cancel', 'btn-default', options);
                cancelButton.innerHTML = buttonLabel('cancel', labels.CANCEL, options);
                cancelButton.addEventListener('click', cancel);
                footer.appendChild(cancelButton);
            }

            var confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = buttonClassName('confirm', 'btn-primary', options);
            confirmButton.innerHTML = buttonLabel('confirm', dialogType === 'alert' ? labels.OK : labels.CONFIRM, options);
            confirmButton.addEventListener('click', accept);
            footer.appendChild(confirmButton);

            content.appendChild(footer);
            dialog.appendChild(content);

            document.body.appendChild(dialog);
            openDialogs.push({ element: dialog, dismiss: cancel });

            dialog.showModal();
            if (input) {
                input.focus();
            } else {
                dialog.focus();
            }
        });
    }

    /**
     * Graceful degradation for browsers without native <dialog>: native window
     * dialogs keep the callback + Promise contract working, without HTML
     * rendering or custom buttons.
     */
    function legacyFallback(dialogType, options) {
        return new Promise(function(resolve) {
            var text = '';
            if (options.message || options.title) {
                var scratch = document.createElement('div');
                scratch.innerHTML = options.message || options.title || '';
                text = scratch.textContent || '';
            }

            var result;
            if (dialogType === 'alert') {
                global.alert(text);
                resolve(undefined);
                if (typeof options.callback === 'function') {
                    options.callback();
                }
                return;
            }
            if (dialogType === 'confirm') {
                result = global.confirm(text);
            } else {
                result = global.prompt(text, options.value === null || options.value === undefined ? '' : String(options.value));
                result = result === null ? null : String(result);
            }
            resolve(result);
            if (typeof options.callback === 'function') {
                options.callback(result);
            }
        });
    }

    function showDialog(dialogType, arg1, arg2) {
        var options = mergeArgs(arg1, arg2);
        if (!nativeDialogSupported) {
            return legacyFallback(dialogType, options);
        }
        return createDialog(dialogType, options);
    }

    global.bootbox = {
        VERSION: '5.5.3-fs-native-shim',

        alert: function(arg1, arg2) {
            return showDialog('alert', arg1, arg2);
        },

        confirm: function(arg1, arg2) {
            return showDialog('confirm', arg1, arg2);
        },

        prompt: function(arg1, arg2) {
            return showDialog('prompt', arg1, arg2);
        },

        setLocale: function(name) {
            if (LOCALES[name]) {
                currentLocale = name;
            }
            return this;
        },

        addLocale: function(name, values) {
            if (name && values && typeof values === 'object') {
                LOCALES[name] = values;
            }
            return this;
        },

        removeLocale: function(name) {
            if (name !== 'en') {
                delete LOCALES[name];
            }
            return this;
        },

        setDefaults: function(options) {
            if (options && typeof options === 'object') {
                for (var key in options) {
                    if (Object.prototype.hasOwnProperty.call(options, key)) {
                        globalDefaults[key] = options[key];
                    }
                }
            }
            return this;
        },

        hideAll: function() {
            var open = openDialogs.slice();
            for (var i = 0; i < open.length; i++) {
                open[i].dismiss();
            }
            return this;
        }
    };
})(window);
