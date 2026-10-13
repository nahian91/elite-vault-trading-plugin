<?php
/**
 * EVG Module: Submissions & Orders (Pro Edition)
 * Comprehensive order lifecycle manager, declared card ledger, 10-stage pipeline routing,
 * manual certificate assignment (starting at 0100), manual order entry for staff/cash,
 * delete functionality, DataTables integration, automated fee computation, and status management.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST/GET submission actions (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_submissions_lifecycle_actions' );

function evg_handle_submissions_lifecycle_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_submissions' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team', 'head_grader' ) ) ) {
		return;
	}

	global $wpdb;

	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';
	$evg_table_cards       = $wpdb->prefix . 'evg_cards';
	$evg_table_faults      = $wpdb->prefix . 'evg_fault_images';
	$evg_table_assessments = $wpdb->prefix . 'evg_assessments';

	// ---------------------------------------------------------
	// 1. Delete Entire Submission
	// ---------------------------------------------------------
	if ( isset( $_GET['action'], $_GET['sub_id'] ) && 'delete_submission' === $_GET['action'] ) {
		$evg_del_sub_id = absint( $_GET['sub_id'] );
		check_admin_referer( 'evg_delete_submission_' . $evg_del_sub_id );

		// Fetch all cards belonging to this submission to delete related sub-records.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$evg_del_cards = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$evg_table_cards} WHERE submission_id = %d", $evg_del_sub_id ) );

		if ( ! empty( $evg_del_cards ) ) {
			$evg_card_placeholders = implode( ',', array_fill( 0, count( $evg_del_cards ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_faults} WHERE card_id IN ($evg_card_placeholders)", $evg_del_cards ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_assessments} WHERE card_id IN ($evg_card_placeholders)", $evg_del_cards ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_cards} WHERE submission_id = %d", $evg_del_sub_id ) );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_submissions} WHERE id = %d", $evg_del_sub_id ) );

		if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
			Elite_Vault_Grading_System::log_activity( "Deleted Submission ID #{$evg_del_sub_id} and all related card ledgers." );
		}

		evg_submissions_safe_redirect( admin_url( 'admin.php?page=evg_tab_submissions&deleted=1' ) );
	}

	// ---------------------------------------------------------
	// 2. Delete Individual Card from Submission
	// ---------------------------------------------------------
	if ( isset( $_GET['action'], $_GET['card_id'], $_GET['submission_id'] ) && 'delete_card' === $_GET['action'] ) {
		$evg_del_card_id = absint( $_GET['card_id'] );
		$evg_parent_sub  = absint( $_GET['submission_id'] );
		check_admin_referer( 'evg_delete_card_' . $evg_del_card_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_faults} WHERE card_id = %d", $evg_del_card_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_assessments} WHERE card_id = %d", $evg_del_card_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_cards} WHERE id = %d", $evg_del_card_id ) );

		// Update total count on parent submission.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$evg_new_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$evg_table_cards} WHERE submission_id = %d", $evg_parent_sub ) );
		$wpdb->update(
			$evg_table_submissions,
			array( 'total_cards' => $evg_new_count ),
			array( 'id' => $evg_parent_sub ),
			array( '%d' ),
			array( '%d' )
		);

		if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
			Elite_Vault_Grading_System::log_activity( "Deleted Card ID #{$evg_del_card_id} from Submission ID #{$evg_parent_sub}." );
		}

		evg_submissions_safe_redirect( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_parent_sub . '&card_deleted=1' ) );
	}

	// ---------------------------------------------------------
	// 3. Update Existing Submission Lifecycle & Metadata
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_update_submission_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_update_submission_nonce'] ) ), 'evg_update_submission' ) ) {

			$evg_sub_id           = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
			$evg_new_stage        = isset( $_POST['current_stage'] ) ? sanitize_text_field( wp_unslash( $_POST['current_stage'] ) ) : '';
			$evg_payment_status   = isset( $_POST['payment_status'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_status'] ) ) : '';
			$evg_tracking         = isset( $_POST['return_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['return_tracking'] ) ) : '';
			$evg_service_type     = isset( $_POST['service_type'] ) ? sanitize_text_field( wp_unslash( $_POST['service_type'] ) ) : 'Standard';
			$evg_label_option     = isset( $_POST['label_option'] ) ? sanitize_text_field( wp_unslash( $_POST['label_option'] ) ) : 'Black Basic';
			$evg_delivery_type    = isset( $_POST['fulfilment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['fulfilment_method'] ) ) : 'postage';
			$evg_submission_notes = isset( $_POST['submission_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['submission_notes'] ) ) : '';
			$evg_declared_value   = isset( $_POST['declared_insurance_value'] ) ? (float) wp_unslash( $_POST['declared_insurance_value'] ) : 0.00;

			$evg_update_data = array(
				'current_stage'    => $evg_new_stage,
				'payment_status'   => $evg_payment_status,
				'return_tracking'  => $evg_tracking,
				'service_type'     => $evg_service_type,
				'label_option'     => $evg_label_option,
				'fulfilment_type'  => $evg_delivery_type,
				'submission_notes' => $evg_submission_notes,
				'declared_value'   => $evg_declared_value,
			);
			$evg_update_formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f' );

			if ( isset( $_POST['total_amount'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['total_amount'] ) ) ) ) {
				$evg_update_data['total_amount'] = number_format( (float) wp_unslash( $_POST['total_amount'], 2, '.', '' ) );
				$evg_update_formats[]            = '%s';
			}

			$wpdb->update(
				$evg_table_submissions,
				$evg_update_data,
				array( 'id' => $evg_sub_id ),
				$evg_update_formats,
				array( '%d' )
			);

			// Sync all underlying card statuses if order marked completed or returned.
			if ( in_array( $evg_new_stage, array( 'Completed', 'Returned To Customer' ), true ) ) {
				$wpdb->update(
					$evg_table_cards,
					array( 'grading_status' => 'Completed' ),
					array( 'submission_id' => $evg_sub_id ),
					array( '%s' ),
					array( '%d' )
				);
			}

			if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
				Elite_Vault_Grading_System::log_activity( "Updated Order ID {$evg_sub_id} to stage: {$evg_new_stage} (Payment: {$evg_payment_status})" );
			}

			evg_submissions_safe_redirect( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_sub_id . '&updated=1' ) );
		}
	}

	// ---------------------------------------------------------
	// 4. Add New Card to Existing Submission
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_add_card_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_add_card_nonce'] ) ), 'evg_add_card_to_submission' ) ) {
			$evg_sub_id          = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
			$evg_card_name       = isset( $_POST['card_name'] ) ? sanitize_text_field( wp_unslash( $_POST['card_name'] ) ) : '';
			$evg_set_name        = isset( $_POST['set_name'] ) ? sanitize_text_field( wp_unslash( $_POST['set_name'] ) ) : '';
			$evg_card_number     = isset( $_POST['card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['card_number'] ) ) : '';
			$evg_cert_number     = isset( $_POST['cert_number'] ) ? sanitize_text_field( wp_unslash( $_POST['cert_number'] ) ) : '0100';
			$evg_language        = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'English';
			$evg_final_grade     = ( isset( $_POST['final_grade'] ) && '' !== $_POST['final_grade'] ) ? absint( $_POST['final_grade'] ) : null;
			$evg_front_image_url = isset( $_POST['front_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['front_image_url'] ) ) : '';
			$evg_back_image_url  = isset( $_POST['back_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['back_image_url'] ) ) : '';
			$evg_grading_status  = isset( $_POST['grading_status'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_status'] ) ) : 'Pending';

			if ( ! empty( $evg_card_name ) && ! empty( $evg_set_name ) && $evg_sub_id > 0 ) {
				$wpdb->insert(
					$evg_table_cards,
					array(
						'submission_id'   => $evg_sub_id,
						'card_name'       => $evg_card_name,
						'set_name'        => $evg_set_name,
						'card_number'     => $evg_card_number,
						'cert_number'     => $evg_cert_number,
						'language'        => $evg_language,
						'final_grade'     => $evg_final_grade,
						'front_image_url' => $evg_front_image_url,
						'back_image_url'  => $evg_back_image_url,
						'grading_status'  => $evg_grading_status,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', ( null === $evg_final_grade ? null : '%d' ), '%s', '%s', '%s' )
				);

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$evg_sub_record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$evg_table_submissions} WHERE id = %d", $evg_sub_id ) );

				if ( $evg_sub_record ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$evg_card_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$evg_table_cards} WHERE submission_id = %d", $evg_sub_id ) );
					$evg_base_rate  = (float) get_option( 'evg_price_standard', 9.99 );

					$evg_label_fee = 0.00;
					if ( false !== stripos( $evg_sub_record->label_option, 'extended' ) ) {
						$evg_label_fee = 2.99;
					} elseif ( false !== stripos( $evg_sub_record->label_option, 'colour' ) || false !== stripos( $evg_sub_record->label_option, 'lightening' ) ) {
						$evg_label_fee = 0.99;
					}

					$evg_speed_fee = ( false !== stripos( $evg_sub_record->service_type, 'express' ) || false !== stripos( $evg_sub_record->service_type, 'fast' ) ) ? 4.99 : 0.00;

					if ( 'Internal Free (Staff)' === $evg_sub_record->payment_status ) {
						$evg_recalculated_total = 0.00;
					} else {
						$evg_is_collection      = ( isset( $evg_sub_record->fulfilment_type ) && 'collection' === $evg_sub_record->fulfilment_type ) || false !== stripos( $evg_sub_record->service_type, 'collection' );
						$evg_shipping           = $evg_is_collection ? 0.00 : (float) get_option( 'evg_return_shipping_fee', 6.99 );
						$evg_recalculated_total = ( $evg_card_count * ( $evg_base_rate + $evg_label_fee + $evg_speed_fee ) ) + $evg_shipping;
					}

					$wpdb->update(
						$evg_table_submissions,
						array(
							'total_cards'  => $evg_card_count,
							'total_amount' => number_format( $evg_recalculated_total, 2, '.', '' ),
						),
						array( 'id' => $evg_sub_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);

					if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
						Elite_Vault_Grading_System::log_activity( "Added card ({$evg_card_name}) with EVG Card ID: {$evg_cert_number} to Submission ID {$evg_sub_id}." );
					}
				}

				evg_submissions_safe_redirect( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_sub_id . '&card_added=1' ) );
			}
		}
	}

	// ---------------------------------------------------------
	// 5. Manual Order Creation
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_manual_order_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_manual_order_nonce'] ) ), 'evg_create_manual_order' ) ) {
			$evg_customer_name  = isset( $_POST['manual_customer_name'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_customer_name'] ) ) : 'Staff Consignment';
			$evg_customer_email = isset( $_POST['manual_customer_email'] ) ? sanitize_email( wp_unslash( $_POST['manual_customer_email'] ) ) : 'internal@elitevaultgrading.com';
			$evg_order_type     = isset( $_POST['manual_order_type'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_order_type'] ) ) : 'Internal Free (Staff)';
			$evg_label_tier     = isset( $_POST['manual_label_tier'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_label_tier'] ) ) : 'Black Basic';
			$evg_service_speed  = isset( $_POST['manual_service_speed'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_service_speed'] ) ) : 'Standard';
			$evg_initial_cert   = isset( $_POST['manual_cert_number'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_cert_number'] ) ) : '0100';
			$evg_card_name      = isset( $_POST['manual_card_name'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_card_name'] ) ) : '';
			$evg_card_set       = isset( $_POST['manual_card_set'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_card_set'] ) ) : '';
			$evg_card_number    = isset( $_POST['manual_card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_card_number'] ) ) : '';
			$evg_custom_amount  = isset( $_POST['manual_total_amount'] ) ? (float) wp_unslash( $_POST['manual_total_amount'] ) : 0.00;
			$evg_delivery_type  = isset( $_POST['manual_fulfilment_type'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_fulfilment_type'] ) ) : 'postage';

			$evg_order_ref = 'EVG-' . strtoupper( wp_generate_password( 6, false, false ) );

			$wpdb->insert(
				$evg_table_submissions,
				array(
					'order_number'    => $evg_order_ref,
					'customer_id'     => get_current_user_id(),
					'submission_date' => current_time( 'mysql' ),
					'total_cards'     => 1,
					'service_type'    => $evg_service_speed,
					'label_option'    => $evg_label_tier,
					'fulfilment_type' => $evg_delivery_type,
					'current_stage'   => 'Registry Check-In',
					'payment_status'  => ( 'Internal Free (Staff)' === $evg_order_type ) ? 'Internal Free (Staff)' : 'Paid (Cash)',
					'total_amount'    => number_format( $evg_custom_amount, 2, '.', '' ),
				),
				array( '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			$evg_new_sub_id = $wpdb->insert_id;

			if ( $evg_new_sub_id && ! empty( $evg_card_name ) ) {
				$wpdb->insert(
					$evg_table_cards,
					array(
						'submission_id'  => $evg_new_sub_id,
						'card_name'      => $evg_card_name,
						'set_name'       => $evg_card_set,
						'card_number'    => $evg_card_number,
						'cert_number'    => $evg_initial_cert,
						'language'       => 'English',
						'grading_status' => 'Pending',
					),
					array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			evg_submissions_safe_redirect( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_new_sub_id . '&manual_created=1' ) );
		}
	}
}

/**
 * Universal redirect helper that won't throw header warnings.
 *
 * @param string $location Destination URL.
 */
function evg_submissions_safe_redirect( $location ) {
	if ( ! headers_sent() ) {
		wp_safe_redirect( $location );
		exit;
	} else {
		echo '<script type="text/javascript">window.location.href="' . esc_url_raw( $location ) . '";</script>';
		echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $location ) . '"></noscript>';
		exit;
	}
}

/**
 * Main render function for the Submissions & Orders tab.
 */
function evg_submissions_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team', 'head_grader' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Submissions & Orders.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;

	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';
	$evg_table_cards       = $wpdb->prefix . 'evg_cards';

	// ---------------------------------------------------------
	// UI Notifications Display
	// ---------------------------------------------------------
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Submission order parameters successfully updated.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['card_added'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'New card unit added to submission successfully with certificate number.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['manual_created'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Manual order created and logged successfully.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff !important; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600; color:#fff !important;">' . esc_html__( 'Submission and all corresponding cards were permanently removed.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['card_deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Card unit was deleted from the submission ledger.', 'evg' ) . '</p></div>';
	}

	$evg_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	$evg_sub_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'view' === $evg_action && $evg_sub_id > 0 ) {
		evg_render_pro_single_submission( $evg_sub_id, $evg_table_submissions, $evg_table_cards );
	} else {
		evg_render_pro_submissions_list( $evg_table_submissions );
	}
}

/**
 * Render Submissions Queue with Integrated DataTables & Delete Controls.
 *
 * @param string $evg_table_submissions Submissions DB table name.
 */
function evg_render_pro_submissions_list( $evg_table_submissions ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_submissions = $wpdb->get_results(
		"SELECT s.*, u.user_email, u.display_name 
		FROM {$evg_table_submissions} s
		LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
		ORDER BY s.id DESC"
	);
	?>
	<style>
		:root {
			--evg-gold: #d4af37;
			--evg-gold-light: #f3e5ab;
			--evg-border: #222224;
			--evg-bg-card: #0f0f11;
			--evg-text-muted: #8e8e93;
			--evg-danger: #ff453a;
		}

		.evg-sub-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 24px;
			flex-wrap: wrap;
			gap: 15px;
		}
		.evg-sub-header h1 {
			font-size: 26px;
			font-weight: 800;
			margin: 0 0 6px 0;
			color: #ffffff;
			letter-spacing: -0.5px;
		}
		.evg-sub-header p {
			color: var(--evg-text-muted);
			font-size: 13px;
			margin: 0;
		}

		.evg-panel-shell {
			background: var(--evg-bg-card);
			border: 1px solid var(--evg-border);
			border-radius: 14px;
			overflow-x: auto;
			box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
			margin-bottom: 30px;
		}

		.evg-manual-box {
			background: #121215;
			border: 1px solid #29292d;
			border-radius: 12px;
			padding: 24px;
			margin-bottom: 30px;
			box-shadow: 0 10px 30px rgba(0,0,0,0.4);
		}
		.evg-manual-box h2 {
			color: var(--evg-gold);
			font-size: 14px;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			margin: 0 0 16px 0;
			font-weight: 800;
		}

		.evg-sub-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}
		.evg-sub-table thead th {
			background: #141416;
			color: var(--evg-text-muted);
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			padding: 16px 20px;
			border-bottom: 1px solid var(--evg-border);
			text-align: left;
			white-space: nowrap;
		}
		.evg-sub-table tbody td {
			padding: 18px 20px;
			border-bottom: 1px solid #1a1a1d;
			color: #ffffff;
			font-size: 13px;
			vertical-align: middle;
		}
		.evg-sub-table tbody tr {
			transition: background 0.2s ease;
		}
		.evg-sub-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.02);
		}
		.evg-sub-table tbody tr:last-child td {
			border-bottom: none;
		}

		.evg-tag-label {
			display: inline-block;
			background: #1c1c1f;
			border: 1px solid #2c2c30;
			color: var(--evg-gold);
			font-size: 11px;
			font-weight: 700;
			padding: 3px 8px;
			border-radius: 4px;
			margin-top: 4px;
		}

		.evg-stage-pill {
			display: inline-block;
			background: #18181a;
			border: 1px solid #2c2c2e;
			color: #ffffff;
			font-size: 11px;
			font-weight: 700;
			padding: 4px 10px;
			border-radius: 20px;
			white-space: nowrap;
		}
		.evg-stage-pill.active {
			border-color: rgba(212, 175, 55, 0.35);
			color: var(--evg-gold);
			background: rgba(212, 175, 55, 0.08);
		}
		.evg-stage-pill.done {
			border-color: rgba(52, 199, 89, 0.35);
			color: #34c759;
			background: rgba(52, 199, 89, 0.08);
		}

		.evg-pay-badge {
			display: inline-block;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.evg-pay-Paid { color: #34c759; }
		.evg-pay-Pending { color: #ff9f0a; }
		.evg-pay-Refunded { color: #ff453a; }
		.evg-pay-Partial { color: #0a84ff; }

		.evg-actions-flex {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			justify-content: flex-end;
		}
		.evg-btn-manage {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 7px 14px;
			border-radius: 6px;
			background: transparent;
			border: 1px solid var(--evg-border);
			color: #ffffff;
			font-size: 12px;
			font-weight: 600;
			text-decoration: none;
			transition: all 0.2s ease;
			white-space: nowrap;
		}
		.evg-btn-manage:hover {
			border-color: var(--evg-gold);
			color: var(--evg-gold);
			background: rgba(212, 175, 55, 0.1);
			transform: translateY(-1px);
		}

		.evg-btn-delete {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 7px 10px;
			border-radius: 6px;
			background: rgba(255, 69, 58, 0.08);
			border: 1px solid rgba(255, 69, 58, 0.25);
			color: var(--evg-danger);
			font-size: 12px;
			font-weight: 700;
			text-decoration: none;
			transition: all 0.2s ease;
			cursor: pointer;
		}
		.evg-btn-delete:hover {
			background: var(--evg-danger);
			color: #ffffff;
			border-color: var(--evg-danger);
			box-shadow: 0 0 10px rgba(255, 69, 58, 0.4);
		}

		.evg-grid-form {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 14px;
			margin-bottom: 14px;
		}
		.evg-form-field label {
			display: block;
			color: #8e8e93;
			font-size: 10px;
			text-transform: uppercase;
			font-weight: 700;
			margin-bottom: 5px;
			letter-spacing: 0.5px;
		}
		.evg-form-field input, 
		.evg-form-field select,
		.evg-field-control {
			width: 100%;
			background-color: #141416 !important;
			border: 1px solid #28282b !important;
			color: #ffffff !important;
			padding: 10px 14px;
			font-size: 12px;
			border-radius: 6px;
			box-sizing: border-box;
			outline: none;
			transition: border-color 0.2s ease, box-shadow 0.2s ease;
		}
		.evg-form-field select,
		.evg-field-control {
			appearance: none;
			-webkit-appearance: none;
			background-image: url("data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2214%22%20height%3D%2214%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%23d4af37%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%226%209%2012%2015%2018%209%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E") !important;
			background-repeat: no-repeat !important;
			background-position: right 14px center !important;
			background-size: 14px 14px !important;
			padding-right: 38px !important;
		}
		.evg-form-field input:focus, 
		.evg-form-field select:focus,
		.evg-field-control:focus {
			border-color: var(--evg-gold) !important;
			box-shadow: 0 0 0 1px var(--evg-gold), 0 0 10px rgba(212, 175, 55, 0.25) !important;
			background-color: #0d0d0f !important;
		}

		/* DataTables Dark & Gold Custom Theme Styling */
		.dataTables_wrapper {
			padding: 10px 0;
			color: #ffffff;
		}
		.dataTables_wrapper .dataTables_length,
		.dataTables_wrapper .dataTables_filter {
			padding: 15px 20px;
			color: var(--evg-text-muted);
			font-size: 12px;
		}
		.dataTables_wrapper .dataTables_filter input {
			background: #141416;
			border: 1px solid #28282b;
			color: #ffffff;
			border-radius: 6px;
			padding: 6px 12px;
			margin-left: 8px;
			outline: none;
		}
		.dataTables_wrapper .dataTables_filter input:focus {
			border-color: var(--evg-gold);
		}
		.dataTables_wrapper .dataTables_length select {
			background: #141416;
			border: 1px solid #28282b;
			color: #ffffff;
			border-radius: 6px;
			padding: 6px 10px;
			margin: 0 6px;
		}
		.dataTables_wrapper .dataTables_info {
			padding: 15px 20px;
			color: var(--evg-text-muted);
			font-size: 12px;
		}
		.dataTables_wrapper .dataTables_paginate {
			display: flex;
			align-items: center;
			justify-content: flex-end;
			gap: 4px;
			padding: 15px 20px;
		}
		.dataTables_wrapper .dataTables_paginate .paginate_button {
			background: #141416 !important;
			border: 1px solid #28282b !important;
			color: #8e8e93 !important;
			border-radius: 6px !important;
			padding: 6px 14px !important;
			font-size: 12px !important;
			font-family: monospace !important;
			font-weight: 700 !important;
			cursor: pointer !important;
			transition: all 0.2s ease !important;
		}
		.dataTables_wrapper .dataTables_paginate .paginate_button.current,
		.dataTables_wrapper .dataTables_paginate .paginate_button:hover {
			background: var(--evg-gold) !important;
			color: #0a0a0a !important;
			border-color: var(--evg-gold) !important;
		}
	</style>

	<div class="evg-sub-header">
		<div>
			<h1><?php esc_html_e( 'Submissions & Orders', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Audit collector submissions, process internal staff/cash orders, and route through the diagnostic pipeline.', 'evg' ); ?></p>
		</div>
		<div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
			<?php
			printf(
				/* translators: %s: Formatted count of cataloged submissions */
				esc_html__( '%s Orders Cataloged', 'evg' ),
				esc_html( number_format_i18n( count( $evg_submissions ) ) )
			);
			?>
		</div>
	</div>

	<!-- Manual Order Intake Panel -->
	<div class="evg-manual-box">
		<h2>+ <?php esc_html_e( 'Add Manual Order (Internal Staff & Cash Ledger)', 'evg' ); ?></h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'evg_create_manual_order', 'evg_manual_order_nonce' ); ?>

			<div class="evg-grid-form">
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Client / Staff Name *', 'evg' ); ?></label>
					<input type="text" name="manual_customer_name" placeholder="<?php esc_attr_e( 'e.g. EVG In-House / Walk-in', 'evg' ); ?>" required>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Client Email *', 'evg' ); ?></label>
					<input type="email" name="manual_customer_email" placeholder="<?php esc_attr_e( 'e.g. team@elitevaultgrading.com', 'evg' ); ?>" required>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Order Classification *', 'evg' ); ?></label>
					<select name="manual_order_type">
						<option value="Internal Free (Staff)"><?php esc_html_e( 'Internal Free (Staff Cards)', 'evg' ); ?></option>
						<option value="Cash Paid"><?php esc_html_e( 'Cash Paid Order', 'evg' ); ?></option>
					</select>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Custom Paid Amount (£)', 'evg' ); ?></label>
					<input type="number" step="0.01" name="manual_total_amount" value="0.00">
				</div>
			</div>

			<div class="evg-grid-form">
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Card Name *', 'evg' ); ?></label>
					<input type="text" name="manual_card_name" placeholder="<?php esc_attr_e( 'e.g. Umbreon VMAX', 'evg' ); ?>" required>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Card Set *', 'evg' ); ?></label>
					<input type="text" name="manual_card_set" placeholder="<?php esc_attr_e( 'e.g. Evolving Skies', 'evg' ); ?>" required>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Card Number', 'evg' ); ?></label>
					<input type="text" name="manual_card_number" placeholder="<?php esc_attr_e( 'e.g. 215/203', 'evg' ); ?>">
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Assign EVG Card # (Starts 0100) *', 'evg' ); ?></label>
					<input type="text" name="manual_cert_number" value="0100" style="font-family:monospace;" required>
				</div>
			</div>

			<div class="evg-grid-form" style="margin-bottom: 18px;">
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Service Label Tier', 'evg' ); ?></label>
					<select name="manual_label_tier">
						<option value="Black Basic"><?php esc_html_e( 'Black Basic (£0.00)', 'evg' ); ?></option>
						<option value="Colour Match"><?php esc_html_e( 'Colour Match (£0.99)', 'evg' ); ?></option>
						<option value="Lightening"><?php esc_html_e( 'Lightening (£0.99)', 'evg' ); ?></option>
						<option value="Extended Artwork"><?php esc_html_e( 'Extended Artwork (£2.99)', 'evg' ); ?></option>
					</select>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Service Speed', 'evg' ); ?></label>
					<select name="manual_service_speed">
						<option value="Standard"><?php esc_html_e( 'Standard (5-10 Business Days)', 'evg' ); ?></option>
						<option value="Express"><?php esc_html_e( 'Express Speed', 'evg' ); ?></option>
					</select>
				</div>
				<div class="evg-form-field">
					<label><?php esc_html_e( 'Delivery / Handover', 'evg' ); ?></label>
					<select name="manual_fulfilment_type">
						<option value="postage"><?php esc_html_e( 'Insured Tracked Postage', 'evg' ); ?></option>
						<option value="collection"><?php esc_html_e( 'Collection – Doncaster', 'evg' ); ?></option>
					</select>
				</div>
			</div>

			<button type="submit" style="background: var(--evg-gold); color: #0a0a0a; border: none; font-weight: 800; padding: 11px 22px; border-radius: 6px; cursor: pointer; text-transform: uppercase; font-size: 11px; letter-spacing: 0.8px;">
				<?php esc_html_e( 'Create Manual Order Record', 'evg' ); ?>
			</button>
		</form>
	</div>

	<!-- Submissions Queue DataTable -->
	<div class="evg-panel-shell">
		<table class="evg-sub-table evg-submissions-datatable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Collector Account', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Submission Date', 'evg' ); ?></th>
					<th style="text-align: center;"><?php esc_html_e( 'Units', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Service / Tier', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Pipeline Stage', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Payment', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Actions', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_submissions ) ) : ?>
					<?php foreach ( $evg_submissions as $sub ) : 
						$evg_is_done   = in_array( $sub->current_stage, array( 'Completed', 'Returned To Customer' ), true );
						$evg_pay_class = 'evg-pay-' . ( false !== strpos( $sub->payment_status, 'Paid' ) ? 'Paid' : ( false !== strpos( $sub->payment_status, 'Refund' ) ? 'Refunded' : 'Pending' ) );
						$evg_timestamp = strtotime( $sub->submission_date );
						$evg_del_url   = wp_nonce_url( admin_url( 'admin.php?page=evg_tab_submissions&action=delete_submission&sub_id=' . $sub->id ), 'evg_delete_submission_' . $sub->id );
					?>
						<tr>
							<td>
								<span style="font-family: monospace; font-weight: 700; color: var(--evg-gold);">
									#<?php echo esc_html( $sub->order_number ); ?>
								</span>
							</td>
							<td>
								<strong style="color: #ffffff;"><?php echo esc_html( $sub->display_name ? $sub->display_name : __( 'Guest Collector', 'evg' ) ); ?></strong><br>
								<span style="color: var(--evg-text-muted); font-size: 12px;"><?php echo esc_html( $sub->user_email ); ?></span>
							</td>
							<td data-order="<?php echo esc_attr( $evg_timestamp ); ?>" style="color: var(--evg-text-muted); font-size: 12px;">
								<?php echo esc_html( date_i18n( 'M j, Y', $evg_timestamp ) ); ?>
							</td>
							<td style="text-align: center;">
								<span style="background: #18181a; border: 1px solid #2c2c2e; color: #ffffff; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 12px;">
									<?php echo esc_html( number_format_i18n( absint( $sub->total_cards ) ) ); ?>
								</span>
							</td>
							<td>
								<span style="font-weight: 600; color: #ffffff;"><?php echo esc_html( $sub->service_type ); ?></span><br>
								<span class="evg-tag-label"><?php echo esc_html( $sub->label_option ); ?></span>
							</td>
							<td>
								<span class="evg-stage-pill <?php echo $evg_is_done ? 'done' : 'active'; ?>">
									<?php echo esc_html( $sub->current_stage ); ?>
								</span>
							</td>
							<td>
								<span class="evg-pay-badge <?php echo esc_attr( $evg_pay_class ); ?>">
									&bull; <?php echo esc_html( $sub->payment_status ); ?>
								</span><br>
								<span style="font-weight: 700; color: #ffffff; font-size: 12px;">&pound;<?php echo esc_html( number_format_i18n( (float) $sub->total_amount, 2 ) ); ?></span>
							</td>
							<td style="text-align: right;">
								<div class="evg-actions-flex">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub->id ) ); ?>" class="evg-btn-manage">
										<?php esc_html_e( 'Manage →', 'evg' ); ?>
									</a>
									<a href="<?php echo esc_url( $evg_del_url ); ?>" class="evg-btn-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete this entire submission and all associated cards?', 'evg' ) ); ?>');" title="<?php esc_attr_e( 'Delete Order', 'evg' ); ?>">
										&times;
									</a>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
							<?php esc_html_e( 'No card submissions recorded in database.', 'evg' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-submissions-datatable tbody tr').length > 0 && $('.evg-submissions-datatable tbody tr td').length > 1) {
				$('.evg-submissions-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"order": [[ 2, "desc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Filter Submissions:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Show _MENU_ orders', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ entries', 'evg' ) ); ?>",
						"paginate": {
							"previous": "<?php echo esc_js( __( '← Prev', 'evg' ) ); ?>",
							"next": "<?php echo esc_js( __( 'Next →', 'evg' ) ); ?>"
						}
					}
				});
			}
		});
	</script>
	<?php
}

/**
 * Render Single Order Lifecycle, Card Ledger & Card Deletion.
 *
 * @param int    $sub_id                Submission ID.
 * @param string $evg_table_submissions Submissions DB table name.
 * @param string $evg_table_cards       Cards DB table name.
 */
function evg_render_pro_single_submission( $sub_id, $evg_table_submissions, $evg_table_cards ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_submission = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT s.*, u.user_email, u.display_name 
			FROM {$evg_table_submissions} s
			LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
			WHERE s.id = %d",
			$sub_id
		)
	);

	if ( ! $evg_submission ) {
		echo '<p style="color:#ff453a;">' . esc_html__( 'Submission record not found.', 'evg' ) . '</p>';
		return;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_cards = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$evg_table_cards} WHERE submission_id = %d ORDER BY id ASC", $sub_id ) );

	$evg_customer_id    = $evg_submission->customer_id;
	$evg_house_number   = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_house_number', true ) : '';
	$evg_street_address = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_street_address', true ) : '';
	$evg_town_city      = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_town_city', true ) : '';
	$evg_county         = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_county', true ) : '';
	$evg_postcode       = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_postcode', true ) : '';
	$evg_mobile_number  = $evg_customer_id ? get_user_meta( $evg_customer_id, 'evg_mobile_number', true ) : '';

	$evg_turnaround_setting = get_option( 'evg_turnaround_time', '5-10 Business Days' );
	$evg_stages             = Elite_Vault_Grading_System::get_order_stages();

	$evg_current_stage_idx = array_search( $evg_submission->current_stage, array_keys( $evg_stages ), true );
	if ( false === $evg_current_stage_idx ) {
		$evg_current_stage_idx = 0;
	}
	?>
	<style>
		.evg-sub-grid {
			display: grid;
			grid-template-columns: 1fr 380px;
			gap: 24px;
			align-items: start;
		}
		.evg-box-panel {
			background: #0f0f11;
			border: 1px solid #222224;
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
			margin-bottom: 24px;
		}
		.evg-box-panel-header {
			padding: 18px 24px;
			background: #141416;
			border-bottom: 1px solid #1f1f22;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.evg-box-panel-header h2 {
			margin: 0;
			font-size: 13px;
			font-weight: 700;
			color: #ffffff;
			text-transform: uppercase;
			letter-spacing: 0.8px;
		}

		.evg-pipeline-strip {
			background: #141416;
			border: 1px solid #222224;
			border-radius: 12px;
			padding: 18px 20px;
			margin-bottom: 24px;
		}
		.evg-steps-bar {
			display: flex;
			flex-wrap: wrap;
			gap: 8px;
			margin-top: 10px;
		}
		.evg-step-chip {
			font-size: 11px;
			font-family: monospace;
			padding: 5px 10px;
			border-radius: 6px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.evg-step-done {
			background: rgba(52, 199, 89, 0.1);
			border: 1px solid rgba(52, 199, 89, 0.3);
			color: #34c759;
		}
		.evg-step-active {
			background: rgba(212, 175, 55, 0.15);
			border: 1px solid #d4af37;
			color: #f3e5ab;
			box-shadow: 0 0 10px rgba(212, 175, 55, 0.2);
		}
		.evg-step-pending {
			background: #09090a;
			border: 1px solid #222224;
			color: #55555a;
		}

		.evg-roster-list {
			display: flex;
			flex-direction: column;
			gap: 12px;
			padding: 20px;
		}
		.evg-roster-item {
			background: #141416;
			border: 1px solid #222224;
			border-radius: 10px;
			padding: 18px 20px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 16px;
			transition: border-color 0.2s ease;
		}
		.evg-roster-item:hover {
			border-color: #333336;
		}
		.evg-roster-meta h4 {
			margin: 0 0 6px 0;
			color: #ffffff;
			font-size: 15px;
			font-weight: 700;
		}
		.evg-roster-meta p {
			margin: 0;
			color: #8e8e93;
			font-size: 12px;
			line-height: 1.4;
		}

		.evg-card-actions {
			display: flex;
			flex-direction: column;
			align-items: flex-end;
			gap: 8px;
		}
		.evg-btn-card-del {
			color: #ff453a;
			font-size: 11px;
			font-weight: 700;
			text-decoration: none;
			padding: 2px 6px;
			border: 1px solid rgba(255, 69, 58, 0.2);
			border-radius: 4px;
			background: rgba(255, 69, 58, 0.05);
			transition: all 0.2s ease;
		}
		.evg-btn-card-del:hover {
			background: #ff453a;
			color: #ffffff;
			border-color: #ff453a;
		}

		.evg-grade-token {
			background: #d4af37;
			color: #0a0a0a;
			font-weight: 900;
			font-size: 18px;
			width: 42px;
			height: 42px;
			border-radius: 8px;
			display: flex;
			align-items: center;
			justify-content: center;
			box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
		}

		.evg-form-box {
			padding: 20px;
		}
		.evg-field-group {
			margin-bottom: 18px;
		}
		.evg-field-group label {
			display: block;
			color: #d4af37;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			margin-bottom: 6px;
		}

		.evg-btn-save {
			width: 100%;
			background: #d4af37;
			color: #0a0a0a;
			border: none;
			padding: 14px;
			font-size: 13px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.2s ease;
		}
		.evg-btn-save:hover {
			background: #f3e5ab;
			box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
		}

		.evg-btn-invoice {
			width: 100%;
			background: transparent;
			border: 1px solid #28282b;
			color: #ffffff;
			padding: 12px;
			font-size: 12px;
			font-weight: 700;
			border-radius: 8px;
			text-decoration: none;
			display: block;
			text-align: center;
			box-sizing: border-box;
			margin-top: 10px;
			transition: all 0.2s ease;
		}
		.evg-btn-invoice:hover {
			border-color: #d4af37;
			color: #d4af37;
		}

		.evg-add-card-shell {
			background: #141416;
			border: 1px dashed #333336;
			border-radius: 10px;
			padding: 20px;
			margin: 0 20px 20px 20px;
		}
		.evg-inline-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 14px;
			margin-bottom: 14px;
		}

		@media (max-width: 1080px) {
			.evg-sub-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<div class="evg-sub-header">
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions' ) ); ?>" style="color: #d4af37; text-decoration: none; font-size: 13px; font-weight: 700; margin-bottom: 8px; display: inline-block;">
				&larr; <?php esc_html_e( 'Back to Submissions', 'evg' ); ?>
			</a>
			<h1><?php printf( esc_html__( 'Submission Ref #%s', 'evg' ), esc_html( $evg_submission->order_number ) ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: Customer name, 2: Formatted date, 3: Estimated turnaround */
					esc_html__( 'Declared by %1$s on %2$s • Estimated Turnaround: %3$s', 'evg' ),
					esc_html( $evg_submission->display_name ? $evg_submission->display_name : __( 'Guest', 'evg' ) ),
					esc_html( date_i18n( 'F j, Y', strtotime( $evg_submission->submission_date ) ) ),
					esc_html( $evg_turnaround_setting )
				);
				?>
			</p>
		</div>
	</div>

	<!-- 10-Stage Pipeline Progression Bar -->
	<div class="evg-pipeline-strip">
		<span style="font-size: 11px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.5px;">
			<?php esc_html_e( 'Pipeline Status:', 'evg' ); ?> <strong style="color: #d4af37;"><?php echo esc_html( $evg_submission->current_stage ); ?></strong>
		</span>
		<div class="evg-steps-bar">
			<?php 
			$i = 0;
			foreach ( $evg_stages as $stg_key => $stg_label ) :
				$evg_chip_class = 'evg-step-pending';
				if ( $i < $evg_current_stage_idx ) {
					$evg_chip_class = 'evg-step-done';
				} elseif ( $i === $evg_current_stage_idx ) {
					$evg_chip_class = 'evg-step-active';
				}
				?>
				<span class="evg-step-chip <?php echo esc_attr( $evg_chip_class ); ?>">
					<?php echo esc_html( sprintf( '%02d. %s', $i + 1, $stg_label ) ); ?> <?php echo ( $i < $evg_current_stage_idx ) ? '&#10003;' : ''; ?>
				</span>
				<?php 
				$i++;
			endforeach; 
			?>
		</div>
	</div>

	<div class="evg-sub-grid">
		<!-- Main Column: Declared Cards -->
		<div>
			<div class="evg-box-panel">
				<div class="evg-box-panel-header">
					<h2><?php esc_html_e( 'Declared Cards Manifest', 'evg' ); ?></h2>
					<span style="font-size: 12px; color: #8e8e93; font-weight: 700; font-family: monospace;">
						<?php
						printf(
							/* translators: %s: Count of cards declared */
							esc_html__( '%s Units (From £9.99 Base Tier)', 'evg' ),
							esc_html( number_format_i18n( count( $evg_cards ) ) )
						);
						?>
					</span>
				</div>

				<div class="evg-roster-list">
					<?php if ( ! empty( $evg_cards ) ) : ?>
						<?php foreach ( $evg_cards as $index => $card ) : 
							$evg_card_del_url = wp_nonce_url( admin_url( 'admin.php?page=evg_tab_submissions&action=delete_card&card_id=' . $card->id . '&submission_id=' . $sub_id ), 'evg_delete_card_' . $card->id );
						?>
							<div class="evg-roster-item">
								<div class="evg-roster-meta">
									<h4><?php echo esc_html( ( $index + 1 ) . '. ' . $card->card_name ); ?></h4>
									<p>
										<strong><?php esc_html_e( 'Set:', 'evg' ); ?></strong> <?php echo esc_html( $card->set_name ); ?> &nbsp;|&nbsp; 
										<strong><?php esc_html_e( 'Card No:', 'evg' ); ?></strong> #<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?> &nbsp;|&nbsp; 
										<strong style="color:var(--evg-gold);"><?php esc_html_e( 'EVG Card No:', 'evg' ); ?></strong> <span style="font-family:monospace; color:#fff; font-weight:700;"><?php echo esc_html( $card->cert_number ? $card->cert_number : '0100' ); ?></span> &nbsp;|&nbsp;
										<strong><?php esc_html_e( 'Lang:', 'evg' ); ?></strong> <?php echo esc_html( $card->language ); ?>
									</p>

									<?php if ( ! empty( $card->customer_notes ) ) : ?>
										<p style="color: #d4af37; margin-top: 6px; font-style: italic;">
											"<?php echo esc_html( $card->customer_notes ); ?>"
										</p>
									<?php endif; ?>
								</div>

								<div class="evg-card-actions">
									<span style="background: #1c1c1f; border: 1px solid #2c2c30; color: #8e8e93; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
										<?php echo esc_html( $card->grading_status ); ?>
									</span>
									<?php if ( ! empty( $card->final_grade ) ) : ?>
										<div class="evg-grade-token" title="<?php esc_attr_e( 'Certified EVG Grade', 'evg' ); ?>">
											<?php echo esc_html( $card->final_grade ); ?>
										</div>
									<?php endif; ?>
									<a href="<?php echo esc_url( $evg_card_del_url ); ?>" class="evg-btn-card-del" onclick="return confirm('<?php echo esc_js( __( 'Remove this card from the submission?', 'evg' ) ); ?>');">
										&times; <?php esc_html_e( 'Delete', 'evg' ); ?>
									</a>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<p style="text-align: center; color: #8e8e93; padding: 30px 0;">
							<?php esc_html_e( 'No declared cards found for this submission.', 'evg' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<!-- In-Line Add Card Form -->
				<div class="evg-add-card-shell">
					<h3 style="color: #ffffff; font-size: 13px; font-weight: 700; text-transform: uppercase; margin: 0 0 14px 0;">
						+ <?php esc_html_e( 'Direct Add Card to Submission', 'evg' ); ?>
					</h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_submission->id ) ); ?>">
						<?php wp_nonce_field( 'evg_add_card_to_submission', 'evg_add_card_nonce' ); ?>
						<input type="hidden" name="submission_id" value="<?php echo esc_attr( $evg_submission->id ); ?>">

						<div class="evg-inline-grid">
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Card Name *', 'evg' ); ?></label>
								<input type="text" name="card_name" class="evg-field-control" placeholder="<?php esc_attr_e( 'e.g. Charizard VMAX', 'evg' ); ?>" required>
							</div>
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Set Name *', 'evg' ); ?></label>
								<input type="text" name="set_name" class="evg-field-control" placeholder="<?php esc_attr_e( 'e.g. Shining Fates', 'evg' ); ?>" required>
							</div>
						</div>

						<div class="evg-inline-grid">
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Card Number', 'evg' ); ?></label>
								<input type="text" name="card_number" class="evg-field-control" placeholder="<?php esc_attr_e( 'e.g. 074/072', 'evg' ); ?>">
							</div>
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Manual EVG Card # (Starts 0100) *', 'evg' ); ?></label>
								<input type="text" name="cert_number" class="evg-field-control" value="0100" style="font-family:monospace;" required>
							</div>
						</div>

						<div class="evg-inline-grid">
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Language', 'evg' ); ?></label>
								<select name="language" class="evg-field-control">
									<option value="English"><?php esc_html_e( 'English (ENG)', 'evg' ); ?></option>
									<option value="Japanese"><?php esc_html_e( 'Japanese (JPN)', 'evg' ); ?></option>
									<option value="Other"><?php esc_html_e( 'Other', 'evg' ); ?></option>
								</select>
							</div>
							<div>
								<label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Grade (Optional)', 'evg' ); ?></label>
								<select name="final_grade" class="evg-field-control">
									<option value="" selected><?php esc_html_e( 'Pending Assessment', 'evg' ); ?></option>
									<?php for ( $g = 10; $g >= 1; $g-- ) : ?>
										<option value="<?php echo esc_attr( $g ); ?>">EVG <?php echo esc_html( $g ); ?></option>
									<?php endfor; ?>
								</select>
							</div>
						</div>

						<button type="submit" class="evg-btn-save" style="padding: 10px; font-size: 12px;">
							+ <?php esc_html_e( 'Add Card & Assign Cert Number', 'evg' ); ?>
						</button>
					</form>
				</div>
			</div>
		</div>

		<!-- Sidebar: Order Parameters Control Panel -->
		<div>
			<div class="evg-box-panel">
				<div class="evg-box-panel-header">
					<h2><?php esc_html_e( 'Parameters', 'evg' ); ?></h2>
				</div>
				<div class="evg-form-box">
					<div style="background: #141416; border: 1px solid #222224; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
						<span style="font-size: 10px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.8px; display: block; margin-bottom: 4px;">
							<?php esc_html_e( 'Collector', 'evg' ); ?>
						</span>
						<strong style="color: #ffffff; font-size: 14px; display: block;"><?php echo esc_html( $evg_submission->display_name ? $evg_submission->display_name : __( 'Guest', 'evg' ) ); ?></strong>
						<span style="color: #8e8e93; font-size: 12px;"><?php echo esc_html( $evg_submission->user_email ); ?></span>
						<?php if ( ! empty( $evg_mobile_number ) ) : ?>
							<br><span style="color: var(--evg-gold); font-size: 11px; font-family: monospace;"><?php echo esc_html( $evg_mobile_number ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $evg_submission->submission_notes ) ) : ?>
						<div style="background: rgba(212, 175, 55, 0.05); border: 1px solid var(--evg-border); border-radius: 8px; padding: 14px; margin-bottom: 16px;">
							<span style="font-size: 10px; text-transform: uppercase; color: var(--evg-gold); font-weight: 700; letter-spacing: 0.8px; display: block; margin-bottom: 4px;">
								<?php esc_html_e( 'Collector Submission Notes', 'evg' ); ?>
							</span>
							<p style="color: #ffffff; font-size: 12px; font-style: italic; margin: 0; line-height: 1.4;">
								"<?php echo esc_html( $evg_submission->submission_notes ); ?>"
							</p>
						</div>
					<?php endif; ?>

					<div style="background: #141416; border: 1px solid #222224; border-radius: 8px; padding: 14px; margin-bottom: 20px; font-size: 12px; line-height: 1.4;">
						<span style="font-size: 10px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.8px; display: block; margin-bottom: 4px;">
							<?php esc_html_e( 'Handover & Destination', 'evg' ); ?>
						</span>
						<strong style="color: <?php echo ( isset( $evg_submission->fulfilment_type ) && 'collection' === $evg_submission->fulfilment_type ) ? 'var(--evg-gold)' : '#34c759'; ?>; text-transform: uppercase; font-size: 11px; display: block; margin-bottom: 6px;">
							&bull; <?php echo ( isset( $evg_submission->fulfilment_type ) && 'collection' === $evg_submission->fulfilment_type ) ? esc_html__( 'Collection – Doncaster (Pre-agreed)', 'evg' ) : esc_html__( 'Insured Tracked Postage', 'evg' ); ?>
						</strong>
						<?php if ( ! empty( $evg_street_address ) || ! empty( $evg_postcode ) ) : ?>
							<span style="color: #ffffff;"><?php echo esc_html( trim( $evg_house_number . ' ' . $evg_street_address ) ); ?></span><br>
							<span style="color: #8e8e93;"><?php echo esc_html( $evg_town_city . ', ' . $evg_county ); ?></span><br>
							<strong style="color: var(--evg-gold); font-family: monospace;"><?php echo esc_html( $evg_postcode ); ?>, UK</strong>
						<?php endif; ?>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $evg_submission->id ) ); ?>">
						<?php wp_nonce_field( 'evg_update_submission', 'evg_update_submission_nonce' ); ?>
						<input type="hidden" name="submission_id" value="<?php echo esc_attr( $evg_submission->id ); ?>">

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Pipeline Stage', 'evg' ); ?></label>
							<select name="current_stage" class="evg-field-control">
								<?php foreach ( $evg_stages as $stg_key => $stg_label ) : ?>
									<option value="<?php echo esc_attr( $stg_key ); ?>" <?php selected( $evg_submission->current_stage, $stg_key ); ?>>
										<?php echo esc_html( $stg_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Fulfilment Method', 'evg' ); ?></label>
							<select name="fulfilment_method" class="evg-field-control">
								<option value="postage" <?php selected( isset( $evg_submission->fulfilment_type ) ? $evg_submission->fulfilment_type : 'postage', 'postage' ); ?>><?php esc_html_e( 'Insured Tracked Postage', 'evg' ); ?></option>
								<option value="collection" <?php selected( isset( $evg_submission->fulfilment_type ) ? $evg_submission->fulfilment_type : 'postage', 'collection' ); ?>><?php esc_html_e( 'Collection – Doncaster', 'evg' ); ?></option>
							</select>
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Service Speed Tier', 'evg' ); ?></label>
							<select name="service_type" class="evg-field-control">
								<option value="Standard" <?php selected( $evg_submission->service_type, 'Standard' ); ?>><?php esc_html_e( 'Standard (5-10 Business Days)', 'evg' ); ?></option>
								<option value="Express" <?php selected( $evg_submission->service_type, 'Express' ); ?>><?php esc_html_e( 'Express Speed (+£4.99/card)', 'evg' ); ?></option>
							</select>
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Label Specification', 'evg' ); ?></label>
							<select name="label_option" class="evg-field-control">
								<option value="Black Basic" <?php selected( $evg_submission->label_option, 'Black Basic' ); ?>><?php esc_html_e( 'Black Basic (+£0.00)', 'evg' ); ?></option>
								<option value="Colour Match" <?php selected( $evg_submission->label_option, 'Colour Match' ); ?>><?php esc_html_e( 'Colour Match (+£0.99/card)', 'evg' ); ?></option>
								<option value="Lightening" <?php selected( $evg_submission->label_option, 'Lightening' ); ?>><?php esc_html_e( 'Lightening (+£0.99/card)', 'evg' ); ?></option>
								<option value="Extended Artwork" <?php selected( $evg_submission->label_option, 'Extended Artwork' ); ?>><?php esc_html_e( 'Extended Artwork (+£2.99/card)', 'evg' ); ?></option>
							</select>
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Payment Status', 'evg' ); ?></label>
							<select name="payment_status" class="evg-field-control">
								<option value="Pending" <?php selected( $evg_submission->payment_status, 'Pending' ); ?>><?php esc_html_e( 'Pending', 'evg' ); ?></option>
								<option value="Paid" <?php selected( $evg_submission->payment_status, 'Paid' ); ?>><?php esc_html_e( 'Paid', 'evg' ); ?></option>
								<option value="Paid (Cash)" <?php selected( $evg_submission->payment_status, 'Paid (Cash)' ); ?>><?php esc_html_e( 'Paid (Cash)', 'evg' ); ?></option>
								<option value="Internal Free (Staff)" <?php selected( $evg_submission->payment_status, 'Internal Free (Staff)' ); ?>><?php esc_html_e( 'Internal Free (Staff)', 'evg' ); ?></option>
								<option value="Partial Refund" <?php selected( $evg_submission->payment_status, 'Partial Refund' ); ?>><?php esc_html_e( 'Partial Refund', 'evg' ); ?></option>
								<option value="Refunded" <?php selected( $evg_submission->payment_status, 'Refunded' ); ?>><?php esc_html_e( 'Refunded', 'evg' ); ?></option>
							</select>
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Declared Insurance Value (£)', 'evg' ); ?></label>
							<input type="number" step="0.01" name="declared_insurance_value" class="evg-field-control" value="<?php echo esc_attr( number_format( (float) ( isset( $evg_submission->declared_value ) ? $evg_submission->declared_value : 0.00 ), 2, '.', '' ) ); ?>">
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Authorized Total (£)', 'evg' ); ?></label>
							<input type="number" step="0.01" name="total_amount" class="evg-field-control" value="<?php echo esc_attr( number_format( (float) $evg_submission->total_amount, 2, '.', '' ) ); ?>">
						</div>

						<div class="evg-field-group">
							<label><?php esc_html_e( 'Return Royal Mail Tracking #', 'evg' ); ?></label>
							<input type="text" name="return_tracking" class="evg-field-control" placeholder="<?php esc_attr_e( 'e.g. GB123456789RM', 'evg' ); ?>" value="<?php echo esc_attr( $evg_submission->return_tracking ); ?>">
						</div>

						<button type="submit" class="evg-btn-save">
							<?php esc_html_e( 'Update Order', 'evg' ); ?>
						</button>
					</form>

					<a href="<?php echo esc_url( admin_url( 'admin-post.php?action=evg_download_invoice&submission_id=' . $evg_submission->id ) ); ?>" target="_blank" rel="noopener noreferrer" class="evg-btn-invoice">
						&#128196; <?php esc_html_e( 'Download Packing Manifest / Invoice', 'evg' ); ?>
					</a>
				</div>
			</div>
		</div>
	</div>
	<?php
}