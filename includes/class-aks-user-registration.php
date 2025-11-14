<?php
/**
 * Handle Gravity Forms User Registration integration for Form ID 2
 * - Force User Registration feed to run synchronously
 * - Update field 32 with the created WordPress user ID
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

		// Modify confirmation for Form 2 to perform the redirect with the real user_id.
		add_filter( 'gform_confirmation_2', array( $this, 'modify_confirmation_redirect' ), 10, 4 );
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
	}

	/**
	 * Modify confirmation redirect to include populated query parameters.
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

		$entry_id = rgar( $entry, 'id' );

		// Prefer the user_id we captured during gform_user_registered.
		$user_id = null;
		if ( $entry_id && isset( self::$created_user_ids[ $entry_id ] ) ) {
			$user_id = self::$created_user_ids[ $entry_id ];
		}

		// Fallback to entry field 32 (in case something unexpected happens).
		if ( empty( $user_id ) ) {
			$user_id = rgar( $entry, '32' );
		}

		// Get name + email fields from the entry.
		$fname = rgar( $entry, '3.3' );
		$lname = rgar( $entry, '3.6' );
		$email = rgar( $entry, '4' );

		// Build redirect URL. Let add_query_arg handle encoding.
		$redirect_url = add_query_arg(
			array(
				'fname'           => $fname,
				'lname'           => $lname,
				'applicant_email' => $email,
				'user_id'         => $user_id,
			),
			site_url( '/complete-registration/' )
		);

		// Return a redirect-style confirmation so GF (and its AJAX handler)
		// can handle the top-window redirect correctly.
		return array(
			'redirect' => $redirect_url,
		);
	}
}

new AKS_User_Registration_Handler();
