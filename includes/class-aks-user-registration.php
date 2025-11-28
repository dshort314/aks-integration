<?php
/**
 * Handle Gravity Forms User Registration integration for Form ID 2
 * - Force User Registration feed to run synchronously
 * - Update field 32 with the created WordPress user ID
 * - Automatically log in the user after registration
 * - Redirect via confirmation (works with AJAX)
 * - Store entry IDs in user meta for forms 2 and 3
 * - Link Form 1 (student) submissions to parent Form 3 entry
 * - Trigger DocuSeal regeneration on student changes
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

		// Store entry ID in user meta for Form 2 (after submission)
		add_action( 'gform_after_submission_2', array( $this, 'store_form_2_entry_id' ), 10, 2 );

		// Store entry ID in user meta for Form 3 (after submission)
		add_action( 'gform_after_submission_3', array( $this, 'store_form_3_entry_id' ), 10, 2 );

		// Link Form 1 (student) submissions to parent Form 3 entry
		add_filter( 'gform_entry_post_save_1', array( $this, 'link_student_to_parent' ), 10, 2 );

		// Trigger DocuSeal regeneration when Form 1 is submitted (new student added)
		add_action( 'gform_after_submission_1', array( $this, 'trigger_docuseal_on_student_add' ), 10, 2 );

		// Trigger DocuSeal regeneration when Form 3 entry is updated (student info changed)
		add_action( 'gform_after_update_entry', array( $this, 'trigger_docuseal_on_student_change' ), 10, 2 );
		
		// Trigger DocuSeal regeneration when Form 1 (student) is updated
		add_action( 'gform_after_update_entry', array( $this, 'trigger_docuseal_on_student_edit' ), 10, 2 );
		
		// Trigger DocuSeal regeneration when Form 1 (student) is deleted
		add_action( 'gform_after_delete_entry', array( $this, 'trigger_docuseal_on_student_delete' ), 10, 2 );
		
		// Debug: Log ALL entry deletions
		add_action( 'gform_after_delete_entry', array( $this, 'debug_entry_delete' ), 5, 2 );
		
		// Debug: Log ALL entry updates to verify hook is firing
		add_action( 'gform_after_update_entry', array( $this, 'debug_entry_update' ), 5, 2 );

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
	 * Store Form 2 entry ID in user meta
	 *
	 * @param array $entry The entry object
	 * @param array $form  The form object
	 */
	public function store_form_2_entry_id( $entry, $form ) {
		// Get user ID from field 32 (set during user registration)
		$user_id = rgar( $entry, '32' );
		$entry_id = rgar( $entry, 'id' );

		if ( empty( $user_id ) || empty( $entry_id ) ) {
			error_log( 'AKS Entry Tracking: Could not store Form 2 entry ID - User ID: ' . $user_id . ', Entry ID: ' . $entry_id );
			return;
		}

		// Store entry ID in user meta
		update_user_meta( $user_id, 'aks_form_1_entry_id', $entry_id );
		error_log( 'AKS Entry Tracking: Stored Form 2 (labeled Form 1) entry ID ' . $entry_id . ' for user ' . $user_id );
	}

	/**
	 * Store Form 3 entry ID in user meta
	 *
	 * @param array $entry The entry object
	 * @param array $form  The form object
	 */
	public function store_form_3_entry_id( $entry, $form ) {
		// Get email from field 29
		$email = rgar( $entry, '29' );
		$entry_id = rgar( $entry, 'id' );

		if ( empty( $email ) || empty( $entry_id ) ) {
			error_log( 'AKS Entry Tracking: Could not store Form 3 entry ID - Email: ' . $email . ', Entry ID: ' . $entry_id );
			return;
		}

		// Get user by email
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			error_log( 'AKS Entry Tracking: User not found for email: ' . $email );
			return;
		}

		// Store entry ID in user meta
		update_user_meta( $user->ID, 'aks_form_2_entry_id', $entry_id );
		error_log( 'AKS Entry Tracking: Stored Form 3 (labeled Form 2) entry ID ' . $entry_id . ' for user ' . $user->ID );
	}

	/**
	 * Link Form 1 (student) submission to parent Form 3 entry
	 *
	 * @param array $entry The entry object
	 * @param array $form  The form object
	 *
	 * @return array Modified entry
	 */
	public function link_student_to_parent( $entry, $form ) {
		// Only process Form 1
		if ( intval( $form['id'] ) !== 1 ) {
			return $entry;
		}

		// Get current user
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			error_log( 'AKS Student Linking: User not logged in' );
			return $entry;
		}

		// Get parent Form 3 entry ID from user meta
		$parent_entry_id = get_user_meta( $user_id, 'aks_form_2_entry_id', true );
		if ( empty( $parent_entry_id ) ) {
			error_log( 'AKS Student Linking: No parent entry ID found for user ' . $user_id );
			return $entry;
		}

		// Check if GPNF class exists
		if ( ! class_exists( 'GPNF_Entry' ) ) {
			error_log( 'AKS Student Linking: GPNF_Entry class not found' );
			return $entry;
		}

		// Set the parent entry properties
		$entry = array_replace( array(
			GPNF_Entry::ENTRY_PARENT_KEY            => $parent_entry_id,  // Parent entry ID from user profile
			GPNF_Entry::ENTRY_PARENT_FORM_KEY       => 3,                  // Form 3
			GPNF_Entry::ENTRY_NESTED_FORM_FIELD_KEY => 21,                 // Field 21
		), $entry );

		// Update the entry
		GFAPI::update_entry( $entry );

		error_log( 'AKS Student Linking: Linked Form 1 entry ' . $entry['id'] . ' to Form 3 entry ' . $parent_entry_id );

		return $entry;
	}

	/**
	 * Trigger DocuSeal regeneration when Form 1 is submitted (new student added)
	 *
	 * @param array $entry The entry object
	 * @param array $form  The form object
	 */
	public function trigger_docuseal_on_student_add( $entry, $form ) {
		error_log('AKS DocuSeal Trigger: Form 1 submission detected, entry ID: ' . rgar($entry, 'id'));
		
		// Get current user
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			error_log( 'AKS DocuSeal Trigger: User not logged in' );
			return;
		}
		
		error_log('AKS DocuSeal Trigger: User ID: ' . $user_id);

		// Get parent Form 3 entry ID
		$parent_entry_id = get_user_meta( $user_id, 'aks_form_2_entry_id', true );
		if ( empty( $parent_entry_id ) ) {
			error_log( 'AKS DocuSeal Trigger: No parent entry ID found for user ' . $user_id );
			return;
		}
		
		error_log('AKS DocuSeal Trigger: Parent Form 3 entry ID: ' . $parent_entry_id);

		// Get the parent entry
		$parent_entry = GFAPI::get_entry( $parent_entry_id );
		if ( is_wp_error( $parent_entry ) ) {
			error_log( 'AKS DocuSeal Trigger: Could not retrieve parent entry ' . $parent_entry_id );
			return;
		}
		
		error_log('AKS DocuSeal Trigger: Retrieved parent entry, field 21 value: "' . rgar($parent_entry, '21') . '"');

		error_log( 'AKS DocuSeal Trigger: Student added, regenerating DocuSeal for user ' . $user_id );

		// Trigger DocuSeal regeneration
		$this->regenerate_docuseal( $parent_entry, $user_id );
	}

	/**
	 * Debug: Log all entry deletions
	 *
	 * @param int   $entry_id The entry ID being deleted
	 * @param array $entry    The entry being deleted
	 */
	public function debug_entry_delete( $entry_id, $entry ) {
		error_log('=== AKS DEBUG: gform_after_delete_entry fired ===');
		error_log('Entry ID: ' . $entry_id);
		error_log('Form ID: ' . rgar($entry, 'form_id'));
		error_log('=== END DEBUG ===');
	}

	/**
	 * Debug: Log all entry updates to verify hook is firing
	 *
	 * @param array $entry    The updated entry
	 * @param int   $entry_id The entry ID (yes, redundant but that's how GF does it)
	 */
	public function debug_entry_update( $entry, $entry_id ) {
		// Fetch fresh entry to get all data
		$fresh_entry = GFAPI::get_entry( $entry_id );
		
		error_log('=== AKS DEBUG: gform_after_update_entry fired ===');
		error_log('Entry ID: ' . $entry_id);
		error_log('Fresh entry form_id: ' . rgar($fresh_entry, 'form_id'));
		
		// If Form 1, log the name fields
		if ( rgar($fresh_entry, 'form_id') == 1 ) {
			error_log('Form 1 - First Name (1.3): "' . rgar($fresh_entry, '1.3') . '"');
			error_log('Form 1 - Last Name (1.6): "' . rgar($fresh_entry, '1.6') . '"');
			error_log('Form 1 - DOB (2): "' . rgar($fresh_entry, '2') . '"');
		}
		
		error_log('Fresh entry field 21: "' . rgar($fresh_entry, '21') . '"');
		error_log('=== END DEBUG ===');
	}

	/**
	 * Trigger DocuSeal regeneration when Form 3 entry is updated (student info changed)
	 *
	 * @param array $entry    The updated entry
	 * @param int   $entry_id The entry ID
	 */
	public function trigger_docuseal_on_student_change( $entry, $entry_id ) {
		// Fetch fresh entry to get form_id
		$fresh_entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $fresh_entry ) ) {
			error_log('AKS DocuSeal Trigger: Could not fetch entry ' . $entry_id);
			return;
		}
		
		$form_id = rgar($fresh_entry, 'form_id');
		error_log('AKS DocuSeal Trigger: gform_after_update_entry fired for form ' . $form_id);
		
		// Only watch Form 3
		if ( intval( $form_id ) !== 3 ) {
			error_log('AKS DocuSeal Trigger: Not Form 3 (form ' . $form_id . '), skipping');
			return;
		}

		// Get students from field 21
		$students = rgar( $fresh_entry, '21' );
		
		error_log('AKS DocuSeal Trigger: Form 3 updated, field 21 value: "' . $students . '"');

		// Get user by email from field 29
		$email = rgar( $fresh_entry, '29' );
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			error_log( 'AKS DocuSeal Trigger: User not found for email: ' . $email );
			return;
		}

		error_log('AKS DocuSeal Trigger: Found user ' . $user->ID . ', calling regenerate_docuseal');

		// Trigger DocuSeal regeneration
		$this->regenerate_docuseal( $fresh_entry, $user->ID );
	}

	/**
	 * Trigger DocuSeal regeneration when Form 1 (student) entry is updated
	 * Only triggers on changes to first name, last name, or birthdate
	 *
	 * @param array $entry    The updated entry
	 * @param int   $entry_id The entry ID
	 */
	public function trigger_docuseal_on_student_edit( $entry, $entry_id ) {
		// Fetch fresh entry to get form_id
		$fresh_entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $fresh_entry ) ) {
			return;
		}
		
		$form_id = rgar($fresh_entry, 'form_id');
		
		// Only watch Form 1 (student form)
		if ( intval( $form_id ) !== 1 ) {
			return;
		}

		error_log('AKS DocuSeal Trigger: Form 1 (student) updated, entry ID: ' . $entry_id);

		// Check if GPNF class exists and get parent entry info
		if ( ! class_exists( 'GPNF_Entry' ) ) {
			error_log( 'AKS DocuSeal Trigger: GPNF_Entry class not found' );
			return;
		}

		// Get parent entry ID from GPNF meta
		$parent_entry_id = gform_get_meta( $entry_id, GPNF_Entry::ENTRY_PARENT_KEY );
		
		if ( empty( $parent_entry_id ) ) {
			error_log('AKS DocuSeal Trigger: No parent entry found for student entry ' . $entry_id);
			return;
		}

		error_log('AKS DocuSeal Trigger: Found parent entry ID: ' . $parent_entry_id);

		// Get the parent Form 3 entry
		$parent_entry = GFAPI::get_entry( $parent_entry_id );
		if ( is_wp_error( $parent_entry ) ) {
			error_log( 'AKS DocuSeal Trigger: Could not retrieve parent entry ' . $parent_entry_id );
			return;
		}

		// Get user by email from parent entry
		$email = rgar( $parent_entry, '29' );
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			error_log( 'AKS DocuSeal Trigger: User not found for email: ' . $email );
			return;
		}

		error_log('AKS DocuSeal Trigger: Student name/DOB edited, regenerating DocuSeal for user ' . $user->ID);

		// Trigger DocuSeal regeneration
		$this->regenerate_docuseal( $parent_entry, $user->ID );
	}

	/**
	 * Trigger DocuSeal regeneration when Form 1 (student) entry is deleted
	 *
	 * @param int   $entry_id The entry ID
	 * @param array $entry    The entry that was deleted
	 */
	public function trigger_docuseal_on_student_delete( $entry_id, $entry ) {
		// Check if this is a Form 1 (student) entry
		$form_id = rgar($entry, 'form_id');
		
		if ( intval( $form_id ) !== 1 ) {
			return;
		}

		error_log('AKS DocuSeal Trigger: Form 1 (student) deleted, entry ID: ' . $entry_id);

		// Check if GPNF class exists and get parent entry info
		if ( ! class_exists( 'GPNF_Entry' ) ) {
			error_log( 'AKS DocuSeal Trigger: GPNF_Entry class not found' );
			return;
		}

		// Get parent entry ID from GPNF meta (need to get it before entry is fully deleted)
		$parent_entry_id = gform_get_meta( $entry_id, GPNF_Entry::ENTRY_PARENT_KEY );
		
		if ( empty( $parent_entry_id ) ) {
			error_log('AKS DocuSeal Trigger: No parent entry found for deleted student entry ' . $entry_id);
			return;
		}

		error_log('AKS DocuSeal Trigger: Found parent entry ID: ' . $parent_entry_id);

		// Get the parent Form 3 entry
		$parent_entry = GFAPI::get_entry( $parent_entry_id );
		if ( is_wp_error( $parent_entry ) ) {
			error_log( 'AKS DocuSeal Trigger: Could not retrieve parent entry ' . $parent_entry_id );
			return;
		}

		// Get user by email from parent entry
		$email = rgar( $parent_entry, '29' );
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			error_log( 'AKS DocuSeal Trigger: User not found for email: ' . $email );
			return;
		}

		error_log('AKS DocuSeal Trigger: Student deleted, regenerating DocuSeal for user ' . $user->ID);

		// Trigger DocuSeal regeneration
		$this->regenerate_docuseal( $parent_entry, $user->ID );
	}

	/**
	 * Regenerate DocuSeal template and send for signature
	 *
	 * @param array $entry   The Form 3 entry
	 * @param int   $user_id The WordPress user ID
	 */
	private function regenerate_docuseal( $entry, $user_id ) {
		$entry_id = rgar($entry, 'id');
		
		// Clear any Gravity Forms caches
		if ( class_exists( 'GFCache' ) ) {
			GFCache::delete( 'entry_' . $entry_id );
		}
		
		// Force fresh retrieval of the entry to ensure we have latest student data
		$fresh_entry = GFAPI::get_entry( $entry_id );
		
		if ( is_wp_error( $fresh_entry ) ) {
			error_log('AKS DocuSeal Regeneration: Could not retrieve fresh entry ' . $entry_id);
			return;
		}
		
		// Query database directly to get all nested student entries
		global $wpdb;
		$entry_meta_table = GFFormsModel::get_entry_meta_table_name();
		
		if ( class_exists( 'GPNF_Entry' ) ) {
			$query = $wpdb->prepare(
				"SELECT entry_id FROM {$entry_meta_table} 
				WHERE meta_key = %s AND meta_value = %s",
				GPNF_Entry::ENTRY_PARENT_KEY,
				$entry_id
			);
			
			$student_entry_ids = $wpdb->get_col( $query );
			
			error_log('AKS DocuSeal Regeneration: Found ' . count($student_entry_ids) . ' student entries via DB query: ' . implode(', ', $student_entry_ids));
			
			// Update field 21 with all student entry IDs
			if ( ! empty( $student_entry_ids ) ) {
				$fresh_entry['21'] = implode( ',', $student_entry_ids );
				error_log('AKS DocuSeal Regeneration: Updated fresh entry field 21 to: "' . $fresh_entry['21'] . '"');
			}
		} else {
			error_log('AKS DocuSeal Regeneration: GPNF_Entry class not found, using field 21 as-is: "' . rgar($fresh_entry, '21') . '"');
		}
		
		// Reset waiver status
		update_user_meta( $user_id, 'sr_waiver_signed', 'no' );
		error_log( 'AKS DocuSeal Regeneration: Reset waiver status for user ' . $user_id );

		// Get the DocuSeal integration class
		if ( ! class_exists( 'AKS_DocuSeal_Integration' ) ) {
			require_once AKS_INTEGRATION_PLUGIN_DIR . 'includes/docuseal/class-docuseal-integration.php';
		}

		// Create instance and trigger document generation
		$docuseal = new AKS_DocuSeal_Integration();
		
		// Get the form object
		$form = GFAPI::get_form( 3 );
		
		// Call the send_to_docuseal method with fresh entry
		$docuseal->send_to_docuseal( $fresh_entry, $form );

		error_log( 'AKS DocuSeal Regeneration: Triggered DocuSeal generation for user ' . $user_id );
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