/**
 * jQuery UI Shake effect fallback
 * Provides a simple shake effect if jQuery UI shake is not available
 */
(function($) {
    if (typeof $.fn.shake === 'undefined') {
        $.fn.shake = function(options) {
            var settings = $.extend({
                distance: 10,
                times: 3,
                duration: 200
            }, options);
            
            return this.each(function() {
                var el = $(this);
                var originalPosition = el.css('position');
                var originalLeft = el.css('left');
                
                if (originalPosition !== 'absolute' && originalPosition !== 'relative') {
                    el.css('position', 'relative');
                }
                
                var animQueue = [];
                for (var i = 0; i < settings.times; i++) {
                    animQueue.push({ left: '-' + settings.distance + 'px' });
                    animQueue.push({ left: settings.distance + 'px' });
                }
                animQueue.push({ left: originalLeft || '0px' });
                
                var animate = function(index) {
                    if (index < animQueue.length) {
                        el.animate(animQueue[index], settings.duration / (settings.times * 2), function() {
                            animate(index + 1);
                        });
                    } else {
                        el.css('position', originalPosition);
                    }
                };
                
                animate(0);
            });
        };
    }
})(jQuery);
