<?php
/**
 * Pay for order form displayed after a customer has clicked the "Change Payment method" button
 * next to a subscription on their My Account page.
 *
 * @package Hosting_Solution/Templates
 * @version 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
?>
<form id="order_review" method="post">

	<table class="shop_table">
		<thead>
			<tr>
				<th class="product-name"><?php echo esc_html_x( 'Product', 'table headings in notification email', 'skyhs-hosting-solution' ); ?></th>
				<th class="product-quantity"><?php echo esc_html_x( 'Quantity', 'table headings in notification email', 'skyhs-hosting-solution' ); ?></th>
				<th class="product-total"><?php echo esc_html_x( 'Totals', 'table headings in notification email', 'skyhs-hosting-solution' ); ?></th>
			</tr>
		</thead>
		<tfoot>
		<?php foreach ( $subscription->get_order_item_totals() as $total ) : ?>
			<tr>
				<th scope="row" colspan="2"><?php echo esc_html( $total['label'] ); ?></th>
				<td class="product-total"><?php echo wp_kses_post( $total['value'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tfoot>
		<tbody>
		<?php foreach ( $subscription->get_items() as $item ) : ?>
			<tr>
				<td class="product-name"><?php echo esc_html( $item['name'] ); ?></td>
				<td class="product-quantity"><?php echo esc_html( $item['qty'] ); ?></td>
				<td class="product-subtotal"><?php echo wp_kses_post( $subscription->get_formatted_line_subtotal( $item ) ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div id="payment">
		<?php
		if ( $subscription->has_payment_gateway() ) {
			$pay_order_button_text = _x( 'Change payment method', 'text on button on checkout page', 'skyhs-hosting-solution' );
		} else {
			$pay_order_button_text = _x( 'Add payment method', 'text on button on checkout page', 'skyhs-hosting-solution' );
		}

		$pay_order_button_text     = apply_filters( 'woocommerce_change_payment_button_text', $pay_order_button_text );
		$customer_subscription_ids = SkyHSHOSO_Subscription_DB::query( array( 'customer_id' => $subscription->get_customer_id() ) );
		$payment_gateways_handler  = 'SkyHSHOSO_Payment_Gateways';
		$available_gateways        = WC()->payment_gateways->get_available_payment_gateways();

		if ( $available_gateways ) :
			?>
			<ul class="payment_methods methods">
				<?php

				if ( count( $available_gateways ) ) {
					current( $available_gateways )->set_current();
				}

				foreach ( $available_gateways as $gateway ) :
					$supports_payment_method_changes = SkyHSHOSO_Change_Payment_Gateway::can_update_all_subscription_payment_methods( $gateway, $subscription );
					?>
					<li class="wc_payment_method payment_method_<?php echo esc_attr( $gateway->id ); ?>">
						<input id="payment_method_<?php echo esc_attr( $gateway->id ); ?>" type="radio" class="input-radio <?php echo $supports_payment_method_changes ? 'supports-payment-method-changes' : ''; ?>" name="payment_method" value="<?php echo esc_attr( $gateway->id ); ?>" <?php checked( $gateway->chosen, true ); ?> data-order_button_text="<?php echo esc_attr( apply_filters( 'skyhshoso_gateway_change_payment_button_text', $pay_order_button_text, $gateway ) ); ?>"/>
						<label for="payment_method_<?php echo esc_attr( $gateway->id ); ?>"><?php echo esc_html( $gateway->get_title() ); ?><?php echo wp_kses_post( $gateway->get_icon() ); ?></label>
						<?php
						if ( $gateway->has_fields() || $gateway->get_description() ) {
							echo '<div class="payment_box payment_method_' . esc_attr( $gateway->id ) . '" style="display:none;">';
							$gateway->payment_fields();
							echo '</div>';
						}
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<div class="woocommerce-error">
				<p> <?php echo esc_html( apply_filters( 'woocommerce_no_available_payment_methods_message', __( 'Sorry, it seems no payment gateways support changing the recurring payment method. Please contact us if you require assistance or to make alternate arrangements.', 'skyhs-hosting-solution' ) ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $available_gateways ) : ?>
			<?php if ( count( $customer_subscription_ids ) > 1 && $payment_gateways_handler::one_gateway_supports( 'subscription_payment_method_change_admin' ) ) : ?>
			<span class="update-all-subscriptions-payment-method-wrap">
				<?php
				// translators: $1: opening <strong> tag, $2: closing </strong> tag
				$label = sprintf( esc_html__( 'Use this payment method for %1$sall%2$s of my current subscriptions', 'skyhs-hosting-solution' ), '<strong>', '</strong>' );

				woocommerce_form_field(
					'update_all_subscriptions_payment_method',
					array(
						'type'     => 'checkbox',
						'class'    => array( 'form-row-wide' ),
						'label'    => $label,
						'required' => true, // Making the field required to help make it more prominent on the page.
						'default'  => apply_filters( 'skyhshoso_update_all_subscriptions_payment_method_checked', true ),
					)
				);
				?>
			</span>
			<?php endif; ?>
		<div class="form-row">
			<?php wp_nonce_field( 'skyhshoso_change_payment_method', '_skyhshosononce', true, true ); ?>

			<?php do_action( 'skyhshoso_subscriptions_change_payment_before_submit' ); ?>

			<?php
			echo wp_kses(
				apply_filters( 'woocommerce_change_payment_button_html', '<input type="submit" class="button alt" id="place_order" value="' . esc_attr( $pay_order_button_text ) . '" data-value="' . esc_attr( $pay_order_button_text ) . '" />' ),
				array(
					'input' => array(
						'type'       => array(),
						'class'      => array(),
						'id'         => array(),
						'value'      => array(),
						'data-value' => array(),
					),
				)
			);
			?>

			<?php do_action( 'skyhshoso_subscriptions_change_payment_after_submit' ); ?>

			<input type="hidden" name="woocommerce_change_payment" value="<?php echo esc_attr( $subscription->get_id() ); ?>" />
		</div>
		<?php endif; ?>

	</div>

</form>
