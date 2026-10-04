(function($) {
    'use strict';

    $(document).ready(function() {
        // 複製短代碼功能
        $('.risecreatives-copy-shortcode').on('click', function() {
            var $button = $(this);
            var shortcode = $button.data('shortcode');
            
            // 創建臨時 input 元素
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(shortcode).select();
            
            // 執行複製
            document.execCommand('copy');
            $temp.remove();
            
            // 顯示成功訊息
            var originalText = $button.text();
            $button.text('已複製！');
            setTimeout(function() {
                $button.text(originalText);
            }, 2000);
        });

        // 切換頁面選擇器
        $('.risecreatives-toggle-pages').on('change', function() {
            var targetId = $(this).data('target');
            $('#' + targetId).slideToggle();
        });
    });
})(jQuery);
