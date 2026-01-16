/**
 * FSFramework Chunked Uploader Helper
 * 
 * Helper reutilizable para subida de archivos grandes usando Resumable.js
 * Soporta drag & drop, progreso visual y reintentos automáticos.
 * 
 * @requires Resumable.js
 * @requires jQuery
 * 
 * Uso:
 * var uploader = new FSChunkedUploader({
 *     dropZone: '#drop-zone',
 *     browseButton: '#browse-btn',
 *     targetUrl: 'index.php?page=mypage&action=upload_chunk',
 *     fileTypes: ['xlsx', 'xls'],
 *     maxFileSize: 500, // MB
 *     chunkSize: 5, // MB
 *     onProgress: function(percent, file) { },
 *     onSuccess: function(response, file) { },
 *     onError: function(message, file) { },
 *     extraParams: { version: '1.0.0' }
 * });
 */
(function(window, $) {
    'use strict';
    
    /**
     * Constructor del uploader
     * @param {Object} options Opciones de configuración
     */
    function FSChunkedUploader(options) {
        this.options = $.extend({}, FSChunkedUploader.defaults, options);
        this.resumable = null;
        this.currentFile = null;
        this.init();
    }
    
    /**
     * Opciones por defecto
     */
    FSChunkedUploader.defaults = {
        // Selectores de elementos
        dropZone: null,
        browseButton: null,
        progressContainer: null,
        progressBar: null,
        progressText: null,
        statusText: null,
        
        // Configuración de subida
        targetUrl: window.location.href,
        fileTypes: [],
        maxFileSize: 500, // MB
        chunkSize: 5, // MB
        simultaneousUploads: 3,
        testChunks: false,
        
        // Parámetros extra para enviar con cada chunk
        extraParams: {},
        
        // Función para obtener parámetros dinámicos
        getExtraParams: null,
        
        // Validación personalizada
        validateFile: null,
        
        // Callbacks
        onFileAdded: null,
        onProgress: null,
        onSuccess: null,
        onError: null,
        onCancel: null,
        onComplete: null,
        
        // Auto-reload después de éxito
        autoReload: false,
        autoReloadDelay: 2000,
        
        // Textos
        texts: {
            preparing: 'Preparando subida...',
            uploading: 'Subiendo: {filename} ({percent}%)',
            success: '¡Archivo subido correctamente!',
            error: 'Error: {message}',
            invalidType: 'Tipo de archivo no permitido. Tipos válidos: {types}',
            fileTooLarge: 'Archivo demasiado grande. Máximo: {max}MB',
            browserNotSupported: 'Tu navegador no soporta subidas de archivos grandes.'
        }
    };
    
    /**
     * Inicializar el uploader
     */
    FSChunkedUploader.prototype.init = function() {
        var self = this;
        
        // Verificar que Resumable esté disponible
        if (typeof Resumable === 'undefined') {
            console.error('FSChunkedUploader: Resumable.js no está cargado');
            this.showError('Resumable.js no está disponible');
            return;
        }
        
        // Crear instancia de Resumable
        this.resumable = new Resumable({
            target: this.options.targetUrl,
            chunkSize: this.options.chunkSize * 1024 * 1024,
            simultaneousUploads: this.options.simultaneousUploads,
            testChunks: this.options.testChunks,
            throttleProgressCallbacks: 0.5,
            query: function() {
                var params = $.extend({}, self.options.extraParams);
                if (typeof self.options.getExtraParams === 'function') {
                    params = $.extend(params, self.options.getExtraParams());
                }
                return params;
            },
            fileType: this.options.fileTypes,
            maxFiles: 1,
            maxFileSize: this.options.maxFileSize * 1024 * 1024,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        // Verificar soporte del navegador
        if (!this.resumable.support) {
            alert(this.options.texts.browserNotSupported);
            return;
        }
        
        // Configurar elementos del DOM
        this.setupDropZone();
        this.setupBrowseButton();
        this.setupEvents();
    };
    
    /**
     * Configurar zona de drop
     */
    FSChunkedUploader.prototype.setupDropZone = function() {
        var self = this;
        var dropZone = $(this.options.dropZone);
        
        if (!dropZone.length) return;
        
        this.resumable.assignDrop(dropZone[0]);
        
        // Efectos visuales
        dropZone.on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        });
        
        dropZone.on('dragleave drop', function(e) {
            $(this).removeClass('dragover');
        });
        
        // Click para abrir selector
        dropZone.on('click', function(e) {
            var browseBtn = $(self.options.browseButton);
            if (!browseBtn.prop('disabled') && e.target !== browseBtn[0]) {
                browseBtn.click();
            }
        });
    };
    
    /**
     * Configurar botón de navegación
     */
    FSChunkedUploader.prototype.setupBrowseButton = function() {
        var browseBtn = $(this.options.browseButton);
        if (!browseBtn.length) return;
        
        this.resumable.assignBrowse(browseBtn[0]);
    };
    
    /**
     * Configurar eventos de Resumable
     */
    FSChunkedUploader.prototype.setupEvents = function() {
        var self = this;
        
        // Archivo añadido
        this.resumable.on('fileAdded', function(file) {
            self.currentFile = file;
            
            // Validar tipo de archivo
            if (self.options.fileTypes.length > 0) {
                var ext = file.fileName.split('.').pop().toLowerCase();
                if (self.options.fileTypes.indexOf(ext) === -1) {
                    var msg = self.options.texts.invalidType.replace('{types}', self.options.fileTypes.join(', '));
                    alert(msg);
                    return false;
                }
            }
            
            // Validación personalizada
            if (typeof self.options.validateFile === 'function') {
                var validation = self.options.validateFile(file);
                if (validation !== true) {
                    alert(validation);
                    return false;
                }
            }
            
            // Callback
            if (typeof self.options.onFileAdded === 'function') {
                var result = self.options.onFileAdded(file);
                if (result === false) return false;
            }
            
            // Mostrar progreso
            self.showProgress();
            self.updateStatus(self.options.texts.preparing);
            
            // Iniciar subida
            self.resumable.upload();
        });
        
        // Progreso
        this.resumable.on('fileProgress', function(file) {
            var percent = Math.floor(file.progress() * 100);
            self.updateProgress(percent);
            
            if (percent < 100) {
                var msg = self.options.texts.uploading
                    .replace('{filename}', file.fileName)
                    .replace('{percent}', percent);
                self.updateStatus(msg);
            }
            
            if (typeof self.options.onProgress === 'function') {
                self.options.onProgress(percent, file);
            }
        });
        
        // Éxito
        this.resumable.on('fileSuccess', function(file, message) {
            self.updateProgress(100);
            self.showSuccess(self.options.texts.success);
            
            var response;
            try {
                response = JSON.parse(message);
            } catch (e) {
                response = { success: true, message: message };
            }
            
            if (typeof self.options.onSuccess === 'function') {
                self.options.onSuccess(response, file);
            }
            
            if (self.options.autoReload) {
                setTimeout(function() {
                    window.location.reload();
                }, self.options.autoReloadDelay);
            }
        });
        
        // Error
        this.resumable.on('fileError', function(file, message) {
            var errorMsg = self.options.texts.error.replace('{message}', message);
            self.showError(errorMsg);
            
            if (typeof self.options.onError === 'function') {
                self.options.onError(message, file);
            }
            
            // Opción de reintentar
            setTimeout(function() {
                if (confirm('¿Deseas reintentar la subida?')) {
                    self.resumable.upload();
                } else {
                    self.hideProgress();
                    self.resumable.files = [];
                }
            }, 2000);
        });
        
        // Cancelado
        this.resumable.on('cancel', function() {
            self.hideProgress();
            
            if (typeof self.options.onCancel === 'function') {
                self.options.onCancel();
            }
        });
        
        // Completado (todos los archivos)
        this.resumable.on('complete', function() {
            if (typeof self.options.onComplete === 'function') {
                self.options.onComplete();
            }
        });
    };
    
    /**
     * Mostrar contenedor de progreso
     */
    FSChunkedUploader.prototype.showProgress = function() {
        var dropZone = $(this.options.dropZone);
        var progressContainer = $(this.options.progressContainer);
        
        if (dropZone.length) dropZone.hide();
        if (progressContainer.length) progressContainer.show();
        
        this.updateProgress(0);
    };
    
    /**
     * Ocultar contenedor de progreso
     */
    FSChunkedUploader.prototype.hideProgress = function() {
        var dropZone = $(this.options.dropZone);
        var progressContainer = $(this.options.progressContainer);
        
        if (progressContainer.length) progressContainer.hide();
        if (dropZone.length) dropZone.show();
    };
    
    /**
     * Actualizar barra de progreso
     */
    FSChunkedUploader.prototype.updateProgress = function(percent) {
        var progressBar = $(this.options.progressBar);
        var progressText = $(this.options.progressText);
        
        if (progressBar.length) {
            progressBar.css('width', percent + '%');
            progressBar.removeClass('progress-bar-success progress-bar-danger');
            if (percent < 100) {
                progressBar.addClass('active');
            } else {
                progressBar.removeClass('active');
            }
        }
        
        if (progressText.length) {
            progressText.text(percent + '%');
        }
    };
    
    /**
     * Actualizar texto de estado
     */
    FSChunkedUploader.prototype.updateStatus = function(message) {
        var statusText = $(this.options.statusText);
        if (statusText.length) {
            statusText.html(message);
        }
    };
    
    /**
     * Mostrar éxito
     */
    FSChunkedUploader.prototype.showSuccess = function(message) {
        var progressBar = $(this.options.progressBar);
        var statusText = $(this.options.statusText);
        
        if (progressBar.length) {
            progressBar.removeClass('active').addClass('progress-bar-success');
        }
        
        if (statusText.length) {
            statusText.html('<span class="text-success"><i class="fa fa-check-circle"></i> ' + message + '</span>');
        }
    };
    
    /**
     * Mostrar error
     */
    FSChunkedUploader.prototype.showError = function(message) {
        var progressBar = $(this.options.progressBar);
        var statusText = $(this.options.statusText);
        
        if (progressBar.length) {
            progressBar.removeClass('active').addClass('progress-bar-danger');
        }
        
        if (statusText.length) {
            statusText.html('<span class="text-danger"><i class="fa fa-exclamation-triangle"></i> ' + message + '</span>');
        }
    };
    
    /**
     * Habilitar el uploader
     */
    FSChunkedUploader.prototype.enable = function() {
        var dropZone = $(this.options.dropZone);
        var browseBtn = $(this.options.browseButton);
        
        if (dropZone.length) dropZone.removeClass('disabled');
        if (browseBtn.length) browseBtn.prop('disabled', false);
    };
    
    /**
     * Deshabilitar el uploader
     */
    FSChunkedUploader.prototype.disable = function() {
        var dropZone = $(this.options.dropZone);
        var browseBtn = $(this.options.browseButton);
        
        if (dropZone.length) dropZone.addClass('disabled');
        if (browseBtn.length) browseBtn.prop('disabled', true);
    };
    
    /**
     * Cancelar subida actual
     */
    FSChunkedUploader.prototype.cancel = function() {
        if (this.resumable) {
            this.resumable.cancel();
        }
    };
    
    /**
     * Reintentar subida
     */
    FSChunkedUploader.prototype.retry = function() {
        if (this.resumable) {
            this.resumable.upload();
        }
    };
    
    // Exportar al scope global
    window.FSChunkedUploader = FSChunkedUploader;
    
})(window, jQuery);
