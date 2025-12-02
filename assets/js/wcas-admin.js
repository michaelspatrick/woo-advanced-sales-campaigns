jQuery(document).ready(function($){
    function toggleDateMode() {
        var mode = $('#wcas_date_mode').val() || 'fixed';
        if (mode === 'holiday') {
            $('.wcas-date-holiday').show();
            $('.wcas-date-fixed').hide();
        } else {
            $('.wcas-date-fixed').show();
            $('.wcas-date-holiday').hide();
        }
    }
    toggleDateMode();
    $('#wcas_date_mode').on('change', toggleDateMode);

    function initProductSearch() {
        if (!$.fn.select2) {
            return;
        }
        $('.wcas-product-search').each(function(){
            var $el = $(this);
            if ($el.data('select2')) {
                return;
            }
            $el.select2({
                ajax: {
                    url: wcasAdminData.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'wcas_search_products',
                            q: params.term || '',
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results || [],
                            pagination: {
                                more: !!data.more
                            }
                        };
                    }
                },
                minimumInputLength: 2,
                width: 'resolve'
            });
        });
    }

    function initTermSearch() {
        if (!$.fn.select2) {
            return;
        }
        $('.wcas-term-search').each(function(){
            var $el = $(this);
            if ($el.data('select2')) {
                return;
            }
            var taxonomy = $el.data('taxonomy') || 'product_cat';
            $el.select2({
                ajax: {
                    url: wcasAdminData.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'wcas_search_terms',
                            taxonomy: taxonomy,
                            q: params.term || '',
                            page: params.page || 1
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.results || [],
                            pagination: {
                                more: !!data.more
                            }
                        };
                    }
                },
                minimumInputLength: 1,
                width: 'resolve'
            });
        });
    }

    initProductSearch();
    initTermSearch();
});
