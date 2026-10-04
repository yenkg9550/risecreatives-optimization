// assets/js/scripts-init.js
(function($) {
    'use strict';

    var RiseCreativesScriptsInit = {
        init: function() {
            this.bindEvents();
            this.initializeFrameworks();
        },

        bindEvents: function() {
            $('.framework-settings-form').on('submit', this.handleSettingsSave);
        },

        initializeFrameworks: function() {
            var self = this;
            if (typeof RiseCreativesScripts !== 'undefined') {
                Object.keys(RiseCreativesScripts.settings).forEach(function(framework) {
                    if (RiseCreativesScripts.settings[framework].enabled) {
                        self.initializeFramework(framework, RiseCreativesScripts.settings[framework].config);
                    }
                });
            }
        },

        initializeFramework: function(framework, config) {
            switch(framework) {
                case 'slick':
                    this.initSlick(config);
                    break;
                case 'aos':
                    this.initAOS(config);
                    break;
                case 'swiper':
                    this.initSwiper(config);
                    break;
                case 'gsap':
                    this.initGSAP(config);
                    break;
            }
        },

        initSlick: function(config) {
            $('.slick-slider').each(function() {
                var $slider = $(this);
                var slickConfig = $.extend({}, config, $slider.data('slick-options'));
                $slider.slick(slickConfig);
            });
        },

        initAOS: function(config) {
            if (typeof AOS !== 'undefined') {
                AOS.init(config);
            }
        },

        initSwiper: function(config) {
            document.querySelectorAll('.swiper').forEach(function(element) {
                new Swiper(element, config);
            });
        },

        initGSAP: function(config) {
            // GSAP initialization can be customized based on your needs
            if (typeof gsap !== 'undefined' && config) {
                try {
                    eval(config);
                } catch (e) {
                    console.error('GSAP initialization error:', e);
                }
            }
        },

        handleSettingsSave: function(e) {
            e.preventDefault();
            var $form = $(this);
            var framework = $form.data('framework');
            var settings = $form.serializeArray().reduce(function(obj, item) {
                obj[item.name] = item.value;
                return obj;
            }, {});

            $.ajax({
                url: RiseCreativesScripts.ajaxurl,
                type: 'POST',
                data: {
                    action: 'save_framework_settings',
                    nonce: RiseCreativesScripts.nonce,
                    framework: framework,
                    settings: JSON.stringify(settings)
                },
                success: function(response) {
                    if (response.success) {
                        alert('設定已儲存');
                    } else {
                        alert('儲存失敗：' + response.data);
                    }
                },
                error: function() {
                    alert('發生錯誤，請稍後再試');
                }
            });
        }
    };

    $(document).ready(function() {
        RiseCreativesScriptsInit.init();
    });

})(jQuery);