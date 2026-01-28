/**
 * FSFramework AJAX Content Loader
 * Replaces iframes with native AJAX loading for better accessibility and UX
 * 
 * Security features:
 * - CSRF token validation on all requests
 * - URL validation (only internal URLs allowed)
 * - Content sanitization
 * - Secure headers
 * 
 * @author Javier Trujillo <mistertekcom@gmail.com>
 * @license LGPL-3.0
 */

(function($) {
    'use strict';

    /**
     * FSAjaxLoader - Handles dynamic content loading with security
     */
    window.FSAjaxLoader = {
        
        /**
         * Configuration
         */
        config: {
            // CSRF token from meta tag
            csrfToken: null,
            // CSRF header name
            csrfHeader: 'X-CSRF-TOKEN',
            // CSRF field name for POST
            csrfFieldName: '_csrf_token',
            // Allowed URL patterns (only internal URLs)
            allowedUrlPatterns: [
                /^index\.php/,
                /^\.?\/?index\.php/,
                /^\/[^\/]/,  // Relative paths starting with /
                /^[^:\/]+\.php/  // Local PHP files
            ],
            // Dangerous tags to remove from loaded content
            dangerousTags: ['script', 'object', 'embed', 'applet', 'form'],
            // Dangerous attributes to remove
            dangerousAttrs: ['onclick', 'onerror', 'onload', 'onmouseover', 'onfocus', 'onblur'],
            // Enable strict mode (blocks external URLs completely)
            strictMode: true,
            // Debug mode
            debug: false
        },

        /**
         * Initialize the loader - call this after DOM ready
         */
        init: function() {
            // Get CSRF token from meta tag
            var $csrfMeta = $('meta[name="csrf-token"]');
            if ($csrfMeta.length > 0) {
                this.config.csrfToken = $csrfMeta.attr('content');
            }
            
            // Setup global AJAX settings for CSRF
            this.setupAjaxDefaults();
            
            if (this.config.debug) {
                console.log('FSAjaxLoader initialized', {
                    csrfToken: this.config.csrfToken ? 'present' : 'missing'
                });
            }
        },

        /**
         * Setup default AJAX settings with security headers
         */
        setupAjaxDefaults: function() {
            var self = this;
            
            $.ajaxSetup({
                beforeSend: function(xhr, settings) {
                    // Only add CSRF for same-origin requests
                    if (self.isSameOrigin(settings.url)) {
                        // Add CSRF token header
                        if (self.config.csrfToken) {
                            xhr.setRequestHeader(self.config.csrfHeader, self.config.csrfToken);
                        }
                        
                        // Add security headers
                        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    }
                }
            });
        },

        /**
         * Check if URL is same origin
         * @param {string} url - URL to check
         * @returns {boolean}
         */
        isSameOrigin: function(url) {
            if (!url) return false;
            
            // Relative URLs are same-origin
            if (url.indexOf('://') === -1 && url.indexOf('//') !== 0) {
                return true;
            }
            
            try {
                var parsed = new URL(url, window.location.origin);
                return parsed.origin === window.location.origin;
            } catch (e) {
                return false;
            }
        },

        /**
         * Validate URL for security
         * @param {string} url - URL to validate
         * @returns {boolean}
         */
        isValidUrl: function(url) {
            if (!url || typeof url !== 'string') {
                return false;
            }
            
            // Trim whitespace
            url = url.trim();
            
            // Block javascript: and data: URLs
            if (/^(javascript|data|vbscript):/i.test(url)) {
                console.error('FSAjaxLoader: Blocked dangerous URL protocol:', url);
                return false;
            }
            
            // In strict mode, only allow internal URLs
            if (this.config.strictMode) {
                // Allow relative URLs
                if (this.isSameOrigin(url)) {
                    return true;
                }
                
                // Check against allowed patterns
                for (var i = 0; i < this.config.allowedUrlPatterns.length; i++) {
                    if (this.config.allowedUrlPatterns[i].test(url)) {
                        return true;
                    }
                }
                
                console.error('FSAjaxLoader: URL not allowed in strict mode:', url);
                return false;
            }
            
            return true;
        },

        /**
         * Sanitize HTML content to prevent XSS
         * @param {string} html - HTML content to sanitize
         * @param {Object} options - Sanitization options
         * @returns {string} - Sanitized HTML
         */
        sanitizeHtml: function(html, options) {
            if (!html || typeof html !== 'string') {
                return '';
            }
            
            options = options || {};
            var allowScripts = options.allowScripts === true;
            var allowForms = options.allowForms === true;
            
            try {
                var $temp = $('<div>').html(html);
                
                // Remove dangerous tags unless explicitly allowed
                if (!allowScripts) {
                    $temp.find('script').remove();
                }
                
                // Remove other dangerous tags
                var tagsToRemove = ['object', 'embed', 'applet', 'iframe'];
                if (!allowForms) {
                    tagsToRemove.push('form');
                }
                
                tagsToRemove.forEach(function(tag) {
                    $temp.find(tag).remove();
                });
                
                // Remove dangerous event handlers from all elements
                $temp.find('*').each(function() {
                    var $el = $(this);
                    var attrs = this.attributes;
                    var attrsToRemove = [];
                    
                    for (var i = 0; i < attrs.length; i++) {
                        var attrName = attrs[i].name.toLowerCase();
                        // Remove on* event handlers
                        if (attrName.indexOf('on') === 0) {
                            attrsToRemove.push(attrs[i].name);
                        }
                        // Remove javascript: in href/src
                        if ((attrName === 'href' || attrName === 'src') && 
                            /^javascript:/i.test(attrs[i].value)) {
                            attrsToRemove.push(attrs[i].name);
                        }
                    }
                    
                    attrsToRemove.forEach(function(attr) {
                        $el.removeAttr(attr);
                    });
                });
                
                return $temp.html();
            } catch (e) {
                console.error('FSAjaxLoader: Sanitization error:', e);
                return '';
            }
        },

        /**
         * Load content into a container via AJAX
         * @param {string} url - URL to fetch
         * @param {string|HTMLElement} container - Target container selector or element
         * @param {Object} options - Additional options
         */
        loadContent: function(url, container, options) {
            var self = this;
            
            // Validate URL
            if (!this.isValidUrl(url)) {
                console.error('FSAjaxLoader: Invalid or blocked URL:', url);
                $(container).html(
                    '<div class="alert alert-danger">' +
                    '<i class="fa fa-shield"></i> ' +
                    'Error de seguridad: URL no permitida' +
                    '</div>'
                );
                return;
            }
            
            var defaults = {
                showLoader: true,
                extractBody: true,
                sanitize: true,
                allowScripts: false,
                allowForms: true,
                onSuccess: null,
                onError: null,
                method: 'GET',
                data: null,
                timeout: 30000
            };
            
            var settings = $.extend({}, defaults, options);
            var $container = $(container);
            
            if ($container.length === 0) {
                console.error('FSAjaxLoader: Container not found:', container);
                return;
            }
            
            // Show loading indicator
            if (settings.showLoader) {
                $container.html(
                    '<div class="text-center" style="padding: 40px;">' +
                    '<i class="fa fa-spinner fa-spin fa-3x"></i>' +
                    '<p class="text-muted" style="margin-top: 15px;">Cargando...</p>' +
                    '</div>'
                );
            }
            
            // Add ajax=1 parameter to signal AJAX request
            var separator = url.indexOf('?') === -1 ? '?' : '&';
            var ajaxUrl = url + separator + 'ajax=1';
            
            // Prepare request data with CSRF token for POST
            var requestData = settings.data || {};
            if (settings.method === 'POST' && this.config.csrfToken) {
                if (typeof requestData === 'object') {
                    requestData[this.config.csrfFieldName] = this.config.csrfToken;
                }
            }
            
            $.ajax({
                url: ajaxUrl,
                method: settings.method,
                data: requestData,
                timeout: settings.timeout,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    var content = response;
                    
                    // Extract only the main content if requested
                    if (settings.extractBody) {
                        content = self.extractMainContent(response);
                    }
                    
                    // Sanitize content if enabled
                    if (settings.sanitize) {
                        content = self.sanitizeHtml(content, {
                            allowScripts: settings.allowScripts,
                            allowForms: settings.allowForms
                        });
                    }
                    
                    $container.html(content);
                    
                    // Re-initialize any JS components
                    self.reinitializeComponents($container);
                    
                    if (typeof settings.onSuccess === 'function') {
                        settings.onSuccess(response, $container);
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = 'Error al cargar el contenido';
                    
                    if (xhr.status === 403) {
                        errorMsg = 'Acceso denegado (posible error CSRF)';
                    } else if (xhr.status === 404) {
                        errorMsg = 'Página no encontrada';
                    } else if (xhr.status === 500) {
                        errorMsg = 'Error interno del servidor';
                    } else if (status === 'timeout') {
                        errorMsg = 'Tiempo de espera agotado';
                    }
                    
                    $container.html(
                        '<div class="alert alert-danger">' +
                        '<i class="fa fa-exclamation-triangle"></i> ' +
                        errorMsg +
                        '</div>'
                    );
                    
                    if (typeof settings.onError === 'function') {
                        settings.onError(xhr, status, error);
                    }
                }
            });
        },
        
        /**
         * Extract main content from a full HTML page response
         * @param {string} html - Full HTML response
         * @returns {string} - Extracted content
         */
        extractMainContent: function(html) {
            try {
                var $temp = $('<div>').html(html);
                
                // Try different selectors to find the main content
                var selectors = [
                    '.content-wrapper .content',
                    '.content-wrapper',
                    '.container-fluid',
                    '.container',
                    'main',
                    'article',
                    '.panel-body',
                    'body'
                ];
                
                for (var i = 0; i < selectors.length; i++) {
                    var $content = $temp.find(selectors[i]).first();
                    if ($content.length > 0 && $content.html().trim().length > 0) {
                        return $content.html();
                    }
                }
                
                // If nothing found, return the original
                return html;
            } catch (e) {
                console.error('FSAjaxLoader: Error extracting content:', e);
                return html;
            }
        },
        
        /**
         * Re-initialize JavaScript components after AJAX load
         * @param {jQuery} $container - Container with new content
         */
        reinitializeComponents: function($container) {
            // Re-init Bootstrap tooltips
            if ($.fn.tooltip) {
                $container.find('[data-toggle="tooltip"]').tooltip();
            }
            
            // Re-init Bootstrap popovers
            if ($.fn.popover) {
                $container.find('[data-toggle="popover"]').popover();
            }
            
            // Re-init datepickers
            if ($.fn.datepicker) {
                $container.find('.datepicker, [data-provide="datepicker"]').datepicker({
                    autoclose: true,
                    todayHighlight: true,
                    format: 'dd-mm-yyyy'
                });
            }
            
            // Re-init autocomplete
            if ($.fn.autocomplete) {
                $container.find('.autocomplete').each(function() {
                    var $el = $(this);
                    var serviceUrl = $el.data('autocomplete-url');
                    if (serviceUrl) {
                        $el.autocomplete({
                            serviceUrl: serviceUrl,
                            onSelect: function(suggestion) {
                                if (typeof window.onAutocompleteSelect === 'function') {
                                    window.onAutocompleteSelect(suggestion, $el);
                                }
                            }
                        });
                    }
                });
            }
            
            // Re-init select pickers
            if ($.fn.selectpicker) {
                $container.find('.selectpicker').selectpicker('refresh');
            }
            
            // Trigger custom event for plugins to hook into
            $container.trigger('fs:contentLoaded');
        },
        
        /**
         * Load extension tab content via AJAX instead of iframe
         * @param {string} tabId - Tab panel ID
         * @param {string} url - URL to load
         */
        loadExtensionTab: function(tabId, url) {
            var $tabPane = $('#' + tabId);
            
            if ($tabPane.length === 0) {
                console.error('FSAjaxLoader: Tab pane not found:', tabId);
                return;
            }
            
            // Only load if not already loaded
            if ($tabPane.data('loaded')) {
                return;
            }
            
            this.loadContent(url, $tabPane, {
                extractBody: true,
                sanitize: true,
                allowForms: true,
                onSuccess: function() {
                    $tabPane.data('loaded', true);
                }
            });
        },
        
        /**
         * Execute a background task via AJAX (replacement for hidden iframes)
         * @param {string} url - URL to call
         * @param {Object} options - Additional options
         */
        backgroundTask: function(url, options) {
            var self = this;
            
            // Validate URL
            if (!this.isValidUrl(url)) {
                console.error('FSAjaxLoader: Background task blocked - invalid URL:', url);
                return;
            }
            
            var defaults = {
                silent: true,
                onSuccess: null,
                onError: null,
                timeout: 30000
            };
            
            var settings = $.extend({}, defaults, options);
            
            // Add ajax=1 parameter
            var separator = url.indexOf('?') === -1 ? '?' : '&';
            var ajaxUrl = url + separator + 'ajax=1';
            
            $.ajax({
                url: ajaxUrl,
                method: 'GET',
                timeout: settings.timeout,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (!settings.silent && self.config.debug) {
                        console.log('FSAjaxLoader: Background task completed:', url);
                    }
                    if (typeof settings.onSuccess === 'function') {
                        settings.onSuccess(response);
                    }
                },
                error: function(xhr, status, error) {
                    if (!settings.silent) {
                        console.warn('FSAjaxLoader: Background task failed:', url, error);
                    }
                    if (typeof settings.onError === 'function') {
                        settings.onError(xhr, status, error);
                    }
                }
            });
        },
        
        /**
         * Show content in a modal via AJAX
         * @param {string} url - URL to load
         * @param {string} title - Modal title
         * @param {Object} options - Additional options
         */
        showModal: function(url, title, options) {
            var self = this;
            
            // Validate URL
            if (!this.isValidUrl(url)) {
                console.error('FSAjaxLoader: Modal blocked - invalid URL:', url);
                return;
            }
            
            var defaults = {
                size: 'lg',
                extractBody: true,
                sanitize: true
            };
            
            var settings = $.extend({}, defaults, options);
            
            // Create or get modal
            var $modal = $('#fs-ajax-modal');
            if ($modal.length === 0) {
                $modal = $(
                    '<div class="modal fade" id="fs-ajax-modal" tabindex="-1" role="dialog" aria-labelledby="fs-ajax-modal-title">' +
                    '  <div class="modal-dialog modal-' + settings.size + '" role="document">' +
                    '    <div class="modal-content">' +
                    '      <div class="modal-header">' +
                    '        <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar">' +
                    '          <span aria-hidden="true">&times;</span>' +
                    '        </button>' +
                    '        <h4 class="modal-title" id="fs-ajax-modal-title"></h4>' +
                    '      </div>' +
                    '      <div class="modal-body"></div>' +
                    '      <div class="modal-footer">' +
                    '        <button type="button" class="btn btn-default" data-dismiss="modal">Cerrar</button>' +
                    '      </div>' +
                    '    </div>' +
                    '  </div>' +
                    '</div>'
                ).appendTo('body');
            }
            
            // Update modal size
            $modal.find('.modal-dialog')
                .removeClass('modal-sm modal-lg modal-xl')
                .addClass('modal-' + settings.size);
            
            // Set title (sanitize it)
            var safeTitle = $('<div>').text(title || 'Contenido').html();
            $modal.find('.modal-title').html(safeTitle);
            
            // Load content
            this.loadContent(url, $modal.find('.modal-body'), {
                extractBody: settings.extractBody,
                sanitize: settings.sanitize,
                allowForms: true
            });
            
            // Show modal
            $modal.modal('show');
        },

        /**
         * Make a secure POST request with CSRF token
         * @param {string} url - URL to post to
         * @param {Object} data - Data to send
         * @param {Object} options - Additional options
         */
        securePost: function(url, data, options) {
            var self = this;
            
            if (!this.isValidUrl(url)) {
                console.error('FSAjaxLoader: POST blocked - invalid URL:', url);
                if (options && typeof options.onError === 'function') {
                    options.onError(null, 'security', 'URL not allowed');
                }
                return;
            }
            
            var defaults = {
                onSuccess: null,
                onError: null,
                timeout: 30000,
                dataType: 'json'
            };
            
            var settings = $.extend({}, defaults, options);
            
            // Ensure CSRF token is included
            data = data || {};
            if (this.config.csrfToken) {
                data[this.config.csrfFieldName] = this.config.csrfToken;
            }
            
            $.ajax({
                url: url,
                method: 'POST',
                data: data,
                timeout: settings.timeout,
                dataType: settings.dataType,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                success: function(response) {
                    if (typeof settings.onSuccess === 'function') {
                        settings.onSuccess(response);
                    }
                },
                error: function(xhr, status, error) {
                    if (typeof settings.onError === 'function') {
                        settings.onError(xhr, status, error);
                    }
                }
            });
        }
    };

    /**
     * Initialize on DOM ready
     */
    $(document).ready(function() {
        // Initialize the loader
        FSAjaxLoader.init();
        
        // Convert tab links with data-ajax-url to use AJAX loading
        $(document).on('shown.bs.tab', '[data-toggle="tab"][data-ajax-url]', function(e) {
            var $tab = $(e.target);
            var url = $tab.data('ajax-url');
            var target = $tab.attr('href') || $tab.data('target');
            
            if (url && target) {
                FSAjaxLoader.loadExtensionTab(target.replace('#', ''), url);
            }
        });
        
        // Handle modal links that should load via AJAX
        $(document).on('click', '[data-ajax-modal]', function(e) {
            e.preventDefault();
            var $link = $(this);
            var url = $link.data('ajax-modal') || $link.attr('href');
            var title = $link.data('modal-title') || $link.text();
            var size = $link.data('modal-size') || 'lg';
            
            FSAjaxLoader.showModal(url, title, { size: size });
        });
    });

})(jQuery);
