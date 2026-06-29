<?php
/**
 * SkyHS Invoice View
 *
 * Renders a clean HTML invoice page for WooCommerce orders linked
 * to subscriptions. Access via ?skyhshoso_invoice=1&order_id={id}.
 * Uses CSS @media print for PDF-like output.
 *
 * @package Hosting_Solution
 */

defined( 'ABSPATH' ) || exit;

class SkyHSHOSO_Invoice {

    /**
     * Register query var and template redirect.
     */
    public static function init() {
        add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
        add_action( 'template_redirect', array( self::class, 'render_invoice' ) );
    }

    /**
     * Add custom query vars.
     *
     * @param array $vars
     * @return array
     */
    public static function add_query_vars( $vars ) {
        $vars[] = 'skyhshoso_invoice';
        $vars[] = 'order_id';
        return $vars;
    }

    /**
     * Render invoice page when query var is set.
     */
    public static function render_invoice() {
        $invoice = get_query_var( 'skyhshoso_invoice' );
        if ( ! $invoice ) {
            return;
        }

        $order_id = (int) get_query_var( 'order_id' );
        if ( ! $order_id ) {
            wp_die( esc_html__( 'No order specified.', 'skyhs-hosting-solution' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'skyhs-hosting-solution' ) );
        }

        // Security: only the order customer or admins can view.
        $current_user_id = get_current_user_id();
        if ( ! $current_user_id || ( $order->get_customer_id() !== $current_user_id && ! current_user_can( 'administrator' ) ) ) {
            wp_die( esc_html__( 'You do not have permission to view this invoice.', 'skyhs-hosting-solution' ) );
        }

        self::output_invoice( $order );
        exit;
    }

    /**
     * Output the HTML invoice page.
     *
     * @param WC_Order $order
     */
    private static function output_invoice( $order ) {
        $company_name = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_invoice_company_name() : get_bloginfo( 'name' );
        $company_addr = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_invoice_address() : '';
        $invoice_footer_text = class_exists( 'SkyHSHOSO_Settings' ) ? SkyHSHOSO_Settings::get_invoice_footer() : '';
        $site_name   = $company_name;
        $site_url    = get_bloginfo( 'url' );
        $order_date  = $order->get_date_created() ? date_i18n( get_option( 'date_format' ), $order->get_date_created()->getTimestamp() ) : '—';
        $due_date    = $order->get_date_paid() ? date_i18n( get_option( 'date_format' ), $order->get_date_paid()->getTimestamp() ) : $order_date;
        $items       = $order->get_items();
        $subtotal    = $order->get_subtotal();
        $total       = $order->get_total();
        $tax_total   = $order->get_total_tax();
        $currency    = $order->get_currency();
        $status      = ucwords( $order->get_status() );

        // Get billing details.
        $billing = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
            'company'    => $order->get_billing_company(),
            'address_1'  => $order->get_billing_address_1(),
            'address_2'  => $order->get_billing_address_2(),
            'city'       => $order->get_billing_city(),
            'state'      => $order->get_billing_state(),
            'postcode'   => $order->get_billing_postcode(),
            'country'    => $order->get_billing_country(),
            'email'      => $order->get_billing_email(),
            'phone'      => $order->get_billing_phone(),
        );

        $payment_method = $order->get_payment_method_title();
        $order_number   = $order->get_order_number();

        $country_name = $billing['country'];
        if ( $billing['country'] && function_exists( 'WC' ) && isset( WC()->countries ) && method_exists( WC()->countries, 'get_countries' ) ) {
            $countries = WC()->countries->get_countries();
            if ( isset( $countries[ $billing['country'] ] ) ) {
                $country_name = $countries[ $billing['country'] ];
            }
        }

        // Format address.
        $billing_lines = array_filter( array(
            $billing['first_name'] . ' ' . $billing['last_name'],
            $billing['company'],
            $billing['address_1'],
            $billing['address_2'],
            $billing['city'] . ( $billing['state'] ? ', ' . $billing['state'] : '' ) . ' ' . $billing['postcode'],
            $country_name,
        ) );
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php printf( esc_html__( 'Invoice #%s — %s', 'skyhs-hosting-solution' ), esc_html( $order_number ), esc_html( $site_name ) ); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                    color: #1d2327;
                    background: #f0f0f1;
                    padding: 40px 20px;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                }
                .invoice-wrapper {
                    max-width: 800px;
                    margin: 0 auto;
                    background: #fff;
                    border-radius: 8px;
                    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
                    overflow: hidden;
                }
                .invoice-header {
                    background: #2271b1;
                    color: #fff;
                    padding: 40px;
                }
                .invoice-header h1 {
                    font-size: 28px;
                    font-weight: 700;
                    margin: 0 0 4px 0;
                }
                .invoice-header .invoice-meta {
                    display: flex;
                    justify-content: space-between;
                    flex-wrap: wrap;
                    gap: 10px;
                    margin-top: 16px;
                    font-size: 14px;
                    opacity: 0.9;
                }
                .invoice-body {
                    padding: 40px;
                }
                .invoice-addr-row {
                    display: flex;
                    justify-content: space-between;
                    gap: 40px;
                    margin-bottom: 32px;
                }
                .invoice-addr-box {
                    flex: 1;
                }
                .invoice-addr-box h3 {
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #646970;
                    margin-bottom: 8px;
                }
                .invoice-addr-box p {
                    font-size: 14px;
                    line-height: 1.6;
                    color: #1d2327;
                }
                table.invoice-items {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 24px 0;
                }
                table.invoice-items th {
                    text-align: left;
                    padding: 10px 12px;
                    font-size: 12px;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                    color: #646970;
                    border-bottom: 2px solid #e5e5e5;
                }
                table.invoice-items th.qty-col,
                table.invoice-items th.price-col {
                    text-align: right;
                }
                table.invoice-items td {
                    padding: 12px;
                    border-bottom: 1px solid #f0f0f1;
                    font-size: 14px;
                }
                table.invoice-items td.qty-col,
                table.invoice-items td.price-col {
                    text-align: right;
                }
                table.invoice-items tr:last-child td {
                    border-bottom: none;
                }
                .invoice-totals {
                    margin-left: auto;
                    width: 280px;
                    margin-top: 16px;
                }
                .invoice-totals table {
                    width: 100%;
                    border-collapse: collapse;
                }
                .invoice-totals td {
                    padding: 6px 0;
                    font-size: 14px;
                }
                .invoice-totals td.label {
                    color: #646970;
                }
                .invoice-totals td.amount {
                    text-align: right;
                }
                .invoice-totals .total-row td {
                    font-weight: 700;
                    font-size: 16px;
                    padding-top: 12px;
                    border-top: 2px solid #1d2327;
                }
                .invoice-footer {
                    text-align: center;
                    padding: 24px 40px;
                    font-size: 13px;
                    color: #646970;
                    border-top: 1px solid #f0f0f1;
                }
                .invoice-footer p { margin: 4px 0; }
                .status-badge {
                    display: inline-block;
                    padding: 3px 10px;
                    border-radius: 12px;
                    font-size: 12px;
                    font-weight: 600;
                }
                .status-completed { background: #e6f2e8; color: #007017; }
                .status-processing { background: #eaf0fa; color: #043959; }
                .status-pending { background: #fcf9e8; color: #996800; }
                .status-failed { background: #f7e8e8; color: #b32d2e; }
                .status-cancelled { background: #f0f0f1; color: #50575e; }
                .no-print { text-align: center; margin-bottom: 16px; }
                .no-print .print-btn {
                    display: inline-block;
                    padding: 10px 24px;
                    background: #2271b1;
                    color: #fff;
                    text-decoration: none;
                    border-radius: 6px;
                    font-size: 14px;
                    font-weight: 500;
                    border: none;
                    cursor: pointer;
                }
                .no-print .print-btn:hover { background: #135e96; }
                @media print {
                    body { background: #fff; padding: 0; }
                    .invoice-wrapper { box-shadow: none; border-radius: 0; }
                    .no-print { display: none; }
                    .invoice-header { background: #2271b1 !important; }
                }
            </style>
        </head>
        <body>
            <div class="no-print">
                <button class="print-btn" onclick="window.print()">
                    <?php esc_html_e( 'Print / Save PDF', 'skyhs-hosting-solution' ); ?>
                </button>
            </div>
            <div class="invoice-wrapper">
                <div class="invoice-header">
                    <h1><?php esc_html_e( 'Invoice', 'skyhs-hosting-solution' ); ?></h1>
                    <div class="invoice-meta">
                        <span>
                            <?php esc_html_e( 'Invoice #', 'skyhs-hosting-solution' ); ?><?php echo esc_html( $order_number ); ?>
                        </span>
                        <span>
                            <?php esc_html_e( 'Date:', 'skyhs-hosting-solution' ); ?> <?php echo esc_html( $order_date ); ?>
                        </span>
                        <span>
                            <?php esc_html_e( 'Status:', 'skyhs-hosting-solution' ); ?>
                            <span class="status-badge status-<?php echo esc_attr( $order->get_status() ); ?>">
                                <?php echo esc_html( $status ); ?>
                            </span>
                        </span>
                    </div>
                </div>
                <div class="invoice-body">
                    <div class="invoice-addr-row">
                        <div class="invoice-addr-box">
                            <h3><?php esc_html_e( 'Bill To', 'skyhs-hosting-solution' ); ?></h3>
                            <p>
                                <?php echo implode( '<br>', array_map( 'esc_html', $billing_lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </p>
                        </div>
                        <div class="invoice-addr-box">
                            <h3><?php esc_html_e( 'Payment', 'skyhs-hosting-solution' ); ?></h3>
                            <p>
                                <?php echo esc_html( $payment_method ?: '—' ); ?><br>
                                <?php esc_html_e( 'Paid on:', 'skyhs-hosting-solution' ); ?> <?php echo esc_html( $due_date ); ?>
                            </p>
                        </div>
                    </div>

                    <table class="invoice-items">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Item', 'skyhs-hosting-solution' ); ?></th>
                                <th class="qty-col"><?php esc_html_e( 'Qty', 'skyhs-hosting-solution' ); ?></th>
                                <th class="price-col"><?php esc_html_e( 'Amount', 'skyhs-hosting-solution' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $items as $item ) : ?>
                            <tr>
                                <td><?php echo esc_html( $item->get_name() ); ?></td>
                                <td class="qty-col"><?php echo esc_html( $item->get_quantity() ); ?></td>
                                <td class="price-col"><?php echo wp_kses_post( wc_price( $item->get_total(), array( 'currency' => $currency ) ) ); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="invoice-totals">
                        <table>
                            <tr>
                                <td class="label"><?php esc_html_e( 'Subtotal', 'skyhs-hosting-solution' ); ?></td>
                                <td class="amount"><?php echo wp_kses_post( wc_price( $subtotal, array( 'currency' => $currency ) ) ); ?></td>
                            </tr>
                            <?php if ( $tax_total > 0 ) : ?>
                            <tr>
                                <td class="label"><?php esc_html_e( 'Tax', 'skyhs-hosting-solution' ); ?></td>
                                <td class="amount"><?php echo wp_kses_post( wc_price( $tax_total, array( 'currency' => $currency ) ) ); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr class="total-row">
                                <td class="label"><?php esc_html_e( 'Total', 'skyhs-hosting-solution' ); ?></td>
                                <td class="amount"><?php echo wp_kses_post( wc_price( $total, array( 'currency' => $currency ) ) ); ?></td>
                            </tr>
                        </table>
                    </div>
                </div>
                <div class="invoice-footer">
                    <p><strong><?php echo esc_html( $company_name ); ?></strong></p>
                    <?php if ( $company_addr ) : ?>
                        <p><?php echo nl2br( esc_html( $company_addr ) ); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html( $site_url ); ?></p>
                    <?php if ( $invoice_footer_text ) : ?>
                        <p style="margin-top:8px;font-style:italic;"><?php echo esc_html( $invoice_footer_text ); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Get the invoice URL for a given order.
     *
     * @param WC_Order|int $order
     * @return string
     */
    public static function get_invoice_url( $order ) {
        $order_id = is_object( $order ) ? $order->get_id() : (int) $order;
        $page_id  = get_option( 'skyhshoso_dashboard_page_id', 0 );

        if ( $page_id ) {
            return add_query_arg( array(
                'skyhshoso_invoice' => 1,
                'order_id'          => $order_id,
            ), get_permalink( $page_id ) );
        }

        return home_url( '/?' . http_build_query( array(
            'skyhshoso_invoice' => 1,
            'order_id'          => $order_id,
        ) ) );
    }
}
