<?php
/**
 * @since 3.0.0
 * @package Stripe/Admin
 * @author PaymentPlugins
 *
 */
class WC_Stripe_Admin_User_Edit {

	public static function init() {
		add_action ( 'edit_user_profile', array( 
				__CLASS__, 'output' 
		) );
		add_action ( 'show_user_profile', array( 
				__CLASS__, 'output' 
		) );
		add_action ( 'edit_user_profile_update', array( 
				__CLASS__, 'save' 
		) );
		add_action ( 'personal_options_update', array( 
				__CLASS__, 'save' 
		) );
	}

	/**
	 *
	 * @param WP_User $user        	
	 */
	public static function output($user) {
		// enquue scripts
		wp_enqueue_style ( 'wc-stripe-admin-style' );
		
		remove_filter ( 'woocommerce_get_customer_payment_tokens', 'wc_stripe_get_customer_payment_tokens' );
		// get payment methods for all environments.
		$tokens = WC_Payment_Tokens::get_customer_tokens ( $user->ID );
		$payment_methods = array( 'live' => array(), 
				'test' => array() 
		);
		foreach ( $tokens as $token ) {
			if ($token instanceof WC_Payment_Token_Stripe) {
				if ('live' === $token->get_environment ()) {
					$payment_methods[ 'live' ][] = $token;
				} else {
					$payment_methods[ 'test' ][] = $token;
				}
			}
		}
		include wc_stripe ()->plugin_path () . 'includes/admin/views/html-user-profile.php';
	}

	/**
	 *
	 * @param int $user_id        	
	 */
	public static function save($user_id) {
		$modes = array( 'test', 'live' 
		);
		if (isset ( $_POST[ 'wc_stripe_live_id' ] )) {
			$old_live_id = wc_stripe_get_customer_id ( $user_id, 'live' );
			wc_stripe_save_customer ( wc_clean ( $_POST[ 'wc_stripe_live_id' ] ), $user_id, 'live' );
		}
		if (isset ( $_POST[ 'wc_stripe_test_id' ] )) {
			$old_test_id = wc_stripe_get_customer_id ( $user_id, 'test' );
			wc_stripe_save_customer ( wc_clean ( $_POST[ 'wc_stripe_test_id' ] ), $user_id, 'test' );
		}
		
		// check if admin want's to delete any payment methods
		foreach ( $modes as $mode ) {
			if (isset ( $_POST[ $mode . '_payment_method_actions' ] )) {
				switch (wc_clean ( $_POST[ $mode . '_payment_method_actions' ] )) {
					case 'delete' :
						if (isset ( $_POST[ 'payment_methods' ], $_POST[ 'payment_methods' ][ $mode ] )) {
							$tokens = $_POST[ 'payment_methods' ][ $mode ];
							foreach ( $tokens as $token_id ) {
								WC_Payment_Tokens::delete ( absint ( $token_id ) );
							}
						}
						break;
				}
			}
		}
		
		$changes = array( 
				'live' => $old_live_id !== wc_stripe_get_customer_id ( $user_id, 'live' ), 
				'test' => $old_test_id !== wc_stripe_get_customer_id ( $user_id, 'test' ) 
		);
		$gateway = WC_Stripe_Gateway::load ();
		
		// this will prevent the payment method from being deleted in Stripe. We only want to remove the tokens
		// from the WC tables.
		remove_action ( 'woocommerce_payment_token_deleted', 'wc_stripe_woocommerce_payment_token_deleted', 10 );
		
		// if the value has changed, then remove old payment methods and import new ones.
		foreach ( $changes as $mode => $change ) {
			if ($change) {
				// Delete all current payment methods in WC then save new ones.
				$tokens = WC_Payment_Tokens::get_customer_tokens ( $user_id );
				foreach ( $tokens as $token ) {
					if ($token instanceof WC_Payment_Token_Stripe) {
						if ($mode === $token->get_environment ()) {
							WC_Payment_Tokens::delete ( $token->get_id () );
						}
					}
				}
				// import payment methods from Stripe.
				WC_Stripe_Customer_Manager::sync_payment_methods ( wc_stripe_get_customer_id ( $user_id, $mode ), $user_id );
			}
		}
	}
}
WC_Stripe_Admin_User_Edit::init ();