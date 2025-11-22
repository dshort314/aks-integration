<?php
/**
 * Handle Gravity Forms User Registration integration for Form ID 2
 * - Force User Registration feed to run synchronously
 * - Update field 32 with the created WordPress user ID
 * - Automatically log in the user after registration
 * - Redirect via confirmation (works with AJAX)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AKS_User_Registration_Handler {

	/**
	 * Map of entry_id => created user_id for this request.
	 *
	 * @var array
	 */
	private static $created_user_ids = array();

	public function __construct() {

		// Ensure the User Registration feed for Form 2 is processed synchronously.
		add_filter( 'gform_is_feed_asynchronous', array( $this, 'force_sync_user_reg_feed' ), 10, 4 );

		// After user is created, store user_id and update entry field 32.
		add_action( 'gform_user_registered', array( $this, 'handle_user_registered' ), 10, 4 );

		// Automatically log in the user after registration.
		add_action( 'gform_user_registered', array( $this, 'auto_login_user' ), 20, 4 );

		// Modify confirmation for Form 2 to perform the redirect with the real user_id.
		add_filter( 'gform_confirmation_2', array( $this, 'modify_confirmation_redirect' ), 10, 4 );

		// Restrict Form 3 to logged-in users only.
		add_filter( 'gform_pre_render_3', array( $this, 'restrict_form_to_logged_in_users' ) );
		add_filter( 'gform_pre_validation_3', array( $this, 'restrict_form_validation' ) );
	}

	/**
	 * Force the User Registration feed for Form 2 to run synchronously
	 * (instead of background/async processing).
	 *
	 * @param bool  $is_asynchronous
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 *
	 * @return bool
	 */
	public function force_sync_user_reg_feed( $is_asynchronous, $feed, $entry, $form ) {

		// Only affect the GF User Registration add-on for Form ID 2.
		if (
			isset( $feed['addon_slug'] ) &&
			$feed['addon_slug'] === 'gravityformsuserregistration' &&
			intval( rgar( $form, 'id' ) ) === 2
		) {
			return false; // process synchronously
		}

		return $is_asynchronous;
	}

	/**
	 * After the user is created, update entry field 32 and record user_id for this entry.
	 *
	 * @param int    $user_id   Newly created WP user ID.
	 * @param array  $feed      User Registration feed configuration.
	 * @param array  $entry     Gravity Forms entry array.
	 * @param string $user_pass User password.
	 */
	public function handle_user_registered( $user_id, $feed, $entry, $user_pass ) {

		// Only for Form ID 2.
		if ( intval( $feed['form_id'] ) !== 2 ) {
			return;
		}

		$entry_id = rgar( $entry, 'id' );

		// Store user_id for this entry for use in confirmation.
		if ( $entry_id && $user_id ) {
			self::$created_user_ids[ $entry_id ] = $user_id;

			// Also update field 32 in the database, as originally required.
			GFAPI::update_entry_field( $entry_id, 32, $user_id );
		}

		// Save phone number to user profile
		$phone = rgar( $entry, '5' ); // Field 5 is the phone number
		if ( ! empty( $phone ) ) {
			// Format phone number as (999) 999-9999 if it's 10 digits
			$digits = preg_replace( '/\D+/', '', $phone );
			
			if ( strlen( $digits ) === 10 ) {
				$formatted_phone = sprintf(
					'(%s) %s-%s',
					substr( $digits, 0, 3 ),
					substr( $digits, 3, 3 ),
					substr( $digits, 6, 4 )
				);
			} else {
				$formatted_phone = $phone;
			}
			
			// Save to billing_phone (used by WooCommerce and displayed in profile)
			update_user_meta( $user_id, 'billing_phone', $formatted_phone );
			
			error_log( 'AKS User Registration: Saved phone number ' . $formatted_phone . ' to user ' . $user_id );
		}
	}

	/**
	 * Automatically log in the user after registration.
	 *
	 * @param int    $user_id   Newly created WP user ID.
	 * @param array  $feed      User Registration feed configuration.
	 * @param array  $entry     Gravity Forms entry array.
	 * @param string $user_pass User password.
	 */
	public function auto_login_user( $user_id, $feed, $entry, $user_pass ) {

		// Only for Form ID 2.
		if ( intval( $feed['form_id'] ) !== 2 ) {
			return;
		}

		// Only proceed if user_id is valid
		if ( ! $user_id ) {
			error_log( 'AKS Auto-Login: No user_id provided' );
			return;
		}

		// Log the user in
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );

		// Trigger the wp_login action for compatibility with other plugins
		do_action( 'wp_login', wp_get_current_user()->user_login, wp_get_current_user() );

		error_log( 'AKS Auto-Login: User ' . $user_id . ' has been automatically logged in' );
	}

	/**
	 * Modify confirmation redirect to go to My Account page.
	 * This runs after submission and plays nicely with AJAX submissions.
	 *
	 * @param mixed $confirmation
	 * @param array $form
	 * @param array $entry
	 * @param bool  $ajax
	 *
	 * @return mixed
	 */
	public function modify_confirmation_redirect( $confirmation, $form, $entry, $ajax ) {

		// Only process for Form ID 2.
		if ( intval( $form['id'] ) !== 2 ) {
			return $confirmation;
		}

		// Redirect to My Account page (/account/)
		$redirect_url = site_url( '/account/' );

		// Return a redirect-style confirmation so GF (and its AJAX handler)
		// can handle the top-window redirect correctly.
		return array(
			'redirect' => $redirect_url,
		);
	}

	/**
	 * Restrict Form 3 to logged-in users only (display).
	 *
	 * @param array $form The form object.
	 *
	 * @return array Modified form or false to prevent display.
	 */
	public function restrict_form_to_logged_in_users( $form ) {
		// Check if user is NOT logged in
		if ( ! is_user_logged_in() ) {
			// Return a message instead of the form
			add_filter( 'gform_get_form_filter_3', array( $this, 'show_login_required_message' ), 10, 2 );
		}

		return $form;
	}

	/**
	 * Show login required message instead of form.
	 *
	 * @param string $form_string The form HTML.
	 * @param array  $form        The form object.
	 *
	 * @return string Modified HTML or login message.
	 */
	public function show_login_required_message( $form_string, $form ) {
		// Use WooCommerce My Account page if available, otherwise default WordPress login
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$login_url = wc_get_page_permalink( 'myaccount' );
		} else {
			$login_url = wp_login_url( get_permalink() );
		}

		return '<div class="gform_wrapper" style="padding: 20px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px;">
			<h3>Login Required</h3>
			<p>You must be logged in to access this form.</p>
			<p><a href="' . esc_url( $login_url ) . '" class="button">Login</a></p>
		</div>';
	}

	/**
	 * Restrict Form 3 validation to logged-in users only (prevent submission).
	 *
	 * @param array $form The form object.
	 *
	 * @return array Modified form.
	 */
	public function restrict_form_validation( $form ) {
		// Check if user is NOT logged in
		if ( ! is_user_logged_in() ) {
			// Add a validation error to prevent submission
			foreach ( $form['fields'] as &$field ) {
				$field->failed_validation  = true;
				$field->validation_message = 'You must be logged in to submit this form.';
				break; // Only need to fail one field
			}
		}

		return $form;
	}
}

new AKS_User_Registration_Handler();