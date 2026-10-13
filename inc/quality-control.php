<?php
/**
 * EVG Module: Quality Control (Pro Edition)
 * Step 5 of the Grading Process: Final verification, sub-score cross-check, defect gallery audit, and encapsulation approval.
 * Pagination configured to 10 items per page with inline transparency toggle actions.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST/GET QC actions (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_quality_control_actions' );

function evg_handle_quality_control_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_quality-control' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'head_grader' ) ) ) {
		return;
	}

	global $wpdb;

	$evg_table_cards       = $wpdb->prefix . 'evg_cards';
	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';

	// ---------------------------------------------------------
	// 1. Quick Toggle Transparency from Queue Table
	// ---------------------------------------------------------
	if ( isset( $_GET['action'], $_GET['card_id'], $_GET['_wpnonce'] ) && 'toggle_transparency' === $_GET['action'] ) {
		$evg_card_id = absint( $_GET['card_id'] );
		check_admin_referer( 'evg_toggle_transparency_' . $evg_card_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$evg_current_val = (int) $wpdb->get_var( $wpdb->prepare( "SELECT transparency_published FROM {$evg_table_cards} WHERE id = %d", $evg_card_id ) );
		$evg_new_val     = ( 1 === $evg_current_val ) ? 0 : 1;

		$wpdb->update(
			$evg_table_cards,
			array( 'transparency_published' => $evg_new_val ),
			array( 'id' => $evg_card_id ),
			array( '%d' ),
			array( '%d' )
		);

		if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
			$action_text = ( 1 === $evg_new_val ) ? 'Published Transparency' : 'Concealed Transparency';
			Elite_Vault_Grading_System::log_activity( "{$action_text} for Card ID #{$evg_card_id}." );
		}

		evg_qc_safe_redirect( admin_url( 'admin.php?page=evg_tab_quality-control&transparency_toggled=1' ) );
	}

	// ---------------------------------------------------------
	// 2. Bulk Publish All In-Queue Transparency
	// ---------------------------------------------------------
	if ( isset( $_GET['action'], $_GET['_wpnonce'] ) && 'publish_all_transparency' === $_GET['action'] ) {
		check_admin_referer( 'evg_publish_all_transparency' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$evg_table_cards} SET transparency_published = 1 WHERE grading_status = 'Quality Control'" );

		if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
			Elite_Vault_Grading_System::log_activity( 'Bulk Published transparency reports for all cards currently in QC.' );
		}

		evg_qc_safe_redirect( admin_url( 'admin.php?page=evg_tab_quality-control&bulk_transparency=1' ) );
	}

	// ---------------------------------------------------------
	// 3. Handle QC Approval & Reject Routing Form
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_qc_action_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_qc_action_nonce'] ) ), 'evg_qc_action' ) ) {

			$evg_card_id        = isset( $_POST['card_id'] ) ? absint( $_POST['card_id'] ) : 0;
			$evg_submission_id  = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
			$evg_qc_action      = isset( $_POST['qc_action'] ) ? sanitize_key( wp_unslash( $_POST['qc_action'] ) ) : '';
			$evg_publish_report = isset( $_POST['transparency_published'] ) ? 1 : 0;

			if ( 'approve' === $evg_qc_action && $evg_card_id > 0 ) {
				$wpdb->update(
					$evg_table_cards,
					array(
						'grading_status'         => 'Encapsulation',
						'transparency_published' => $evg_publish_report,
					),
					array( 'id' => $evg_card_id ),
					array( '%s', '%d' ),
					array( '%d' )
				);

				// Auto-advance parent submission stage if all cards in batch have cleared QC.
				if ( $evg_submission_id > 0 ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$evg_pending_cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$evg_table_cards} WHERE submission_id = %d AND grading_status NOT IN ('Encapsulation', 'Completed')", $evg_submission_id ) );
					if ( 0 === $evg_pending_cards ) {
						$wpdb->update(
							$evg_table_submissions,
							array( 'current_stage' => 'Encapsulation' ),
							array( 'id' => $evg_submission_id ),
							array( '%s' ),
							array( '%d' )
						);
					}
				}

				if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
					Elite_Vault_Grading_System::log_activity( "QC Approved for Card ID {$evg_card_id}. Dispatched to Encapsulation." );
				}

				evg_qc_safe_redirect( admin_url( 'admin.php?page=evg_tab_quality-control&approved=1' ) );
			} elseif ( 'reject' === $evg_qc_action && $evg_card_id > 0 ) {
				$wpdb->update(
					$evg_table_cards,
					array( 'grading_status' => 'Grading In Progress' ),
					array( 'id' => $evg_card_id ),
					array( '%s' ),
					array( '%d' )
				);

				if ( $evg_submission_id > 0 ) {
					$wpdb->update(
						$evg_table_submissions,
						array( 'current_stage' => 'Grading In Progress' ),
						array( 'id' => $evg_submission_id ),
						array( '%s' ),
						array( '%d' )
					);
				}

				if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
					Elite_Vault_Grading_System::log_activity( "QC Rejected for Card ID {$evg_card_id}. Returned to Grading Desk." );
				}

				evg_qc_safe_redirect( admin_url( 'admin.php?page=evg_tab_quality-control&rejected=1' ) );
			}
		}
	}
}

/**
 * Universal redirect helper.
 *
 * @param string $location Destination URL.
 */
function evg_qc_safe_redirect( $location ) {
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
 * Render function for the Quality Control tab.
 */
function evg_quality_control_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'head_grader' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Quality Control.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;

	$evg_table_cards       = $wpdb->prefix . 'evg_cards';
	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';
	$evg_table_assessments = $wpdb->prefix . 'evg_assessments';
	$evg_table_faults      = $wpdb->prefix . 'evg_fault_images';

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['approved'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Quality Control approved. Card routed to Encapsulation.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['rejected'] ) ) {
		echo '<div class="notice notice-error is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'QC Rejected. Card returned to Grading Desk for re-assessment.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['transparency_toggled'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Transparency report visibility toggled successfully.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['bulk_transparency'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #34c759; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'All pending QC cards published to public transparency portals.', 'evg' ) . '</p></div>';
	}

	$evg_action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	$evg_card_id = isset( $_GET['card_id'] ) ? absint( $_GET['card_id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'review' === $evg_action && $evg_card_id > 0 ) {
		evg_render_pro_qc_review_interface( $evg_card_id, $evg_table_cards, $evg_table_submissions, $evg_table_assessments, $evg_table_faults );
	} else {
		evg_render_pro_qc_list( $evg_table_cards, $evg_table_submissions );
	}
}

/**
 * Pro View: Quality Control Queue
 *
 * @param string $evg_table_cards       Cards DB table name.
 * @param string $evg_table_submissions Submissions DB table name.
 */
function evg_render_pro_qc_list( $evg_table_cards, $evg_table_submissions ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_cards = $wpdb->get_results(
		"SELECT c.*, s.order_number, s.label_option, s.submission_date 
		FROM {$evg_table_cards} c
		JOIN {$evg_table_submissions} s ON c.submission_id = s.id
		WHERE c.grading_status = 'Quality Control'
		ORDER BY s.submission_date ASC"
	);

	$bulk_publish_url = wp_nonce_url( admin_url( 'admin.php?page=evg_tab_quality-control&action=publish_all_transparency' ), 'evg_publish_all_transparency' );
	?>
	<style>
		:root {
			--evg-gold: #d4af37;
			--evg-gold-light: #f3e5ab;
			--evg-border: #222224;
			--evg-bg-card: #0f0f11;
			--evg-text-muted: #8e8e93;
		}

		.evg-qc-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 28px;
			flex-wrap: wrap;
			gap: 15px;
		}
		.evg-qc-header h1 {
			font-size: 26px;
			font-weight: 800;
			margin: 0 0 6px 0;
			color: #ffffff;
			letter-spacing: -0.5px;
		}
		.evg-qc-header p {
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
		}

		.evg-qc-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}
		.evg-qc-table thead th {
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
		.evg-qc-table tbody td {
			padding: 18px 20px;
			border-bottom: 1px solid #1a1a1d;
			color: #ffffff;
			font-size: 13px;
			vertical-align: middle;
		}
		.evg-qc-table tbody tr {
			transition: background 0.2s ease;
		}
		.evg-qc-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.02);
		}
		.evg-qc-table tbody tr:last-child td {
			border-bottom: none;
		}

		.evg-grade-pill {
			background: #d4af37;
			color: #0a0a0a;
			font-weight: 900;
			font-size: 14px;
			padding: 4px 12px;
			border-radius: 6px;
			display: inline-block;
			box-shadow: 0 2px 8px rgba(212, 175, 55, 0.25);
		}

		.evg-portal-badge {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 11px;
			font-weight: 700;
			padding: 4px 10px;
			border-radius: 6px;
			text-decoration: none;
			transition: all 0.2s ease;
		}
		.evg-portal-active {
			background: rgba(52, 199, 89, 0.1);
			color: #34c759;
			border: 1px solid rgba(52, 199, 89, 0.3);
		}
		.evg-portal-active:hover {
			background: rgba(52, 199, 89, 0.2);
			color: #34c759;
		}
		.evg-portal-hidden {
			background: rgba(142, 142, 147, 0.1);
			color: #8e8e93;
			border: 1px solid rgba(142, 142, 147, 0.3);
		}
		.evg-portal-hidden:hover {
			background: rgba(212, 175, 55, 0.1);
			border-color: var(--evg-gold);
			color: var(--evg-gold);
		}

		.evg-actions-cell {
			display: flex;
			align-items: center;
			justify-content: flex-end;
			gap: 10px;
		}

		.evg-btn-qc {
			background: var(--evg-gold);
			color: #0a0a0a !important;
			padding: 8px 18px;
			font-size: 12px;
			font-weight: 800;
			border-radius: 6px;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			gap: 6px;
			transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
			white-space: nowrap;
		}
		.evg-btn-qc:hover {
			background: #f3e5ab;
			transform: translateY(-1px);
			box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
			color: #000000 !important;
		}

		.evg-btn-bulk-publish {
			background: transparent;
			border: 1px solid var(--evg-gold);
			color: var(--evg-gold);
			padding: 8px 16px;
			border-radius: 8px;
			font-size: 12px;
			font-weight: 700;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			gap: 6px;
			transition: all 0.2s ease;
		}
		.evg-btn-bulk-publish:hover {
			background: var(--evg-gold);
			color: #000000;
		}

		/* DataTables Custom UI Styling */
		.dataTables_wrapper {
			padding: 10px 0;
			color: #ffffff;
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
			padding: 5px 12px !important;
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
		.dataTables_wrapper .dataTables_paginate .paginate_button.disabled,
		.dataTables_wrapper .dataTables_paginate .paginate_button.disabled:hover {
			background: #101012 !important;
			border-color: #1f1f23 !important;
			color: #444449 !important;
			cursor: not-allowed !important;
		}
		.dataTables_wrapper .dataTables_length,
		.dataTables_wrapper .dataTables_filter,
		.dataTables_wrapper .dataTables_info {
			padding: 15px 20px;
			color: var(--evg-text-muted);
			font-size: 12px;
		}
		.dataTables_wrapper .dataTables_filter input,
		.dataTables_wrapper .dataTables_length select {
			background: #141416;
			border: 1px solid #28282b;
			color: #ffffff;
			border-radius: 4px;
			padding: 5px 10px;
			outline: none;
		}
		.dataTables_wrapper .dataTables_filter input:focus,
		.dataTables_wrapper .dataTables_length select:focus {
			border-color: var(--evg-gold);
		}
	</style>

	<div class="evg-qc-header">
		<div>
			<h1><?php esc_html_e( 'QC Queue', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Step 5 of the Grading Process. Verify card information, grade labels, and slab presentation before sealing.', 'evg' ); ?></p>
		</div>
		<div style="display: flex; align-items: center; gap: 14px;">
			<?php if ( ! empty( $evg_cards ) ) : ?>
				<a href="<?php echo esc_url( $bulk_publish_url ); ?>" class="evg-btn-bulk-publish" onclick="return confirm('<?php echo esc_js( __( 'Publish transparency reports for all cards currently in QC to customer portals?', 'evg' ) ); ?>');">
					&#10003; <?php esc_html_e( 'Publish All Transparency', 'evg' ); ?>
				</a>
			<?php endif; ?>
			<div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
				<?php
				printf(
					/* translators: %s: Formatted count of cards in QC review */
					esc_html__( '%s Units in QC Review', 'evg' ),
					esc_html( number_format_i18n( count( $evg_cards ) ) )
				);
				?>
			</div>
		</div>
	</div>

	<div class="evg-panel-shell">
		<table class="evg-qc-table evg-qc-datatable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Card Identification', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Set / Number', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Assigned Grade', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Slab Design', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Portal Report', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Verification', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_cards ) ) : ?>
					<?php foreach ( $evg_cards as $card ) : 
						$evg_toggle_nonce_url = wp_nonce_url( admin_url( 'admin.php?page=evg_tab_quality-control&action=toggle_transparency&card_id=' . $card->id ), 'evg_toggle_transparency_' . $card->id );
						$is_published         = ( 1 === (int) $card->transparency_published );
					?>
						<tr>
							<td>
								<span style="font-family: monospace; font-weight: 700; color: var(--evg-gold);">
									#<?php echo esc_html( $card->order_number ); ?>
								</span>
							</td>
							<td>
								<strong style="font-size: 14px; color: #ffffff;"><?php echo esc_html( $card->card_name ); ?></strong><br>
								<span style="color: var(--evg-text-muted); font-size: 12px;"><?php echo esc_html( $card->language ); ?></span>
							</td>
							<td>
								<span style="color: #ffffff;"><?php echo esc_html( $card->set_name ); ?></span><br>
								<span style="color: var(--evg-text-muted); font-size: 12px;">#<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?></span>
							</td>
							<td>
								<span class="evg-grade-pill">EVG <?php echo esc_html( $card->final_grade ); ?></span>
							</td>
							<td>
								<span style="background: #1c1c1f; border: 1px solid #2c2c30; color: var(--evg-gold); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
									<?php echo esc_html( $card->label_option ); ?>
								</span>
							</td>
							<td>
								<a href="<?php echo esc_url( $evg_toggle_nonce_url ); ?>" class="evg-portal-badge <?php echo $is_published ? 'evg-portal-active' : 'evg-portal-hidden'; ?>" title="<?php esc_attr_e( 'Click to toggle transparency publication', 'evg' ); ?>">
									<?php if ( $is_published ) : ?>
										&#10003; <?php esc_html_e( 'Live in Portal', 'evg' ); ?>
									<?php else : ?>
										&#128065; <?php esc_html_e( 'Hidden / Gated', 'evg' ); ?>
									<?php endif; ?>
								</a>
							</td>
							<td style="text-align: right;">
								<div class="evg-actions-cell">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control&action=review&card_id=' . $card->id ) ); ?>" class="evg-btn-qc">
										&#128737; <?php esc_html_e( 'Verify', 'evg' ); ?>
									</a>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="7" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
							<?php esc_html_e( 'No cards currently pending quality control review.', 'evg' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-qc-datatable tbody tr').length > 0 && $('.evg-qc-datatable tbody tr td').length > 1) {
				$('.evg-qc-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
					"order": [[ 0, "asc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Filter QC Queue:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Display _MENU_ cards per page', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ cards in QC queue', 'evg' ) ); ?>",
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
 * Pro View: Step 5 QC Verification Interface
 *
 * @param int    $card_id               Inspected card ID.
 * @param string $table_cards           Cards DB table name.
 * @param string $table_submissions     Submissions DB table name.
 * @param string $table_assessments     Assessments DB table name.
 * @param string $table_faults          Faults DB table name.
 */
function evg_render_pro_qc_review_interface( $card_id, $table_cards, $table_submissions, $table_assessments, $table_faults ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_card = $wpdb->get_row( $wpdb->prepare( "SELECT c.*, s.order_number, s.label_option FROM {$table_cards} c JOIN {$table_submissions} s ON c.submission_id = s.id WHERE c.id = %d", $card_id ) );
	if ( ! $evg_card ) {
		echo '<p style="color:#ff453a;">' . esc_html__( 'Card not found in database.', 'evg' ) . '</p>';
		return;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_assessment   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_assessments} WHERE card_id = %d", $card_id ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_fault_images = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_faults} WHERE card_id = %d ORDER BY id ASC", $card_id ) );

	$evg_grader_name = __( 'Authorized Grader', 'evg' );
	if ( $evg_assessment && $evg_assessment->grader_id ) {
		$evg_grader_info = get_userdata( $evg_assessment->grader_id );
		if ( $evg_grader_info ) {
			$evg_grader_name = $evg_grader_info->display_name;
		}
	}
	?>
	<style>
		.evg-qc-split-grid {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 24px;
			align-items: start;
		}

		.evg-qc-box {
			background: #0f0f11;
			border: 1px solid #222224;
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
			margin-bottom: 24px;
		}
		.evg-qc-box-header {
			padding: 18px 20px;
			background: #141416;
			border-bottom: 1px solid #1f1f22;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.evg-qc-box-header h2 {
			margin: 0;
			font-size: 13px;
			font-weight: 700;
			color: #ffffff;
			text-transform: uppercase;
			letter-spacing: 0.8px;
		}
		.evg-qc-box-body {
			padding: 20px;
		}

		.evg-verify-table {
			width: 100%;
			border-collapse: collapse;
		}
		.evg-verify-table td {
			padding: 12px 0;
			border-bottom: 1px solid #1a1a1c;
			color: #ffffff;
			font-size: 13px;
		}
		.evg-verify-table td:first-child {
			color: #8e8e93;
			width: 40%;
		}

		.evg-qc-grade-badge {
			background: #d4af37;
			color: #0a0a0a;
			font-weight: 900;
			font-size: 22px;
			padding: 6px 18px;
			border-radius: 6px;
			display: inline-block;
			box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
		}

		.evg-subgrades-strip {
			background: #141416;
			border: 1px solid #222224;
			border-radius: 8px;
			padding: 14px;
			margin-top: 18px;
		}
		.evg-subgrades-grid {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 8px;
			text-align: center;
			margin-top: 8px;
		}
		.evg-subgrade-cell {
			background: #0a0a0a;
			border: 1px solid #2a2a2e;
			border-radius: 6px;
			padding: 8px 4px;
		}
		.evg-subgrade-cell span {
			display: block;
			font-size: 10px;
			color: #8e8e93;
			text-transform: uppercase;
			font-weight: 700;
		}
		.evg-subgrade-cell strong {
			font-size: 16px;
			color: #d4af37;
			font-weight: 800;
		}

		.evg-fault-gallery {
			display: grid;
			grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
			gap: 12px;
			margin-top: 12px;
		}
		.evg-fault-card {
			background: #0a0a0a;
			border: 1px solid #2a2a2e;
			border-radius: 6px;
			overflow: hidden;
			text-align: center;
			padding: 6px;
		}
		.evg-fault-card img {
			width: 100%;
			height: 90px;
			object-fit: cover;
			border-radius: 4px;
		}
		.evg-fault-card span {
			display: block;
			font-size: 11px;
			color: #d4af37;
			font-weight: 700;
			margin-top: 4px;
			text-transform: capitalize;
		}

		.evg-checklist-group {
			display: flex;
			flex-direction: column;
			gap: 16px;
		}
		.evg-check-item {
			display: flex;
			align-items: flex-start;
			gap: 12px;
			background: #141416;
			border: 1px solid #222224;
			padding: 14px;
			border-radius: 8px;
			cursor: pointer;
			transition: border-color 0.2s ease;
		}
		.evg-check-item:hover {
			border-color: #333336;
		}
		.evg-check-item input[type="checkbox"] {
			margin-top: 3px;
			width: 18px;
			height: 18px;
			accent-color: #d4af37;
			cursor: pointer;
		}
		.evg-check-item span {
			font-size: 13px;
			color: #e5e5ea;
			line-height: 1.4;
		}

		.evg-btn-approve {
			width: 100%;
			background: #d4af37;
			color: #0a0a0a;
			border: none;
			padding: 15px;
			font-size: 13px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.2s ease;
		}
		.evg-btn-approve:hover {
			background: #f3e5ab;
			box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
		}

		.evg-btn-reject {
			width: 100%;
			background: transparent;
			border: 1px solid #ff453a;
			color: #ff453a;
			padding: 12px;
			font-size: 12px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.5px;
			border-radius: 8px;
			cursor: pointer;
			margin-top: 10px;
			transition: all 0.2s ease;
		}
		.evg-btn-reject:hover {
			background: #ff453a;
			color: #ffffff;
		}

		@media (max-width: 992px) {
			.evg-qc-split-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<div class="evg-qc-header">
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>" style="color: #d4af37; text-decoration: none; font-size: 13px; font-weight: 700; margin-bottom: 8px; display: inline-block;">
				&larr; <?php esc_html_e( 'Back to QC Queue', 'evg' ); ?>
			</a>
			<h1><?php printf( esc_html__( 'Quality Control Verification: %s', 'evg' ), esc_html( $evg_card->card_name ) ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: Order reference number, 2: Grader name */
					esc_html__( 'Order Ref #%1$s — Assessed by %2$s', 'evg' ),
					esc_html( $evg_card->order_number ),
					esc_html( $evg_grader_name )
				);
				?>
			</p>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>">
		<?php wp_nonce_field( 'evg_qc_action', 'evg_qc_action_nonce' ); ?>
		<input type="hidden" name="card_id" value="<?php echo esc_attr( $evg_card->id ); ?>">
		<input type="hidden" name="submission_id" value="<?php echo esc_attr( $evg_card->submission_id ); ?>">

		<div class="evg-qc-split-grid">
			<!-- Left Panel: Label Data Verification & Defect Evidence -->
			<div>
				<div class="evg-qc-box">
					<div class="evg-qc-box-header">
						<h2><?php esc_html_e( 'Label Specification Verification', 'evg' ); ?></h2>
					</div>
					<div class="evg-qc-box-body">
						<table class="evg-verify-table">
							<tr>
								<td><?php esc_html_e( 'Certified Grade', 'evg' ); ?></td>
								<td><span class="evg-qc-grade-badge">EVG <?php echo esc_html( $evg_card->final_grade ); ?></span></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Card Name', 'evg' ); ?></td>
								<td><strong style="color:#ffffff;"><?php echo esc_html( $evg_card->card_name ); ?></strong></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Set Name', 'evg' ); ?></td>
								<td><?php echo esc_html( $evg_card->set_name ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Card Number', 'evg' ); ?></td>
								<td>#<?php echo esc_html( $evg_card->card_number ? $evg_card->card_number : 'N/A' ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Language', 'evg' ); ?></td>
								<td><?php echo esc_html( $evg_card->language ); ?></td>
							</tr>
							<tr>
								<td><?php esc_html_e( 'Selected Slab Design', 'evg' ); ?></td>
								<td><span style="color:#d4af37; font-weight:700;"><?php echo esc_html( $evg_card->label_option ); ?></span></td>
							</tr>
						</table>

						<?php if ( $evg_assessment ) : ?>
							<div class="evg-subgrades-strip">
								<span style="font-size:11px; text-transform:uppercase; color:#8e8e93; font-weight:700; letter-spacing:0.5px;">
									<?php esc_html_e( 'Grader Assessment Sub-Scores:', 'evg' ); ?>
								</span>
								<div class="evg-subgrades-grid">
									<div class="evg-subgrade-cell">
										<span><?php esc_html_e( 'Centring', 'evg' ); ?></span>
										<strong><?php echo esc_html( (int) $evg_assessment->centreing_score ); ?></strong>
									</div>
									<div class="evg-subgrade-cell">
										<span><?php esc_html_e( 'Corners', 'evg' ); ?></span>
										<strong><?php echo esc_html( (int) $evg_assessment->corner_score ); ?></strong>
									</div>
									<div class="evg-subgrade-cell">
										<span><?php esc_html_e( 'Edges', 'evg' ); ?></span>
										<strong><?php echo esc_html( (int) $evg_assessment->edge_score ); ?></strong>
									</div>
									<div class="evg-subgrade-cell">
										<span><?php esc_html_e( 'Surface', 'evg' ); ?></span>
										<strong><?php echo esc_html( (int) $evg_assessment->surface_score ); ?></strong>
									</div>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Photographic Fault Evidence Inspector -->
				<div class="evg-qc-box">
					<div class="evg-qc-box-header">
						<h2><?php esc_html_e( 'Photographic Fault Evidence', 'evg' ); ?></h2>
					</div>
					<div class="evg-qc-box-body">
						<?php if ( ! empty( $evg_fault_images ) ) : ?>
							<div class="evg-fault-gallery">
								<?php foreach ( $evg_fault_images as $f ) : ?>
									<div class="evg-fault-card">
										<a href="<?php echo esc_url( $f->image_url ); ?>" target="_blank">
											<img src="<?php echo esc_url( $f->image_url ); ?>" alt="<?php echo esc_attr( $f->fault_type ); ?>">
										</a>
										<span><?php echo esc_html( $f->fault_type ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p style="color: #8e8e93; font-size: 12px; margin: 0;">
								<?php esc_html_e( 'No specific defect photos logged for this card assessment.', 'evg' ); ?>
							</p>
						<?php endif; ?>
					</div>
				</div>
			</div>

			<!-- Right Panel: Step 5 QC Protocol Checklist -->
			<div class="evg-qc-box">
				<div class="evg-qc-box-header">
					<h2><?php esc_html_e( 'Final QC Protocol Checklist', 'evg' ); ?></h2>
				</div>
				<div class="evg-qc-box-body">
					<div class="evg-checklist-group">
						<label class="evg-check-item">
							<input type="checkbox" name="qc_check_label" required>
							<span><?php esc_html_e( 'Verified correct card information matches the label exactly.', 'evg' ); ?></span>
						</label>
						<label class="evg-check-item">
							<input type="checkbox" name="qc_check_grade" required>
							<span><?php esc_html_e( 'Verified the final grade printed on the label is correct (whole numbers 1-10 only).', 'evg' ); ?></span>
						</label>
						<label class="evg-check-item">
							<input type="checkbox" name="qc_check_slab" required>
							<span><?php esc_html_e( 'Verified slab quality (no scratches, dust-free, tamper-evident seals intact).', 'evg' ); ?></span>
						</label>
						<label class="evg-check-item">
							<input type="checkbox" name="qc_check_standards" required>
							<span><?php esc_html_e( 'Verified overall presentation meets EVG certification standards.', 'evg' ); ?></span>
						</label>

						<!-- Transparency Report Publishing Control -->
						<label class="evg-check-item" style="border-color: rgba(212, 175, 55, 0.3); background: rgba(212, 175, 55, 0.05);">
							<input type="checkbox" name="transparency_published" value="1" <?php checked( (int) $evg_card->transparency_published, 1 ); ?>>
							<span style="color: #d4af37;">
								<strong><?php esc_html_e( 'Publish Transparency Report:', 'evg' ); ?></strong>
								<?php esc_html_e( 'Authorise customer to view sub-scores & photographic fault evidence in their dashboard.', 'evg' ); ?>
							</span>
						</label>
					</div>

					<div style="margin-top: 24px;">
						<button type="submit" name="qc_action" value="approve" class="evg-btn-approve">
							&#128737; <?php esc_html_e( 'Pass QC & Authorize Encapsulation', 'evg' ); ?>
						</button>
						<button type="submit" name="qc_action" value="reject" class="evg-btn-reject" formnovalidate onclick="return confirm('<?php echo esc_js( __( 'Reject QC check and return card to the Grading Desk?', 'evg' ) ); ?>');">
							&times; <?php esc_html_e( 'Reject & Return to Grading Desk', 'evg' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	</form>
	<?php
}