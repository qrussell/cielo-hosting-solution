<?php

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode to display individual hosting product plans with Buy Now button.
 *
 * Usage: [skyhshoso_hosting_plan id="123"]
 *        [skyhshoso_hosting_plan id="123" title="Custom Title"]
 *
 * Renders inside Shadow DOM for complete CSS isolation from themes.
 */
class SkyHSHOSO_Product_Shortcode {

    public static function init() {
        add_shortcode( 'skyhshoso_hosting_plan', array( self::class, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
    }

    public static function enqueue_assets() {
        global $post;
        if ( is_singular() && is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'skyhshoso_hosting_plan' ) ) {
            wp_enqueue_script(
                'skyhshoso-product-shortcode',
                SKYHSHOSO_PLUGIN_URL . 'assets/js/product-shortcode.js',
                array(),
                SKYHSHOSO_VERSION,
                true
            );
        }
    }

    /**
     * Parse a feature string and return HTML with inline SVG icons.
     *
     * Used by the dashboard shortcode (outside Shadow DOM).
     * Inside Shadow DOM, the JS handles feature rendering directly.
     *
     * Syntax:
     *   [+] Feature text  or  + Feature text  →  check icon
     *   [-] Feature text  or  - Feature text  →  cross icon
     *   Plain text                            →  neutral dot
     *
     * @param string $feature Raw feature string.
     * @return string HTML with SVG icon + escaped text.
     */
    public static function parse_feature( $feature ) {
        $feature = trim( $feature );
        $icon    = '';
        $text    = $feature;

        // SVG icons — small inline, theme-independent.
        $svg_check = '<span class="skyhshoso-fi skyhshoso-fi-check"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#dbeafe"/><path d="M6 10.5l2.5 2.5L14 7.5" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        $svg_cross = '<span class="skyhshoso-fi skyhshoso-fi-cross"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#f1f5f9"/><path d="M7 7l6 6M13 7l-6 6" stroke="#94a3b8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';
        $svg_neutral = '<span class="skyhshoso-fi skyhshoso-fi-neutral"><svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#f1f5f9"/><path d="M7 10h6" stroke="#94a3b8" stroke-width="2" stroke-linecap="round"/></svg></span>';

        if ( strpos( $feature, '[+]' ) === 0 ) {
            $icon = $svg_check;
            $text = trim( substr( $feature, 3 ) );
        } elseif ( strpos( $feature, '[-]' ) === 0 ) {
            $icon = $svg_cross;
            $text = trim( substr( $feature, 3 ) );
        } elseif ( in_array( substr( $feature, 0, 1 ), array( '+', '✓' ), true ) ) {
            $icon = $svg_check;
            $text = trim( substr( $feature, 1 ) );
        } elseif ( in_array( substr( $feature, 0, 1 ), array( '-', '✗' ), true ) ) {
            $icon = $svg_cross;
            $text = trim( substr( $feature, 1 ) );
        } else {
            $icon = $svg_neutral;
        }

        return $icon . esc_html( $text );
    }

    /**
     * Parse feature into structured array for JSON output (used by Shadow DOM JS).
     *
     * @param string $feature Raw feature string.
     * @return array { 'text' => string, 'type' => 'check'|'cross'|'neutral' }
     */
    private static function parse_feature_data( $feature ) {
        $feature = trim( $feature );
        $type    = 'neutral';
        $text    = $feature;

        if ( strpos( $feature, '[+]' ) === 0 ) {
            $type = 'check';
            $text = trim( substr( $feature, 3 ) );
        } elseif ( strpos( $feature, '[-]' ) === 0 ) {
            $type = 'cross';
            $text = trim( substr( $feature, 3 ) );
        } elseif ( in_array( substr( $feature, 0, 1 ), array( '+', '✓' ), true ) ) {
            $type = 'check';
            $text = trim( substr( $feature, 1 ) );
        } elseif ( in_array( substr( $feature, 0, 1 ), array( '-', '✗' ), true ) ) {
            $type = 'cross';
            $text = trim( substr( $feature, 1 ) );
        }

        return array(
            'text' => $text,
            'type' => $type,
        );
    }

    /**
     * Render the shortcode.
     *
     * Outputs a lightweight container with plan data as JSON.
     * The JavaScript attaches Shadow DOM and renders the full UI.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id'         => 0,
            'title'      => '',
            'show_title' => 'true',
        ), $atts, 'skyhshoso_hosting_plan' );

        $product_id = absint( $atts['id'] );
        if ( ! $product_id ) {
            return '<p style="color:#60768a;font-size:14px;">' . esc_html__( 'No product ID specified. Use [skyhshoso_hosting_plan id="PRODUCT_ID"]', 'skyhs-hosting-solution' ) . '</p>';
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            return '<p style="color:#60768a;font-size:14px;">' . esc_html__( 'Product not found.', 'skyhs-hosting-solution' ) . '</p>';
        }

        $product_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );
        if ( ! in_array( $product_type, array( 'skyhshoso_hosting', 'skyhshoso_wp_site', 'hosting' ), true ) ) {
            return '<p style="color:#60768a;font-size:14px;">' . esc_html__( 'This product is not a hosting product.', 'skyhs-hosting-solution' ) . '</p>';
        }

        // Build structured data for JS rendering.
        $data = self::build_plan_data( $product, $atts );

        // Output container + JSON data block.
        $container_id = 'skyhshoso-plan-' . $product_id;
        $json         = wp_json_encode( $data );

        return '<style>'
             . '.skyhshoso-plan-shadow-host, .skyhshoso-plan-shadow-host:focus, .skyhshoso-plan-shadow-host:focus-within, .skyhshoso-plan-shadow-host:active {'
             . ' outline: none !important; border: 0 !important; box-shadow: none !important;'
             . '}'
             . '</style>'
             . '<div id="' . esc_attr( $container_id ) . '" class="skyhshoso-plan-shadow-host"></div>'
             . '<script type="application/json" data-skyhshoso-plan="' . esc_attr( $container_id ) . '">' . $json . '</script>';
    }

    /**
     * Build structured plan data array.
     *
     * @param WC_Product $product     The WooCommerce product.
     * @param array      $atts        Shortcode attributes.
     * @return array
     */
    private static function build_plan_data( $product, $atts ) {
        $product_id   = $product->get_id();
        $display_title = ! empty( $atts['title'] ) ? $atts['title'] : $product->get_name();

        // Parent-level features.
        $features_raw = get_post_meta( $product_id, '_skyhshoso_hosting_features', true );
        if ( is_array( $features_raw ) ) {
            $features_raw = implode( "\n", $features_raw );
        }
        $features_arr = $features_raw ? array_filter( array_map( 'trim', preg_split( '/\r?\n/', $features_raw ) ) ) : array();
        $features     = array_values( array_map( array( self::class, 'parse_feature_data' ), $features_arr ) );

        $product_type = get_post_meta( $product_id, '_skyhshoso_product_type', true );
        $is_wp_site   = ( 'skyhshoso_wp_site' === $product_type );

        $data = array(
            'id'         => $product_id,
            'title'      => $display_title,
            'show_title' => filter_var( $atts['show_title'], FILTER_VALIDATE_BOOLEAN ),
            'type'       => $product->get_type(), // simple | variable
        );

        if ( $product->is_type( 'simple' ) ) {
            $data['plans'] = array( self::build_simple_plan( $product, $features, $is_wp_site ) );

        } elseif ( $product->is_type( 'variable' ) ) {
            $period_groups = array();
            $plans         = array();
            $is_sub        = SkyHSHOSO_Subscriptions_Product::is_subscription( $product );

            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation || ! $variation->is_purchasable() ) {
                    continue;
                }

                $v_period_label = '';
                $slug           = '';
                $v_trial_length = 0;
                $v_trial_period = '';

                if ( $is_sub ) {
                    $interval = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $variation );
                    $period   = SkyHSHOSO_Subscriptions_Product::get_period( $variation );
                    if ( $period ) {
                        $slug = $interval . '-' . $period;
                        if ( ! isset( $period_groups[ $slug ] ) ) {
                            if ( 'year' === $period ) {
                                $label = ( 1 === $interval ) ? __( 'Yearly', 'skyhs-hosting-solution' ) : sprintf( __( 'Every %d Years', 'skyhs-hosting-solution' ), $interval );
                            } elseif ( 'month' === $period ) {
                                $label = ( 1 === $interval ) ? __( 'Monthly', 'skyhs-hosting-solution' ) : sprintf( __( 'Every %d Months', 'skyhs-hosting-solution' ), $interval );
                            } else {
                                $label = sprintf( __( 'Every %1$d %2$s', 'skyhs-hosting-solution' ), $interval, ucfirst( $period ) );
                            }
                            $period_groups[ $slug ] = $label;
                        }
                        $v_period_label = '';
                    }
                    $v_trial_length = (int) SkyHSHOSO_Subscriptions_Product::get_trial_length( $variation );
                    $v_trial_period = SkyHSHOSO_Subscriptions_Product::get_trial_period( $variation );
                }

                // Variation-level features (fall back to parent).
                $v_features_raw = get_post_meta( $variation_id, '_skyhshoso_hosting_features', true );
                if ( is_array( $v_features_raw ) ) {
                    $v_features_raw = implode( "\n", $v_features_raw );
                }
                $v_features_arr = $v_features_raw ? array_filter( array_map( 'trim', preg_split( '/\r?\n/', $v_features_raw ) ) ) : $features_arr;
                $v_features     = array_values( array_map( array( self::class, 'parse_feature_data' ), $v_features_arr ) );

                if ( $is_wp_site ) {
                    $v_wp_storage = get_post_meta( $variation_id, '_skyhshoso_wp_storage', true );
                    if ( empty( $v_wp_storage ) ) {
                        $v_wp_storage = get_post_meta( $product_id, '_skyhshoso_wp_storage', true );
                    }
                    if ( empty( $v_wp_storage ) ) {
                        $v_wp_storage = 500;
                    }

                    $v_wp_memory = get_post_meta( $variation_id, '_skyhshoso_wp_memory', true );
                    if ( empty( $v_wp_memory ) ) {
                        $v_wp_memory = get_post_meta( $product_id, '_skyhshoso_wp_memory', true );
                    }
                    if ( empty( $v_wp_memory ) ) {
                        $v_wp_memory = '64M';
                    }

                    $storage_feat = self::parse_feature_data( '[+] ' . self::format_storage( $v_wp_storage ) );
                    $memory_feat  = self::parse_feature_data( '[+] ' . sprintf( __( '%s PHP Memory', 'skyhs-hosting-solution' ), $v_wp_memory ) );

                    array_unshift( $v_features, $storage_feat, $memory_feat );
                }

                // Variation name — strip parent product name prefix.
                $variation_name = $variation->get_name();
                $product_name   = $product->get_name();
                if ( strpos( $variation_name, $product_name ) === 0 ) {
                    $variation_name = trim( str_replace( $product_name, '', $variation_name ), ' -' );
                }

                // Format the variation name: replace hyphens, capitalize words, and enforce WordPress capitalization.
                $variation_name = str_replace( '-', ' ', $variation_name );
                $variation_name = ucwords( $variation_name );
                $variation_name = str_ireplace( 'wordpress', 'WordPress', $variation_name );

                $plans[] = array(
                    'id'           => $variation->get_id(),
                    'name'         => $variation_name,
                    'price_html'   => wp_kses_post( $variation->get_price_html() ),
                    'period_label' => $v_period_label,
                    'period_group' => $slug,
                    'features'     => $v_features,
                    'buy_url'      => esc_url( add_query_arg( 'add-to-cart', $variation->get_id(), wc_get_checkout_url() ) ),
                    'on_sale'      => $variation->is_on_sale(),
                    'trial_length' => $v_trial_length,
                    'trial_period' => $v_trial_period ? ucfirst( $v_trial_period ) . ( $v_trial_length > 1 ? 's' : '' ) : '',
                );
            }

            $data['period_groups'] = array();
            foreach ( $period_groups as $slug => $label ) {
                $data['period_groups'][] = array( 'slug' => $slug, 'label' => $label );
            }
            $data['plans'] = $plans;

        } else {
            // Fallback: simple-like rendering.
            $data['plans'] = array( self::build_simple_plan( $product, $features, $is_wp_site ) );
        }

        // i18n strings for JS.
        $data['i18n'] = array(
            'buy'        => __( 'Buy Now', 'skyhs-hosting-solution' ),
            'sale'       => __( 'Sale', 'skyhs-hosting-solution' ),
            'free_trial' => __( 'Free Trial', 'skyhs-hosting-solution' ),
        );

        return $data;
    }

    /**
     * Helper to format storage value dynamically from MB to GB if >= 1024.
     */
    private static function format_storage( $mb ) {
        $mb = intval( $mb );
        if ( $mb >= 1024 ) {
            $gb = floatval( round( $mb / 1024, 2 ) );
            return sprintf( __( '%s GB Storage', 'skyhs-hosting-solution' ), $gb );
        }
        return sprintf( __( '%s MB Storage', 'skyhs-hosting-solution' ), $mb );
    }

    /**
     * Build plan data for a simple (non-variable) product.
     */
    private static function build_simple_plan( $product, $features, $is_wp_site = false ) {
        $s_period       = SkyHSHOSO_Subscriptions_Product::get_period( $product );
        $s_interval     = (int) SkyHSHOSO_Subscriptions_Product::get_interval( $product );
        $s_is_sub       = SkyHSHOSO_Subscriptions_Product::is_subscription( $product );
        $s_period_label = '';
        $s_trial_length = (int) SkyHSHOSO_Subscriptions_Product::get_trial_length( $product );
        $s_trial_period = SkyHSHOSO_Subscriptions_Product::get_trial_period( $product );

        if ( $is_wp_site ) {
            $wp_storage = get_post_meta( $product->get_id(), '_skyhshoso_wp_storage', true );
            $wp_memory  = get_post_meta( $product->get_id(), '_skyhshoso_wp_memory', true );

            if ( empty( $wp_storage ) ) {
                $wp_storage = 500;
            }
            if ( empty( $wp_memory ) ) {
                $wp_memory = '64M';
            }

            $storage_feat = self::parse_feature_data( '[+] ' . self::format_storage( $wp_storage ) );
            $memory_feat  = self::parse_feature_data( '[+] ' . sprintf( __( '%s PHP Memory', 'skyhs-hosting-solution' ), $wp_memory ) );

            array_unshift( $features, $storage_feat, $memory_feat );
        }

        return array(
            'id'           => $product->get_id(),
            'name'         => $product->get_name(),
            'price_html'   => wp_kses_post( $product->get_price_html() ),
            'period_label' => $s_period_label,
            'period_group' => '',
            'features'     => $features,
            'buy_url'      => esc_url( add_query_arg( 'add-to-cart', $product->get_id(), wc_get_checkout_url() ) ),
            'on_sale'      => $product->is_on_sale(),
            'trial_length' => $s_trial_length,
            'trial_period' => $s_trial_period ? ucfirst( $s_trial_period ) . ( $s_trial_length > 1 ? 's' : '' ) : '',
        );
    }
}

SkyHSHOSO_Product_Shortcode::init();
