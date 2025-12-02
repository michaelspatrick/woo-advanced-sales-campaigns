<?php
/**
 * Plugin Name: Woo Advanced Sales Campaigns
 * Description: Create advanced, schedulable sales campaigns with countdown timers, targeting, and store notices.
 * Author: Michael Patrick
 * Version: 0.2.0
 * Text Domain: woo-advanced-sales-campaigns
 * Requires Woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WCAS_Plugin' ) ) {

class WCAS_Plugin {

    const POST_TYPE             = 'wcas_campaign';
    const OPTION_CUSTOM_HOLIDAYS = 'wcas_custom_holidays';

    protected static $instance = null;

    // Cache per request
    protected $active_campaign_ids      = null;
    protected $store_notice_campaign_id = null;
    protected $discount_cache           = [];
    protected $campaign_meta_cache      = [];
    protected $campaign_window_cache    = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_campaign' ], 10, 2 );

        add_action( 'admin_enqueue_scripts', [ $this, 'admin_assets' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'frontend_assets' ] );

        // AJAX search
        add_action( 'wp_ajax_wcas_search_products', [ $this, 'ajax_search_products' ] );
        add_action( 'wp_ajax_wcas_search_terms', [ $this, 'ajax_search_terms' ] );

        // Price filters
        add_filter( 'woocommerce_product_get_price', [ $this, 'filter_product_price' ], 20, 2 );
        add_filter( 'woocommerce_product_is_on_sale', [ $this, 'filter_product_is_on_sale' ], 20, 2 );
        add_filter( 'woocommerce_get_price_html', [ $this, 'filter_price_html_savings' ], 20, 2 );

        // Countdown on product page
        add_action( 'woocommerce_single_product_summary', [ $this, 'render_countdown_timer' ], 25 );

        // Store notice integration
        add_filter( 'woocommerce_demo_store_notice', [ $this, 'maybe_store_notice_message' ] );

        // Override WooCommerce store notice options at runtime
        add_filter( 'pre_option_woocommerce_demo_store', [ $this, 'filter_demo_store_option' ] );
        add_filter( 'pre_option_woocommerce_demo_store_notice', [ $this, 'filter_demo_store_notice_option' ] );

        // Settings submenu for holidays
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );

        // Admin list table columns
        add_filter( 'manage_edit-wcas_campaign_columns', [ $this, 'admin_columns' ] );
        add_action( 'manage_wcas_campaign_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );
    }

    /*--------------------------------------------------------------
     * Post type
     *-------------------------------------------------------------*/

    public function register_post_type() {
        $labels = [
            'name'               => __( 'Sales Campaigns', 'woo-advanced-sales-campaigns' ),
            'singular_name'      => __( 'Sales Campaign', 'woo-advanced-sales-campaigns' ),
            'add_new_item'       => __( 'Add New Sales Campaign', 'woo-advanced-sales-campaigns' ),
            'edit_item'          => __( 'Edit Sales Campaign', 'woo-advanced-sales-campaigns' ),
            'new_item'           => __( 'New Sales Campaign', 'woo-advanced-sales-campaigns' ),
            'view_item'          => __( 'View Sales Campaign', 'woo-advanced-sales-campaigns' ),
            'search_items'       => __( 'Search Sales Campaigns', 'woo-advanced-sales-campaigns' ),
            'not_found'          => __( 'No campaigns found', 'woo-advanced-sales-campaigns' ),
            'not_found_in_trash' => __( 'No campaigns found in Trash', 'woo-advanced-sales-campaigns' ),
            'menu_name'          => __( 'Sales Campaigns', 'woo-advanced-sales-campaigns' ),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'woocommerce',
            'capability_type'    => 'post',
            'supports'           => [ 'title' ],
            'has_archive'        => false,
            'rewrite'            => false,
        ];

        register_post_type( self::POST_TYPE, $args );
    }


    public function admin_columns( $columns ) {
        $new = [];

        if ( isset( $columns['cb'] ) ) {
            $new['cb'] = $columns['cb'];
        }

        $new['title'] = __( 'Sales Campaign', 'woo-advanced-sales-campaigns' );
        $new['wcas_status'] = __( 'Status', 'woo-advanced-sales-campaigns' );
        $new['wcas_mode']   = __( 'Date Mode', 'woo-advanced-sales-campaigns' );
        $new['wcas_window'] = __( 'Window', 'woo-advanced-sales-campaigns' );
        $new['wcas_discount'] = __( 'Discount', 'woo-advanced-sales-campaigns' );

        if ( isset( $columns['date'] ) ) {
            $new['date'] = $columns['date'];
        }

        return $new;
    }

    public function admin_column_content( $column, $post_id ) {
        if ( get_post_type( $post_id ) !== self::POST_TYPE ) {
            return;
        }

        switch ( $column ) {
            case 'wcas_status':
                $status = $this->get_campaign_status( $post_id );
                echo esc_html( ucfirst( $status ) );
                break;

            case 'wcas_mode':
                $mode = get_post_meta( $post_id, '_wcas_date_mode', true );
                if ( 'holiday' === $mode ) {
                    esc_html_e( 'Holiday window', 'woo-advanced-sales-campaigns' );
                } else {
                    esc_html_e( 'Fixed date range', 'woo-advanced-sales-campaigns' );
                }
                break;

            case 'wcas_window':
                $window = $this->get_campaign_window( $post_id );
                if ( ! $window ) {
                    esc_html_e( '—', 'woo-advanced-sales-campaigns' );
                    break;
                }
                $start = date_i18n( get_option( 'date_format' ), $window['start'] );
                $end   = date_i18n( get_option( 'date_format' ), $window['end'] );
                echo esc_html( $start . ' → ' . $end );
                break;

            case 'wcas_discount':
                $meta = $this->get_campaign_meta( $post_id );
                $type = isset( $meta['discount_type'] ) ? $meta['discount_type'] : 'percent';
                $val  = isset( $meta['discount_value'] ) ? (float) $meta['discount_value'] : 0;

                if ( $val <= 0 ) {
                    esc_html_e( 'None', 'woo-advanced-sales-campaigns' );
                    break;
                }

                if ( 'percent' === $type ) {
                    printf( esc_html__( '%s%% off', 'woo-advanced-sales-campaigns' ), esc_html( $val ) );
                } else {
                    printf( esc_html__( '%s off', 'woo-advanced-sales-campaigns' ), wp_kses_post( wc_price( $val ) ) );
                }

                if ( isset( $meta['free_shipping'] ) && 'yes' === $meta['free_shipping'] ) {
                    echo ' &middot; ' . esc_html__( 'Free shipping', 'woo-advanced-sales-campaigns' );
                }

                if ( isset( $meta['store_notice_enable'] ) && 'yes' === $meta['store_notice_enable'] ) {
                    echo ' &middot; ' . esc_html__( 'Store notice', 'woo-advanced-sales-campaigns' );
                }
                break;
        }
    }


    /*--------------------------------------------------------------
     * Meta boxes
     *-------------------------------------------------------------*/

    public function add_meta_boxes() {
        add_meta_box(
            'wcas_campaign_dates',
            __( 'Dates & Status', 'woo-advanced-sales-campaigns' ),
            [ $this, 'render_campaign_dates_meta_box' ],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'wcas_campaign_discount',
            __( 'Discount & Shipping', 'woo-advanced-sales-campaigns' ),
            [ $this, 'render_campaign_discount_meta_box' ],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'wcas_campaign_targeting',
            __( 'Targeting', 'woo-advanced-sales-campaigns' ),
            [ $this, 'render_campaign_targeting_meta_box' ],
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    protected function get_campaign_meta( $post_id ) {
        $post_id = (int) $post_id;

        if ( isset( $this->campaign_meta_cache[ $post_id ] ) ) {
            return $this->campaign_meta_cache[ $post_id ];
        }

        $defaults = [
            'date_mode'             => 'fixed',
            'start_date'            => '',
            'end_date'              => '',
            'holiday_key'           => '',
            'holiday_offset'        => 0,
            'holiday_duration'      => 1,
            'recurrence'            => 'none',
            'status'                => '',
            'discount_type'         => 'percent',
            'discount_value'        => '',
            'apply_to_sale_items'   => 'no',
            'show_countdown'        => 'yes',
            'free_shipping'         => 'no',
            'include_products'      => [],
            'exclude_products'      => [],
            'include_cats'          => [],
            'exclude_cats'          => [],
            'include_tags'          => [],
            'exclude_tags'          => [],
            'store_notice_enable'   => 'no',
            'store_notice_message'  => '',
        ];

        $meta = [];
        foreach ( $defaults as $key => $default ) {
            $val = get_post_meta( $post_id, '_wcas_' . $key, true );
            if ( '' === $val && ! is_array( $default ) ) {
                $meta[ $key ] = $default;
            } else {
                $meta[ $key ] = ( '' === $val && is_array( $default ) ) ? [] : $val;
            }
        }

        // Normalize arrays
        foreach ( [ 'include_products', 'exclude_products', 'include_cats', 'exclude_cats', 'include_tags', 'exclude_tags' ] as $key ) {
            if ( ! is_array( $meta[ $key ] ) ) {
                $meta[ $key ] = $meta[ $key ] ? (array) $meta[ $key ] : [];
            }
            $meta[ $key ] = array_map( 'intval', $meta[ $key ] );
        }

        // Computed status text
        $meta['computed_status'] = $this->get_campaign_status( $post_id );

        $this->campaign_meta_cache[ $post_id ] = $meta;
        return $meta;
    }


    public function render_campaign_dates_meta_box( $post ) {
        wp_nonce_field( 'wcas_save_campaign', 'wcas_campaign_nonce' );
        $meta = $this->get_campaign_meta( $post->ID );
        $status_display = ucfirst( $meta['computed_status'] );
        ?>
        <h3><?php esc_html_e( 'Date Strategy', 'woo-advanced-sales-campaigns' ); ?></h3>

        <p>
            <label for="wcas_date_mode"><strong><?php esc_html_e( 'Date mode', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_date_mode" name="wcas_date_mode">
                <option value="fixed" <?php selected( $meta['date_mode'], 'fixed' ); ?>><?php esc_html_e( 'Fixed-date range', 'woo-advanced-sales-campaigns' ); ?></option>
                <option value="holiday" <?php selected( $meta['date_mode'], 'holiday' ); ?>><?php esc_html_e( 'Holiday-based window', 'woo-advanced-sales-campaigns' ); ?></option>
            </select>
        </p>

        <div class="wcas-date-fixed">
            <p>
                <label for="wcas_start_date"><strong><?php esc_html_e( 'Start date', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
                <input type="date" id="wcas_start_date" name="wcas_start_date" value="<?php echo esc_attr( $meta['start_date'] ); ?>" />
            </p>
            <p>
                <label for="wcas_end_date"><strong><?php esc_html_e( 'End date', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
                <input type="date" id="wcas_end_date" name="wcas_end_date" value="<?php echo esc_attr( $meta['end_date'] ); ?>" />
            </p>
        </div>

        <div class="wcas-date-holiday">
            <p>
                <label for="wcas_holiday_key"><strong><?php esc_html_e( 'Holiday', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
                <select id="wcas_holiday_key" name="wcas_holiday_key">
                    <option value=""><?php esc_html_e( 'Select a holiday...', 'woo-advanced-sales-campaigns' ); ?></option>
                    <?php
                    $year     = (int) current_time( 'Y' );
                    $holidays = $this->get_holidays_for_year( $year );
                    foreach ( $holidays as $key => $date ) {
                        $label = $this->format_holiday_label( $key, $date );
                        printf(
                            '<option value="%s"%s>%s</option>',
                            esc_attr( $key ),
                            selected( $meta['holiday_key'], $key, false ),
                            esc_html( $label )
                        );
                    }
                    ?>
                </select>
            </p>
            <p>
                <label for="wcas_holiday_offset"><strong><?php esc_html_e( 'Offset (days)', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
                <input type="number" id="wcas_holiday_offset" name="wcas_holiday_offset" value="<?php echo esc_attr( (int) $meta['holiday_offset'] ); ?>" />
                <br><em><?php esc_html_e( 'Negative values start before the holiday; positive values start after.', 'woo-advanced-sales-campaigns' ); ?></em>
            </p>
            <p>
                <label for="wcas_holiday_duration"><strong><?php esc_html_e( 'Duration (days)', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
                <input type="number" min="1" id="wcas_holiday_duration" name="wcas_holiday_duration" value="<?php echo esc_attr( (int) $meta['holiday_duration'] ); ?>" />
            </p>
        </div>

        <p>
            <label for="wcas_recurrence"><strong><?php esc_html_e( 'Recurrence', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_recurrence" name="wcas_recurrence">
                <option value="none" <?php selected( $meta['recurrence'], 'none' ); ?>><?php esc_html_e( 'None (one-time window)', 'woo-advanced-sales-campaigns' ); ?></option>
                <option value="yearly" <?php selected( $meta['recurrence'], 'yearly' ); ?>><?php esc_html_e( 'Yearly', 'woo-advanced-sales-campaigns' ); ?></option>
            </select>
        </p>

        <p>
            <label for="wcas_status"><strong><?php esc_html_e( 'Status override (optional)', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_status" name="wcas_status">
                <option value="" <?php selected( $meta['status'], '' ); ?>><?php esc_html_e( 'Automatic (based on date strategy)', 'woo-advanced-sales-campaigns' ); ?></option>
                <option value="running" <?php selected( $meta['status'], 'running' ); ?>><?php esc_html_e( 'Force Running (ignore schedule)', 'woo-advanced-sales-campaigns' ); ?></option>
                <option value="ended" <?php selected( $meta['status'], 'ended' ); ?>><?php esc_html_e( 'Force Ended (ignore schedule)', 'woo-advanced-sales-campaigns' ); ?></option>
            </select>
            <br>
            <em><?php printf( esc_html__( 'Calculated status now: %s', 'woo-advanced-sales-campaigns' ), esc_html( $status_display ) ); ?></em>
        </p>
        <?php
    }

    public function render_campaign_discount_meta_box( $post ) {
        $meta = $this->get_campaign_meta( $post->ID );
        ?>
        <h3><?php esc_html_e( 'Discount', 'woo-advanced-sales-campaigns' ); ?></h3>

        <p>
            <label for="wcas_discount_type"><strong><?php esc_html_e( 'Discount type', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_discount_type" name="wcas_discount_type">
                <option value="percent" <?php selected( $meta['discount_type'], 'percent' ); ?>><?php esc_html_e( 'Percentage (%)', 'woo-advanced-sales-campaigns' ); ?></option>
                <option value="amount" <?php selected( $meta['discount_type'], 'amount' ); ?>><?php esc_html_e( 'Fixed amount', 'woo-advanced-sales-campaigns' ); ?></option>
            </select>
        </p>

        <p>
            <label for="wcas_discount_value"><strong><?php esc_html_e( 'Discount value', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <input type="number" step="0.01" id="wcas_discount_value" name="wcas_discount_value" value="<?php echo esc_attr( $meta['discount_value'] ); ?>" />
        </p>

        <p>
            <label>
                <input type="checkbox" name="wcas_apply_to_sale_items" value="yes" <?php checked( $meta['apply_to_sale_items'], 'yes' ); ?> />
                <?php esc_html_e( 'Apply discount to products already on sale', 'woo-advanced-sales-campaigns' ); ?>
            </label>
        </p>

        <p>
            <label>
                <input type="checkbox" name="wcas_show_countdown" value="yes" <?php checked( $meta['show_countdown'], 'yes' ); ?> />
                <?php esc_html_e( 'Show countdown timer on product pages for this campaign', 'woo-advanced-sales-campaigns' ); ?>
            </label>
        </p>

        <h3><?php esc_html_e( 'Shipping', 'woo-advanced-sales-campaigns' ); ?></h3>

        <p>
            <label>
                <input type="checkbox" name="wcas_free_shipping" value="yes" <?php checked( $meta['free_shipping'], 'yes' ); ?> />
                <?php esc_html_e( 'Enable free shipping while this campaign is active (for matching products)', 'woo-advanced-sales-campaigns' ); ?>
            </label>
        </p>

        <h3><?php esc_html_e( 'Store Notice', 'woo-advanced-sales-campaigns' ); ?></h3>

        <p>
            <label>
                <input type="checkbox"
                       name="wcas_store_notice_enable"
                       value="yes"
                       <?php checked( $meta['store_notice_enable'], 'yes' ); ?> />
                <?php esc_html_e( 'Show a custom WooCommerce store notice while this campaign is active', 'woo-advanced-sales-campaigns' ); ?>
            </label>
        </p>

        <p>
            <label for="wcas_store_notice_message">
                <strong><?php esc_html_e( 'Store notice message', 'woo-advanced-sales-campaigns' ); ?></strong>
            </label><br>
            <textarea id="wcas_store_notice_message"
                      name="wcas_store_notice_message"
                      rows="3"
                      style="width:100%;"><?php echo esc_textarea( $meta['store_notice_message'] ); ?></textarea>
            <br>
            <em><?php esc_html_e( 'This message will appear in the WooCommerce store notice bar while the campaign is running.', 'woo-advanced-sales-campaigns' ); ?></em>
        </p>
        <?php
    }

    public function render_campaign_targeting_meta_box( $post ) {
        $meta = $this->get_campaign_meta( $post->ID );

        $include_products = $meta['include_products'];
        $exclude_products = $meta['exclude_products'];
        $include_cats     = $meta['include_cats'];
        $exclude_cats     = $meta['exclude_cats'];
        $include_tags     = $meta['include_tags'];
        $exclude_tags     = $meta['exclude_tags'];
        ?>
        <h3><?php esc_html_e( 'Products', 'woo-advanced-sales-campaigns' ); ?></h3>

        <p>
            <label><strong><?php esc_html_e( 'Include specific products', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_include_products" name="wcas_include_products[]" class="wcas-product-search" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $include_products ) ) {
                    foreach ( $include_products as $product_id ) {
                        $product = wc_get_product( $product_id );
                        if ( $product ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $product_id,
                                esc_html( $product->get_formatted_name() )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>

        <p>
            <label><strong><?php esc_html_e( 'Exclude specific products', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_exclude_products" name="wcas_exclude_products[]" class="wcas-product-search" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $exclude_products ) ) {
                    foreach ( $exclude_products as $product_id ) {
                        $product = wc_get_product( $product_id );
                        if ( $product ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $product_id,
                                esc_html( $product->get_formatted_name() )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>

        <h3><?php esc_html_e( 'Categories', 'woo-advanced-sales-campaigns' ); ?></h3>
        <p>
            <label><strong><?php esc_html_e( 'Include categories', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_include_cats" name="wcas_include_cats[]" class="wcas-term-search" data-taxonomy="product_cat" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $include_cats ) ) {
                    foreach ( $include_cats as $term_id ) {
                        $term = get_term( $term_id, 'product_cat' );
                        if ( $term && ! is_wp_error( $term ) ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $term_id,
                                esc_html( $term->name )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Exclude categories', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_exclude_cats" name="wcas_exclude_cats[]" class="wcas-term-search" data-taxonomy="product_cat" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $exclude_cats ) ) {
                    foreach ( $exclude_cats as $term_id ) {
                        $term = get_term( $term_id, 'product_cat' );
                        if ( $term && ! is_wp_error( $term ) ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $term_id,
                                esc_html( $term->name )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>

        <h3><?php esc_html_e( 'Tags', 'woo-advanced-sales-campaigns' ); ?></h3>
        <p>
            <label><strong><?php esc_html_e( 'Include tags', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_include_tags" name="wcas_include_tags[]" class="wcas-term-search" data-taxonomy="product_tag" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $include_tags ) ) {
                    foreach ( $include_tags as $term_id ) {
                        $term = get_term( $term_id, 'product_tag' );
                        if ( $term && ! is_wp_error( $term ) ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $term_id,
                                esc_html( $term->name )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>
        <p>
            <label><strong><?php esc_html_e( 'Exclude tags', 'woo-advanced-sales-campaigns' ); ?></strong></label><br>
            <select id="wcas_exclude_tags" name="wcas_exclude_tags[]" class="wcas-term-search" data-taxonomy="product_tag" multiple="multiple" style="width:100%;">
                <?php
                if ( ! empty( $exclude_tags ) ) {
                    foreach ( $exclude_tags as $term_id ) {
                        $term = get_term( $term_id, 'product_tag' );
                        if ( $term && ! is_wp_error( $term ) ) {
                            printf(
                                '<option value="%d" selected="selected">%s</option>',
                                $term_id,
                                esc_html( $term->name )
                            );
                        }
                    }
                }
                ?>
            </select>
        </p>
        <?php
    }

    /*--------------------------------------------------------------
     * Save
     *-------------------------------------------------------------*/

    public function save_campaign( $post_id, $post ) {
        if ( ! isset( $_POST['wcas_campaign_nonce'] ) || ! wp_verify_nonce( $_POST['wcas_campaign_nonce'], 'wcas_save_campaign' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $fields = [
            'date_mode'        => 'text',
            'start_date'       => 'text',
            'end_date'         => 'text',
            'holiday_key'      => 'text',
            'holiday_offset'   => 'int',
            'holiday_duration' => 'int',
            'recurrence'       => 'text',
            'status'           => 'text',
            'discount_type'    => 'text',
            'discount_value'   => 'float',
        ];

        foreach ( $fields as $key => $type ) {
            $form_key = 'wcas_' . $key;
            $meta_key = '_wcas_' . $key;
            if ( ! isset( $_POST[ $form_key ] ) ) {
                if ( in_array( $type, [ 'int', 'float' ], true ) ) {
                    update_post_meta( $post_id, $meta_key, 0 );
                } else {
                    delete_post_meta( $post_id, $meta_key );
                }
                continue;
            }

            $val = $_POST[ $form_key ];
            if ( 'int' === $type ) {
                $val = (int) $val;
            } elseif ( 'float' === $type ) {
                $val = (float) $val;
            } else {
                $val = sanitize_text_field( wp_unslash( $val ) );
            }
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Booleans
        $bool_fields = [
            'apply_to_sale_items',
            'show_countdown',
            'free_shipping',
            'store_notice_enable',
        ];
        foreach ( $bool_fields as $key ) {
            $meta_key = '_wcas_' . $key;
            $val      = isset( $_POST[ 'wcas_' . $key ] ) ? 'yes' : 'no';
            update_post_meta( $post_id, $meta_key, $val );
        }

        // Store notice message
        $msg = isset( $_POST['wcas_store_notice_message'] ) ? wp_kses_post( wp_unslash( $_POST['wcas_store_notice_message'] ) ) : '';
        update_post_meta( $post_id, '_wcas_store_notice_message', $msg );

        // Arrays: products, cats, tags
        $array_fields = [
            'include_products',
            'exclude_products',
            'include_cats',
            'exclude_cats',
            'include_tags',
            'exclude_tags',
        ];
        foreach ( $array_fields as $key ) {
            $form_key = 'wcas_' . $key;
            $meta_key = '_wcas_' . $key;
            $val      = isset( $_POST[ $form_key ] ) ? (array) $_POST[ $form_key ] : [];
            $val      = array_filter( array_map( 'intval', $val ) );
            update_post_meta( $post_id, $meta_key, $val );
        }
    }

    /*--------------------------------------------------------------
     * Assets
     *-------------------------------------------------------------*/

    public function admin_assets( $hook ) {
        global $post_type;
        if ( ( 'post.php' === $hook || 'post-new.php' === $hook ) && self::POST_TYPE === $post_type ) {
            // Select2 from WooCommerce admin
            wp_enqueue_script( 'selectWoo' );
            wp_enqueue_style( 'select2' );

            wp_enqueue_script(
                'wcas-admin',
                plugins_url( 'assets/js/wcas-admin.js', __FILE__ ),
                [ 'jquery', 'selectWoo' ],
                '0.2.0',
                true
            );
            wp_localize_script(
                'wcas-admin',
                'wcasAdminData',
                [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                ]
            );
        }
    }

    public function frontend_assets() {
        if ( is_product() ) {
            wp_enqueue_style(
                'wcas-frontend',
                plugins_url( 'assets/css/wcas-frontend.css', __FILE__ ),
                [],
                '0.2.0'
            );
            wp_enqueue_script(
                'wcas-frontend',
                plugins_url( 'assets/js/wcas-frontend.js', __FILE__ ),
                [ 'jquery' ],
                '0.2.0',
                true
            );
        }
    }

    /*--------------------------------------------------------------
     * AJAX
     *-------------------------------------------------------------*/

    public function ajax_search_products() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $page   = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
        $limit  = 20;
        $offset = ( $page - 1 ) * $limit;

        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish' ],
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'title',
            'order'          => 'ASC',
            's'              => $search,
            'fields'         => 'ids',
        ];

        $q = new WP_Query( $args );
        $results = [];

        foreach ( $q->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $results[] = [
                'id'   => $product_id,
                'text' => $product->get_formatted_name(),
            ];
        }

        $more = ( $q->found_posts > $offset + $limit );
        wp_send_json_success(
            [
                'results' => $results,
                'more'    => $more,
            ]
        );
    }

    public function ajax_search_terms() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error();
        }

        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
        if ( ! in_array( $taxonomy, [ 'product_cat', 'product_tag' ], true ) ) {
            wp_send_json_error();
        }

        $search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
        $page   = isset( $_GET['page'] ) ? max( 1, intval( $_GET['page'] ) ) : 1;
        $limit  = 20;
        $offset = ( $page - 1 ) * $limit;

        $args = [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'number'     => $limit,
            'offset'     => $offset,
        ];

        if ( '' !== $search ) {
            $args['search'] = $search;
        }

        $terms = get_terms( $args );
        if ( is_wp_error( $terms ) ) {
            wp_send_json_error();
        }

        $results = [];
        foreach ( $terms as $term ) {
            $results[] = [
                'id'   => $term->term_id,
                'text' => $term->name,
            ];
        }

        $more = ( count( $terms ) === $limit );
        wp_send_json_success(
            [
                'results' => $results,
                'more'    => $more,
            ]
        );
    }

    /*--------------------------------------------------------------
     * Campaign logic
     *-------------------------------------------------------------*/

    public function get_active_campaigns() {
        if ( null !== $this->active_campaign_ids ) {
            return $this->active_campaign_ids;
        }

        $this->active_campaign_ids = [];

        $args = [
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];
        $q = new WP_Query( $args );
        if ( empty( $q->posts ) ) {
            return [];
        }

        foreach ( $q->posts as $campaign_id ) {
            $status = $this->get_campaign_status( $campaign_id );
            if ( 'running' === $status ) {
                $this->active_campaign_ids[] = $campaign_id;
            }
        }

        return $this->active_campaign_ids;
    }

    public function get_campaign_status( $campaign_id ) {
        $campaign_id = (int) $campaign_id;

        $override = get_post_meta( $campaign_id, '_wcas_status', true );
        if ( 'running' === $override ) {
            return 'running';
        }
        if ( 'ended' === $override ) {
            return 'ended';
        }

        $window = $this->get_campaign_window( $campaign_id );
        if ( ! $window ) {
            return 'unscheduled';
        }

        $now = current_time( 'timestamp', true ); // GMT

        if ( $now < $window['start'] ) {
            return 'scheduled';
        }
        if ( $now > $window['end'] ) {
            return 'ended';
        }

        return 'running';
    }

    public function get_campaign_window( $campaign_id ) {
        $campaign_id = (int) $campaign_id;

        if ( isset( $this->campaign_window_cache[ $campaign_id ] ) ) {
            return $this->campaign_window_cache[ $campaign_id ];
        }

        $mode = get_post_meta( $campaign_id, '_wcas_date_mode', true );
        if ( ! in_array( $mode, [ 'fixed', 'holiday' ], true ) ) {
            $mode = 'fixed';
        }

        if ( 'holiday' === $mode ) {
            $window = $this->get_holiday_window( $campaign_id );
        } else {
            $window = $this->get_fixed_window( $campaign_id );
        }

        if ( ! $window || empty( $window['start'] ) || empty( $window['end'] ) ) {
            $this->campaign_window_cache[ $campaign_id ] = null;
            return null;
        }

        $window = [
            'start' => (int) $window['start'],
            'end'   => (int) $window['end'],
        ];

        $this->campaign_window_cache[ $campaign_id ] = $window;
        return $window;
    }

    protected function get_fixed_window( $campaign_id ) {
        $start_raw = get_post_meta( $campaign_id, '_wcas_start_date', true );
        $end_raw   = get_post_meta( $campaign_id, '_wcas_end_date', true );
        $recur     = get_post_meta( $campaign_id, '_wcas_recurrence', true );

        if ( empty( $start_raw ) || empty( $end_raw ) ) {
            return null;
        }

        $start_ts = strtotime( $start_raw . ' 00:00:00' );
        $end_ts   = strtotime( $end_raw . ' 23:59:59' );

        if ( ! $start_ts || ! $end_ts ) {
            return null;
        }

        // Simple yearly recurrence: project dates into current year.
        if ( 'yearly' === $recur ) {
            $year = (int) current_time( 'Y' );
            $start_ts = strtotime( sprintf( '%04d-%02d-%02d 00:00:00', $year, (int) date( 'm', $start_ts ), (int) date( 'd', $start_ts ) ) );
            $end_ts   = strtotime( sprintf( '%04d-%02d-%02d 23:59:59', $year, (int) date( 'm', $end_ts ), (int) date( 'd', $end_ts ) ) );
        }

        return [
            'start' => $start_ts,
            'end'   => $end_ts,
        ];
    }

    protected function get_holiday_window( $campaign_id ) {
        $holiday_key = get_post_meta( $campaign_id, '_wcas_holiday_key', true );
        if ( ! $holiday_key ) {
            return null;
        }

        $year     = (int) current_time( 'Y' );
        $holidays = $this->get_holidays_for_year( $year );

        if ( ! isset( $holidays[ $holiday_key ] ) ) {
            return null;
        }

        $base_date = $holidays[ $holiday_key ]; // Y-m-d
        $offset    = (int) get_post_meta( $campaign_id, '_wcas_holiday_offset', true );
        $duration  = (int) get_post_meta( $campaign_id, '_wcas_holiday_duration', true );
        if ( $duration < 1 ) {
            $duration = 1;
        }

        $start_ts = strtotime( $base_date . ' 00:00:00' );
        if ( $offset !== 0 ) {
            $start_ts = strtotime( sprintf( '%+d days', $offset ), $start_ts );
        }
        $end_ts = strtotime( sprintf( '+%d days', $duration - 1 ), $start_ts );
        $end_ts = strtotime( date( 'Y-m-d 23:59:59', $end_ts ) );

        return [
            'start' => $start_ts,
            'end'   => $end_ts,
        ];
    }

    protected function campaign_applies_to_product( $campaign_id, WC_Product $product ) {
        $meta = $this->get_campaign_meta( $campaign_id );

        $product_id = $product->get_id();

        // Include products
        if ( ! empty( $meta['include_products'] ) && ! in_array( $product_id, $meta['include_products'], true ) ) {
            return false;
        }
        // Exclude products
        if ( ! empty( $meta['exclude_products'] ) && in_array( $product_id, $meta['exclude_products'], true ) ) {
            return false;
        }

        $terms_cat = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        $terms_tag = wp_get_post_terms( $product_id, 'product_tag', [ 'fields' => 'ids' ] );

        // Include cats
        if ( ! empty( $meta['include_cats'] ) ) {
            if ( empty( $terms_cat ) || ! array_intersect( $meta['include_cats'], $terms_cat ) ) {
                return false;
            }
        }
        // Exclude cats
        if ( ! empty( $meta['exclude_cats'] ) && ! empty( $terms_cat ) ) {
            if ( array_intersect( $meta['exclude_cats'], $terms_cat ) ) {
                return false;
            }
        }

        // Include tags
        if ( ! empty( $meta['include_tags'] ) ) {
            if ( empty( $terms_tag ) || ! array_intersect( $meta['include_tags'], $terms_tag ) ) {
                return false;
            }
        }
        // Exclude tags
        if ( ! empty( $meta['exclude_tags'] ) && ! empty( $terms_tag ) ) {
            if ( array_intersect( $meta['exclude_tags'], $terms_tag ) ) {
                return false;
            }
        }

        return true;
    }

    protected function calculate_discounted_price( WC_Product $product, $base_price ) {
        $base_price = (float) $base_price;
        if ( $base_price <= 0 ) {
            return $base_price;
        }

        $product_id = $product->get_id();
        if ( isset( $this->discount_cache[ $product_id ] ) ) {
            return $this->discount_cache[ $product_id ];
        }

        $campaign_ids = $this->get_active_campaigns();
        if ( empty( $campaign_ids ) ) {
            $this->discount_cache[ $product_id ] = $base_price;
            return $base_price;
        }

        $best_price = $base_price;

        foreach ( $campaign_ids as $cid ) {
            if ( ! $this->campaign_applies_to_product( $cid, $product ) ) {
                continue;
            }

            $meta = $this->get_campaign_meta( $cid );

            $sale = $product->get_sale_price( 'edit' );
            $is_on_sale_already = ( '' !== $sale && null !== $sale );
            if ( $is_on_sale_already && 'yes' !== $meta['apply_to_sale_items'] ) {
                continue;
            }

            $discount_type  = $meta['discount_type'];
            $discount_value = (float) $meta['discount_value'];
            if ( $discount_value <= 0 ) {
                continue;
            }

            $candidate = $base_price;
            if ( 'percent' === $discount_type ) {
                $candidate = $base_price * ( 1 - ( $discount_value / 100 ) );
            } else {
                $candidate = $base_price - $discount_value;
            }
            if ( $candidate < 0 ) {
                $candidate = 0;
            }

            if ( $candidate < $best_price ) {
                $best_price = $candidate;
            }
        }

        $this->discount_cache[ $product_id ] = $best_price;
        return $best_price;
    }

    public function filter_product_price( $price, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $price;
        }
        $regular = $product->get_regular_price();
        if ( '' === $regular || null === $regular ) {
            return $price;
        }
        $regular   = (float) $regular;
        $discounted = $this->calculate_discounted_price( $product, $regular );

        if ( $discounted >= $regular ) {
            return $price;
        }

        return $discounted;
    }

    public function filter_product_sale_price( $sale_price, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $sale_price;
        }
        $regular = $product->get_regular_price();
        if ( '' === $regular || null === $regular ) {
            return $sale_price;
        }
        $regular    = (float) $regular;
        $discounted = $this->calculate_discounted_price( $product, $regular );

        if ( $discounted >= $regular ) {
            return $sale_price;
        }

        return $discounted;
    }

    public function filter_product_is_on_sale( $is_on_sale, $product ) {
        if ( ! $product instanceof WC_Product ) {
            return $is_on_sale;
        }

        $regular = $product->get_regular_price();
        if ( '' === $regular || null === $regular ) {
            return $is_on_sale;
        }
        $regular    = (float) $regular;
        $discounted = $this->calculate_discounted_price( $product, $regular );

        if ( $discounted < $regular ) {
            return true;
        }

        return $is_on_sale;
    }

    public function filter_price_html_savings( $price_html, $product ) {
        if ( ! $product instanceof WC_Product ) {
        return $price_html;
        }

        $regular = $product->get_regular_price();
        if ( '' === $regular || null === $regular ) {
            return $price_html;
        }

        $regular    = (float) $regular;
        $discounted = $this->calculate_discounted_price( $product, $regular );

        if ( $discounted >= $regular ) {
            return $price_html;
        }

        $saved     = $regular - $discounted;
        $saved_pct = $regular > 0 ? ( $saved / $regular ) * 100 : 0;

        $regular_html    = wc_price( $regular );
        $discounted_html = wc_price( $discounted );

        $html  = '<span class="price">';
        $html .= '<del>' . $regular_html . '</del> ';
        $html .= '<ins>' . $discounted_html . '</ins>';
        $html .= '<br /><span class="wcas-saving">' . sprintf(
            /* translators: 1: amount saved, 2: percent saved */
            esc_html__( 'You save %1$s (%2$.0f%%)', 'woo-advanced-sales-campaigns' ),
            wc_price( $saved ),
            $saved_pct
        ) . '</span>';
        $html .= '</span>';

        return $html;
    }

    /*--------------------------------------------------------------
     * Countdown
     *-------------------------------------------------------------*/

    public function render_countdown_timer() {
        if ( ! is_product() ) {
            return;
        }
        global $product;
        if ( ! $product instanceof WC_Product ) {
            return;
        }

        $campaign_ids = $this->get_active_campaigns();
        if ( empty( $campaign_ids ) ) {
            return;
        }

        $now       = current_time( 'timestamp', true );
        $target_id = null;
        $expiry_ts = null;

        foreach ( $campaign_ids as $cid ) {
            if ( ! $this->campaign_applies_to_product( $cid, $product ) ) {
                continue;
            }

            $meta = $this->get_campaign_meta( $cid );
            if ( 'yes' !== $meta['show_countdown'] ) {
                continue;
            }

            $window = $this->get_campaign_window( $cid );
            if ( ! $window ) {
                continue;
            }
            if ( $now >= $window['end'] ) {
                continue;
            }

            $target_id = $cid;
            $expiry_ts = $window['end'];
            break;
        }

        if ( ! $target_id || ! $expiry_ts ) {
            return;
        }

        $expiry_attr = esc_attr( $expiry_ts );
        $now_attr    = esc_attr( current_time( 'timestamp', true ) );

        echo '<div class="wcas-countdown-wrapper" data-expiry="' . $expiry_attr . '" data-now="' . $now_attr . '">';
        echo '<strong>' . esc_html__( 'Limited-time sale!', 'woo-advanced-sales-campaigns' ) . '</strong>';
        echo '<div class="wcas-countdown wcas-countdown-circles">';
        echo '<div class="wcas-circle"><span class="wcas-num" data-unit="days">00</span><span class="wcas-label">' . esc_html__( 'days', 'woo-advanced-sales-campaigns' ) . '</span></div>';
        echo '<div class="wcas-circle"><span class="wcas-num" data-unit="hours">00</span><span class="wcas-label">' . esc_html__( 'hrs', 'woo-advanced-sales-campaigns' ) . '</span></div>';
        echo '<div class="wcas-circle"><span class="wcas-num" data-unit="mins">00</span><span class="wcas-label">' . esc_html__( 'mins', 'woo-advanced-sales-campaigns' ) . '</span></div>';
        echo '<div class="wcas-circle"><span class="wcas-num" data-unit="secs">00</span><span class="wcas-label">' . esc_html__( 'secs', 'woo-advanced-sales-campaigns' ) . '</span></div>';
        echo '</div>';
        echo '<div class="wcas-countdown-note">' . esc_html__( 'This is how much longer this special price is available.', 'woo-advanced-sales-campaigns' ) . '</div>';
        echo '</div>';
    }

    /*--------------------------------------------------------------
     * Free shipping (simple flag)
     *-------------------------------------------------------------*/

    // NOTE: Implementing full free-shipping integration with all shipping methods
    // is complex and highly site-specific. This version exposes the free_shipping
    // flag in the campaign meta so you can hook into your shipping logic as needed.

    /*--------------------------------------------------------------
     * Store notice integration
     *-------------------------------------------------------------*/

    public function maybe_enable_store_notice( $enabled ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $enabled;
        }

        $campaign_id = $this->get_active_store_notice_campaign();
        if ( ! $campaign_id ) {
            return $enabled;
        }

        return 'yes';
    }

    public function maybe_store_notice_message( $message ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $message;
        }

        $campaign_id = $this->get_active_store_notice_campaign();
        if ( ! $campaign_id ) {
            return $message;
        }

        $custom = get_post_meta( $campaign_id, '_wcas_store_notice_message', true );
        if ( '' === $custom ) {
            return $message;
        }

        return $custom;
    }


    public function filter_demo_store_option( $value ) {
        // Called before get_option( 'woocommerce_demo_store' ).
        $campaign_id = $this->get_active_store_notice_campaign();
        if ( ! $campaign_id ) {
            return $value;
        }

        // Force demo store ON while a store-notice campaign is active.
        return 'yes';
    }

    public function filter_demo_store_notice_option( $value ) {
        // Called before get_option( 'woocommerce_demo_store_notice' ).
        $campaign_id = $this->get_active_store_notice_campaign();
        if ( ! $campaign_id ) {
            return $value;
        }

        $custom = get_post_meta( $campaign_id, '_wcas_store_notice_message', true );
        if ( '' === $custom ) {
            return $value;
        }

        return $custom;
    }


    protected function get_active_store_notice_campaign() {
        if ( null !== $this->store_notice_campaign_id ) {
            return $this->store_notice_campaign_id;
        }

        $this->store_notice_campaign_id = 0;
        $campaigns = $this->get_active_campaigns();
        if ( empty( $campaigns ) ) {
            return 0;
        }

        foreach ( $campaigns as $cid ) {
            $enable = get_post_meta( $cid, '_wcas_store_notice_enable', true );
            if ( 'yes' === $enable ) {
                $this->store_notice_campaign_id = (int) $cid;
                break;
            }
        }

        return $this->store_notice_campaign_id;
    }

    /*--------------------------------------------------------------
     * Holidays settings page
     *-------------------------------------------------------------*/

    public function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Sales Campaign Holidays', 'woo-advanced-sales-campaigns' ),
            __( 'Sales Holidays', 'woo-advanced-sales-campaigns' ),
            'manage_woocommerce',
            'wcas-holidays',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting(
            'wcas_holidays_group',
            self::OPTION_CUSTOM_HOLIDAYS,
            [ $this, 'sanitize_custom_holidays_option' ]
        );

        add_settings_section(
            'wcas_holidays_section',
            __( 'Custom Holidays', 'woo-advanced-sales-campaigns' ),
            '__return_false',
            'wcas-holidays'
        );

        add_settings_field(
            'wcas_holidays_field',
            __( 'Custom Holidays', 'woo-advanced-sales-campaigns' ),
            [ $this, 'render_holidays_field' ],
            'wcas-holidays',
            'wcas_holidays_section'
        );
    }

    public function sanitize_custom_holidays_option( $input ) {
        if ( ! is_array( $input ) ) {
            return [];
        }

        $clean = [];

        foreach ( $input as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $name  = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';
            $month = isset( $row['month'] ) ? (int) $row['month'] : 0;
            $day   = isset( $row['day'] ) ? (int) $row['day'] : 0;
            $del   = ! empty( $row['delete'] );

            if ( $del || '' === $name ) {
                continue;
            }

            if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 ) {
                continue;
            }

            $clean[] = [
                'name'  => $name,
                'month' => $month,
                'day'   => $day,
            ];
        }

        return $clean;
    }

    public function render_holidays_field() {
        $rows = get_option( self::OPTION_CUSTOM_HOLIDAYS, [] );
        if ( ! is_array( $rows ) ) {
            $rows = [];
        }

        // Always provide at least one empty row.
        $rows[] = [
            'name'  => '',
            'month' => '',
            'day'   => '',
        ];
        ?>
        <table class="widefat striped" id="wcas-holidays-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Holiday Name', 'woo-advanced-sales-campaigns' ); ?></th>
                    <th><?php esc_html_e( 'Month', 'woo-advanced-sales-campaigns' ); ?></th>
                    <th><?php esc_html_e( 'Day', 'woo-advanced-sales-campaigns' ); ?></th>
                    <th><?php esc_html_e( 'Delete', 'woo-advanced-sales-campaigns' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $index => $row ) : ?>
                    <tr>
                        <td>
                            <input type="text"
                                   name="<?php echo esc_attr( self::OPTION_CUSTOM_HOLIDAYS ); ?>[<?php echo esc_attr( $index ); ?>][name]"
                                   value="<?php echo isset( $row['name'] ) ? esc_attr( $row['name'] ) : ''; ?>"
                                   class="regular-text" />
                        </td>
                        <td>
                            <input type="number"
                                   min="1"
                                   max="12"
                                   name="<?php echo esc_attr( self::OPTION_CUSTOM_HOLIDAYS ); ?>[<?php echo esc_attr( $index ); ?>][month]"
                                   value="<?php echo isset( $row['month'] ) ? esc_attr( $row['month'] ) : ''; ?>"
                                   style="width: 80px;" />
                        </td>
                        <td>
                            <input type="number"
                                   min="1"
                                   max="31"
                                   name="<?php echo esc_attr( self::OPTION_CUSTOM_HOLIDAYS ); ?>[<?php echo esc_attr( $index ); ?>][day]"
                                   value="<?php echo isset( $row['day'] ) ? esc_attr( $row['day'] ) : ''; ?>"
                                   style="width: 80px;" />
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox"
                                   name="<?php echo esc_attr( self::OPTION_CUSTOM_HOLIDAYS ); ?>[<?php echo esc_attr( $index ); ?>][delete]"
                                   value="1" />
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button" id="wcas-add-holiday-row">
                <?php esc_html_e( 'Add Holiday', 'woo-advanced-sales-campaigns' ); ?>
            </button>
        </p>
        <script>
            (function($){
                $('#wcas-add-holiday-row').on('click', function(e){
                    e.preventDefault();
                    var $table = $('#wcas-holidays-table tbody');
                    var $last  = $table.find('tr:last');
                    var $clone = $last.clone();

                    $clone.find('input').each(function(){
                        var $input = $(this);
                        if ($input.is(':checkbox')) {
                            $input.prop('checked', false);
                        } else {
                            $input.val('');
                        }
                    });

                    $table.append($clone);
                });
            })(jQuery);
        </script>
        <?php
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Sales Campaign Holidays', 'woo-advanced-sales-campaigns' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'wcas_holidays_group' );
                do_settings_sections( 'wcas-holidays' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /*--------------------------------------------------------------
     * Holiday helpers
     *-------------------------------------------------------------*/

    protected function format_holiday_label( $key, $date ) {
        $name = str_replace( '_', ' ', $key );
        $name = ucwords( strtolower( $name ) );
        $formatted_date = date_i18n( 'M j, Y', strtotime( $date ) );
        return sprintf( '%s (%s)', $name, $formatted_date );
    }

    public function get_holidays_for_year( $year ) {
        $year = (int) $year;
        $holidays = [];

        // Approximate solar terms
        $holidays['First_Day_of_Spring']   = sprintf( '%04d-03-20', $year );
        $holidays['Vernal_Equinox']        = $holidays['First_Day_of_Spring'];
        $holidays['First_Day_of_Summer']   = sprintf( '%04d-06-21', $year );
        $holidays['Summer_Solstice']       = $holidays['First_Day_of_Summer'];
        $holidays['First_Day_of_Autumn']   = sprintf( '%04d-09-22', $year );
        $holidays['Autumnal_Equinox']      = $holidays['First_Day_of_Autumn'];
        $holidays['First_Day_of_Winter']   = sprintf( '%04d-12-21', $year );
        $holidays['Winter_Solstice']       = $holidays['First_Day_of_Winter'];

        // Chinese New Year (approx using known algorithm)
        $holidays['Chinese_New_Year'] = $this->chinese_new_year_date( $year );

        // User-specified martial / standard holidays (subset for brevity)
        $holidays['New_Years_Day']          = date( 'Y-m-d', strtotime( "first day of january $year" ) );
        $holidays['Isshinryu_Birthday']     = date( 'Y-m-d', strtotime( "January 15 $year" ) );
        $holidays['MLK_Day']                = date( 'Y-m-d', strtotime( "january $year third monday" ) );
        $holidays['Groundhog_Day']          = date( 'Y-m-d', strtotime( "February 2 $year" ) );
        $holidays['Valentines_Day']         = date( 'Y-m-d', strtotime( "February 14 $year" ) );
        $holidays['Presidents_Day']         = date( 'Y-m-d', strtotime( "third monday of February $year" ) );
        $holidays['Saint_Patricks_Day']     = date( 'Y-m-d', strtotime( "March 17 $year" ) );
        $holidays['April_Fools_Day']        = date( 'Y-m-d', strtotime( "April 1 $year" ) );
        $holidays['Good_Friday']            = date( 'Y-m-d', strtotime( '-2 days', strtotime( date( 'Y-m-d', easter_date( $year ) ) ) ) );
        $holidays['Easter_Day']             = date( 'Y-m-d', easter_date( $year ) );
        $holidays['Pi_Day']                 = date( 'Y-m-d', strtotime( "March 14 $year" ) );
        $holidays['Earth_Day']              = date( 'Y-m-d', strtotime( "April 22 $year" ) );
        $holidays['Star_Wars_Day']          = date( 'Y-m-d', strtotime( "may 4 $year" ) );
        $holidays['Cinco_De_Mayo_Day']      = date( 'Y-m-d', strtotime( "may 5 $year" ) );
        $holidays['Mothers_Day']            = date( 'Y-m-d', strtotime( "second Sunday of May $year" ) );
        $holidays['Memorial_Day']           = date( 'Y-m-d', strtotime( "last monday of may $year" ) );
        $holidays['Fathers_Day']            = date( 'Y-m-d', strtotime( "third Sunday of June $year" ) );
        $holidays['Canada_Day']             = date( 'Y-m-d', strtotime( "july 1 $year" ) );
        $holidays['Independence_Day']       = date( 'Y-m-d', strtotime( "july 4 $year" ) );
        $holidays['Labor_Day']              = date( 'Y-m-d', strtotime( "september $year first monday" ) );
        $holidays['Halloween']              = date( 'Y-m-d', strtotime( "october 31 $year" ) );
        $holidays['Thanksgiving']           = date( 'Y-m-d', strtotime( "november $year fourth thursday" ) );
        $holidays['Black_Friday']           = date( 'Y-m-d', strtotime( '+1 day', strtotime( $holidays['Thanksgiving'] ) ) );
        $holidays['Cyber_Monday']           = date( 'Y-m-d', strtotime( '+4 days', strtotime( $holidays['Thanksgiving'] ) ) );
        $holidays['Christmas_Eve']          = date( 'Y-m-d', strtotime( "december 24 $year" ) );
        $holidays['Christmas_Day']          = date( 'Y-m-d', strtotime( "december 25 $year" ) );
        $holidays['Boxing_Day']             = date( 'Y-m-d', strtotime( "december 26 $year" ) );
        $holidays['New_Years_Eve']          = date( 'Y-m-d', strtotime( "last day of december $year" ) );

        // Custom holidays from settings
        $custom = get_option( self::OPTION_CUSTOM_HOLIDAYS, [] );
        if ( is_array( $custom ) ) {
            foreach ( $custom as $row ) {
                if ( empty( $row['name'] ) || empty( $row['month'] ) || empty( $row['day'] ) ) {
                    continue;
                }
                $key  = preg_replace( '/\s+/', '_', trim( $row['name'] ) );
                $date = sprintf( '%04d-%02d-%02d', $year, (int) $row['month'], (int) $row['day'] );
                $holidays[ $key ] = $date;
            }
        }

        asort( $holidays );
        return $holidays;
    }

    /**
     * Approximate Chinese New Year date for a given Gregorian year.
     * This uses a simple table-based method for modern years, with a fallback.
     */
    protected function chinese_new_year_date( $year ) {
        $year = (int) $year;
        $table = [
            // A small modern range; can be extended as needed.
            2023 => '2023-01-22',
            2024 => '2024-02-10',
            2025 => '2025-01-29',
            2026 => '2026-02-17',
            2027 => '2027-02-06',
            2028 => '2028-01-26',
        ];
        if ( isset( $table[ $year ] ) ) {
            return $table[ $year ];
        }

        // Fallback approximation: late Jan / mid Feb around new moon after Jan 21
        $approx = strtotime( "$year-02-04" );
        return date( 'Y-m-d', $approx );
    }
}

}

WCAS_Plugin::instance();
