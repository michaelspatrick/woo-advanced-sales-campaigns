jQuery(document).ready(function($){
    function initCountdown($wrapper) {
        var expiry = parseInt($wrapper.data('expiry'), 10);
        var now    = parseInt($wrapper.data('now'), 10);

        if (!expiry || !now) {
            return;
        }

        function update() {
            var current   = Math.floor(Date.now() / 1000);
            var remaining = expiry - current;

            if (remaining <= 0) {
                remaining = 0;
                clearInterval(timer);
            }

            var days  = Math.floor(remaining / 86400);
            var hours = Math.floor((remaining % 86400) / 3600);
            var mins  = Math.floor((remaining % 3600) / 60);
            var secs  = remaining % 60;

            $wrapper.find('.wcas-num[data-unit="days"]').text(String(days).padStart(2, '0'));
            $wrapper.find('.wcas-num[data-unit="hours"]').text(String(hours).padStart(2, '0'));
            $wrapper.find('.wcas-num[data-unit="mins"]').text(String(mins).padStart(2, '0'));
            $wrapper.find('.wcas-num[data-unit="secs"]').text(String(secs).padStart(2, '0'));
        }

        update();
        var timer = setInterval(update, 1000);
    }

    $('.wcas-countdown-wrapper').each(function(){
        initCountdown($(this));
    });
});
