<?php
/**
 * EVG Module: Grading Desk (Pro Edition)
 * Terminal for precision grading assessments, manual EVG card number entry (starting at 0100),
 * category sub-scores, batch fault photo uploader, and client free preview designations.
 * Pagination configured to 5 items per page.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST actions for the Grading Desk (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_grading_desk_actions' );

function evg_handle_grading_desk_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_grading-desk' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'head_grader', 'grader' ) ) ) {
		return;
	}

	global $wpdb;

	$evg_table_cards       = $wpdb->prefix . 'evg_cards';
	$evg_table_assessments = $wpdb->prefix . 'evg_assessments';
	$evg_table_faults      = $wpdb->prefix . 'evg_fault_images';
	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';

	// ---------------------------------------------------------
	// Handle Form Submissions (Saving Grade, Card Number, Faults)
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_save_grade_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_save_grade_nonce'] ) ), 'evg_save_grade' ) ) {

			$evg_card_id         = isset( $_POST['card_id'] ) ? absint( $_POST['card_id'] ) : 0;
			$evg_submission_id   = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
			$evg_cert_number     = isset( $_POST['cert_number'] ) ? sanitize_text_field( wp_unslash( $_POST['cert_number'] ) ) : '0100';
			$evg_center_score    = isset( $_POST['centreing_score'] ) ? max( 1, min( 10, (int) $_POST['centreing_score'] ) ) : 1;
			$evg_corner_score    = isset( $_POST['corner_score'] ) ? max( 1, min( 10, (int) $_POST['corner_score'] ) ) : 1;
			$evg_edge_score      = isset( $_POST['edge_score'] ) ? max( 1, min( 10, (int) $_POST['edge_score'] ) ) : 1;
			$evg_surface_score   = isset( $_POST['surface_score'] ) ? max( 1, min( 10, (int) $_POST['surface_score'] ) ) : 1;
			$evg_internal_notes  = isset( $_POST['internal_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['internal_notes'] ) ) : '';
			$evg_grader_comments = isset( $_POST['grader_comments'] ) ? sanitize_textarea_field( wp_unslash( $_POST['grader_comments'] ) ) : '';
			$evg_final_grade     = isset( $_POST['final_grade'] ) ? max( 1, min( 10, (int) $_POST['final_grade'] ) ) : 10;

			$evg_current_user_id = get_current_user_id();

			// 1. Insert or Update Assessment Record.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$evg_existing_assessment = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$evg_table_assessments} WHERE card_id = %d", $evg_card_id ) );

			$evg_assessment_data = array(
				'grader_id'       => $evg_current_user_id,
				'centreing_score' => number_format( (float) $evg_center_score, 2, '.', '' ),
				'corner_score'    => number_format( (float) $evg_corner_score, 2, '.', '' ),
				'edge_score'      => number_format( (float) $evg_edge_score, 2, '.', '' ),
				'surface_score'   => number_format( (float) $evg_surface_score, 2, '.', '' ),
				'internal_notes'  => $evg_internal_notes,
				'grader_comments' => $evg_grader_comments,
				'assessed_date'   => current_time( 'mysql' ),
			);
			$evg_assessment_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

			if ( $evg_existing_assessment ) {
				$wpdb->update(
					$evg_table_assessments,
					$evg_assessment_data,
					array( 'id' => $evg_existing_assessment ),
					$evg_assessment_formats,
					array( '%d' )
				);
				$evg_assessment_id = $evg_existing_assessment;
			} else {
				$evg_assessment_data['card_id'] = $evg_card_id;
				array_unshift( $evg_assessment_formats, '%d' );

				$wpdb->insert(
					$evg_table_assessments,
					$evg_assessment_data,
					$evg_assessment_formats
				);
				$evg_assessment_id = $wpdb->insert_id;
			}

			// 2. Sync Fault Evidence Images & Apply Max 3 Free Previews.
			$wpdb->delete( $evg_table_faults, array( 'card_id' => $evg_card_id ), array( '%d' ) );

			if ( isset( $_POST['fault_images'] ) && is_array( $_POST['fault_images'] ) ) {
				$evg_fault_types       = isset( $_POST['fault_types'] ) && is_array( $_POST['fault_types'] ) ? (array) wp_unslash( $_POST['fault_types'] ) : array();
				$evg_raw_free_previews = isset( $_POST['free_preview_indices'] ) && is_array( $_POST['free_preview_indices'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['free_preview_indices'] ) ) : array();

				if ( count( $evg_raw_free_previews ) > 3 ) {
					$evg_raw_free_previews = array_slice( $evg_raw_free_previews, 0, 3 );
				}

				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$evg_fault_images = array_values( (array) wp_unslash( $_POST['fault_images'] ) );

				foreach ( $evg_fault_images as $index => $fault_url ) {
					$evg_clean_url       = esc_url_raw( $fault_url );
					$evg_fault_type      = isset( $evg_fault_types[ $index ] ) ? sanitize_text_field( $evg_fault_types[ $index ] ) : 'Surface Scratch';
					$evg_is_free_preview = in_array( (int) $index, $evg_raw_free_previews, true ) ? 1 : 0;

					if ( ! empty( $evg_clean_url ) ) {
						$wpdb->insert(
							$evg_table_faults,
							array(
								'assessment_id'   => $evg_assessment_id,
								'card_id'         => $evg_card_id,
								'fault_type'      => $evg_fault_type,
								'image_url'       => $evg_clean_url,
								'is_free_preview' => $evg_is_free_preview,
								'notes'           => '',
								'created_at'      => current_time( 'mysql' ),
							),
							array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
						);
					}
				}
			}

			// 3. Update Global Card Record.
			$wpdb->update(
				$evg_table_cards,
				array(
					'cert_number'    => $evg_cert_number,
					'final_grade'    => $evg_final_grade,
					'grading_status' => 'Quality Control',
				),
				array( 'id' => $evg_card_id ),
				array( '%s', '%d', '%s' ),
				array( '%d' )
			);

			// 4. Update Submission Pipeline Stage if all cards completed.
			if ( $evg_submission_id > 0 ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$evg_ungraded = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$evg_table_cards} WHERE submission_id = %d AND grading_status NOT IN ('Quality Control', 'Encapsulation', 'Completed')", $evg_submission_id ) );
				if ( 0 === $evg_ungraded ) {
					$wpdb->update( $evg_table_submissions, array( 'current_stage' => 'Quality Control' ), array( 'id' => $evg_submission_id ), array( '%s' ), array( '%d' ) );
				}
			}

			if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
				Elite_Vault_Grading_System::log_activity( "Assessed Card ID {$evg_card_id} with EVG Card Number: {$evg_cert_number}, Final Grade EVG {$evg_final_grade} and updated damage portfolio." );
			}

			evg_grading_desk_safe_redirect( admin_url( 'admin.php?page=evg_tab_grading-desk&graded=1' ) );
		}
	}
}

/**
 * Universal redirect helper.
 *
 * @param string $location Destination URL.
 */
function evg_grading_desk_safe_redirect( $location ) {
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
 * Main render function for the Grading Desk tab.
 */
function evg_grading_desk_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'head_grader', 'grader' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access the Grading Desk.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;

	$evg_table_cards       = $wpdb->prefix . 'evg_cards';
	$evg_table_assessments = $wpdb->prefix . 'evg_assessments';
	$evg_table_faults      = $wpdb->prefix . 'evg_fault_images';
	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['graded'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Assessment and damage portfolio successfully sealed. EVG Card Number assigned and card dispatched to QC.', 'evg' ) . '</p></div>';
	}

	$evg_action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	$evg_card_id = isset( $_GET['card_id'] ) ? absint( $_GET['card_id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'grade' === $evg_action && $evg_card_id > 0 ) {
		evg_render_pro_grading_terminal( $evg_card_id, $evg_table_cards, $evg_table_submissions, $evg_table_assessments, $evg_table_faults );
	} else {
		evg_render_pro_grading_queue( $evg_table_cards, $evg_table_submissions );
	}
}

/**
 * Queue View: Cards Awaiting Grading
 *
 * @param string $evg_table_cards       Cards table name.
 * @param string $evg_table_submissions Submissions table name.
 */
function evg_render_pro_grading_queue( $evg_table_cards, $evg_table_submissions ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_cards = $wpdb->get_results(
		"SELECT c.*, s.order_number, s.label_option, s.submission_date 
		FROM {$evg_table_cards} c
		JOIN {$evg_table_submissions} s ON c.submission_id = s.id
		WHERE c.grading_status IN ('Pending', 'Under Review', 'Grading In Progress')
		ORDER BY s.submission_date ASC"
	);
	?>
	<style>
		:root {
			--evg-gold: #d4af37;
			--evg-border: #222224;
			--evg-bg-card: #0f0f11;
			--evg-text-muted: #8e8e93;
		}

		.evg-desk-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 28px;
			flex-wrap: wrap;
			gap: 15px;
		}
		.evg-desk-header h1 {
			font-size: 26px;
			font-weight: 800;
			margin: 0 0 6px 0;
			color: #ffffff;
			letter-spacing: -0.5px;
		}
		.evg-desk-header p {
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

		.evg-queue-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}
		.evg-queue-table thead th {
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
		.evg-queue-table tbody td {
			padding: 18px 20px;
			border-bottom: 1px solid #1a1a1d;
			color: #ffffff;
			font-size: 13px;
			vertical-align: middle;
		}
		.evg-queue-table tbody tr {
			transition: background 0.2s ease;
		}
		.evg-queue-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.02);
		}
		.evg-queue-table tbody tr:last-child td {
			border-bottom: none;
		}

		.evg-badge-label {
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
		.evg-badge-state {
			display: inline-block;
			background: rgba(255, 159, 10, 0.08);
			border: 1px solid rgba(255, 159, 10, 0.25);
			color: #ff9f0a;
			font-size: 11px;
			font-weight: 700;
			padding: 4px 10px;
			border-radius: 20px;
			white-space: nowrap;
		}

		.evg-btn-grade {
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
		.evg-btn-grade:hover {
			background: #f3e5ab;
			transform: translateY(-1px);
			box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
			color: #000000 !important;
		}

		/* DataTables UI Customizations */
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

	<div class="evg-desk-header">
		<div>
			<h1><?php esc_html_e( 'Grading Queue', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Authorized grader workspace. Select a pending unit to begin physical assessment.', 'evg' ); ?></p>
		</div>
		<div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
			<?php
			printf(
				/* translators: %s: Formatted count of cards awaiting inspection */
				esc_html__( '%s Cards Awaiting Inspection', 'evg' ),
				esc_html( number_format_i18n( count( $evg_cards ) ) )
			);
			?>
		</div>
	</div>

	<div class="evg-panel-shell">
		<table class="evg-queue-table evg-queue-datatable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Card Identification', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Set / Number', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Assigned EVG #', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Language', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Pipeline Status', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Terminal', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_cards ) ) : ?>
					<?php foreach ( $evg_cards as $card ) : ?>
						<tr>
							<td>
								<span style="font-family: monospace; font-weight: 700; color: var(--evg-gold);">
									#<?php echo esc_html( $card->order_number ); ?>
								</span>
							</td>
							<td>
								<strong style="font-size: 14px; color: #ffffff;"><?php echo esc_html( $card->card_name ); ?></strong><br>
								<span class="evg-badge-label"><?php echo esc_html( $card->label_option ); ?></span>
							</td>
							<td>
								<span style="color: #ffffff;"><?php echo esc_html( $card->set_name ); ?></span><br>
								<span style="color: var(--evg-text-muted); font-size: 12px;">#<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?></span>
							</td>
							<td>
								<span style="font-family: monospace; color: var(--evg-gold); font-weight: 800; font-size: 13px;">
									<?php echo esc_html( $card->cert_number ? $card->cert_number : __( 'Pending', 'evg' ) ); ?>
								</span>
							</td>
							<td style="color: var(--evg-text-muted); font-weight: 600;">
								<?php echo esc_html( $card->language ); ?>
							</td>
							<td>
								<span class="evg-badge-state"><?php echo esc_html( $card->grading_status ); ?></span>
							</td>
							<td style="text-align: right;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_grading-desk&action=grade&card_id=' . $card->id ) ); ?>" class="evg-btn-grade">
									&#128300; <?php esc_html_e( 'Assess', 'evg' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="7" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
							<?php esc_html_e( 'No cards currently awaiting assessment in the queue.', 'evg' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-queue-datatable tbody tr').length > 0 && $('.evg-queue-datatable tbody tr td').length > 1) {
				$('.evg-queue-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, -1], [10, 25, 50, "All"] ],
					"order": [[ 0, "asc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Filter Queue:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Display _MENU_ cards per page', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ cards in queue', 'evg' ) ); ?>",
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
 * Terminal View: Assessment Console, Multi-Photo Uploader & Free Preview Selector
 *
 * @param int    $card_id               Inspected card ID.
 * @param string $table_cards           Cards DB table name.
 * @param string $table_submissions     Submissions DB table name.
 * @param string $table_assessments     Assessments DB table name.
 * @param string $table_faults          Faults DB table name.
 */
function evg_render_pro_grading_terminal( $card_id, $table_cards, $table_submissions, $table_assessments, $table_faults ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_card = $wpdb->get_row( $wpdb->prepare( "SELECT c.*, s.order_number, s.label_option FROM {$table_cards} c JOIN {$table_submissions} s ON c.submission_id = s.id WHERE c.id = %d", $card_id ) );
	if ( ! $evg_card ) {
		echo '<p style="color:#ff453a;">' . esc_html__( 'Card record not found in system.', 'evg' ) . '</p>';
		return;
	}

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_assessment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_assessments} WHERE card_id = %d", $card_id ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_faults     = $wpdb->get_results( $wpdb->prepare( "SELECT image_url, fault_type, is_free_preview FROM {$table_faults} WHERE card_id = %d ORDER BY id ASC", $card_id ) );

	$evg_fault_types_available = array(
		'Surface Scratch'     => __( 'Surface Scratch', 'evg' ),
		'Edge Whitening'      => __( 'Edge Whitening', 'evg' ),
		'Corner Dent'         => __( 'Corner Dent', 'evg' ),
		'Holo Print Line'     => __( 'Holo Print Line', 'evg' ),
		'Centering Imbalance' => __( 'Centering Imbalance', 'evg' ),
		'Surface Damage'      => __( 'Surface Damage', 'evg' ),
		'Corner Imperfection' => __( 'Corner Imperfection', 'evg' ),
		'Edge Wear'           => __( 'Edge Wear', 'evg' ),
		'Other'               => __( 'Other Fault', 'evg' ),
	);
	?>
	<style>
		.evg-terminal-layout {
			display: grid;
			grid-template-columns: 360px 1fr;
			gap: 24px;
			align-items: start;
		}

		.evg-card-box {
			background: #0f0f11;
			border: 1px solid #222224;
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
		}
		.evg-card-box-header {
			padding: 18px 20px;
			background: #141416;
			border-bottom: 1px solid #1f1f22;
			display: flex;
			justify-content: space-between;
			align-items: center;
		}
		.evg-card-box-header h2 {
			margin: 0;
			font-size: 13px;
			font-weight: 700;
			color: #ffffff;
			text-transform: uppercase;
			letter-spacing: 0.8px;
		}
		.evg-card-box-body {
			padding: 20px;
		}

		.evg-info-list {
			list-style: none;
			margin: 0;
			padding: 0;
		}
		.evg-info-list li {
			display: flex;
			justify-content: space-between;
			padding: 9px 0;
			font-size: 12px;
			border-bottom: 1px solid #18181a;
		}
		.evg-info-list li:last-child {
			border-bottom: none;
		}
		.evg-info-list .key {
			color: #8e8e93;
		}
		.evg-info-list .val {
			color: #ffffff;
			font-weight: 600;
		}

		/* EVG Manual Card Number Assignment Input */
		.evg-cert-assign-box {
			background: #141416;
			border: 1px solid #d4af37;
			border-radius: 10px;
			padding: 16px 20px;
			margin-bottom: 24px;
			display: flex;
			justify-content: space-between;
			align-items: center;
			gap: 15px;
		}
		.evg-cert-assign-box label {
			font-size: 12px;
			font-weight: 800;
			color: #d4af37;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			display: block;
			margin-bottom: 4px;
		}
		.evg-cert-assign-box input {
			background: #0a0a0a;
			border: 1px solid #28282b;
			color: #ffffff;
			font-family: monospace;
			font-size: 18px;
			font-weight: 800;
			padding: 8px 14px;
			border-radius: 6px;
			width: 140px;
			text-align: center;
			outline: none;
		}
		.evg-cert-assign-box input:focus {
			border-color: #d4af37;
			box-shadow: 0 0 10px rgba(212, 175, 55, 0.3);
		}

		.evg-score-matrix {
			display: grid;
			grid-template-columns: repeat(4, 1fr);
			gap: 16px;
			margin-bottom: 24px;
		}
		.evg-score-card {
			background: #141416;
			border: 1px solid #222224;
			border-radius: 10px;
			padding: 16px;
			text-align: center;
			transition: border-color 0.2s ease;
		}
		.evg-score-card:focus-within {
			border-color: #d4af37;
		}
		.evg-score-card label {
			display: block;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			color: #d4af37;
			margin-bottom: 10px;
		}
		.evg-score-field {
			width: 100%;
			height: 48px;
			background: #0a0a0a;
			border: 1px solid #2a2a2e;
			border-radius: 8px;
			color: #ffffff;
			font-size: 22px;
			font-weight: 800;
			text-align: center;
			outline: none;
			box-sizing: border-box;
		}
		.evg-score-field:focus {
			border-color: #d4af37;
			box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.15);
		}

		.evg-final-banner {
			display: flex;
			justify-content: space-between;
			align-items: center;
			background: linear-gradient(90deg, #18181a 0%, #121214 100%);
			border: 2px solid #d4af37;
			border-radius: 12px;
			padding: 24px;
			margin: 24px 0;
		}
		.evg-final-title {
			font-size: 17px;
			font-weight: 800;
			color: #ffffff;
			margin: 0 0 4px 0;
		}
		.evg-final-subtitle {
			font-size: 12px;
			color: #8e8e93;
			margin: 0;
		}
		.evg-grade-select {
			width: 130px;
			height: 56px;
			background-color: #0a0a0a !important;
			border: 1px solid #d4af37 !important;
			border-radius: 8px;
			color: #d4af37 !important;
			font-size: 24px;
			font-weight: 900;
			text-align: center;
			padding-left: 14px;
			outline: none;
			cursor: pointer;
		}
		.evg-grade-select option {
			background-color: #141416 !important;
			color: #ffffff !important;
		}

		.evg-text-area {
			width: 100%;
			background: #141416;
			border: 1px solid #222224;
			border-radius: 8px;
			color: #ffffff;
			padding: 12px 14px;
			font-size: 13px;
			outline: none;
			box-sizing: border-box;
			resize: vertical;
		}
		.evg-text-area:focus {
			border-color: #d4af37;
		}

		/* Defect Portfolio Styles */
		.evg-fault-grid {
			display: grid;
			grid-template-columns: 1fr;
			gap: 12px;
			margin-bottom: 12px;
		}
		.evg-fault-cell {
			position: relative;
			background: #141416;
			border: 1px solid #2a2a2e;
			border-radius: 8px;
			padding: 10px;
			display: flex;
			gap: 12px;
			align-items: center;
			transition: border-color 0.2s ease;
		}
		.evg-fault-cell.is-preview-active {
			border-color: rgba(212, 175, 55, 0.4);
			background: #17171a;
		}
		.evg-fault-cell img {
			width: 64px;
			height: 64px;
			object-fit: cover;
			border-radius: 6px;
			flex-shrink: 0;
			border: 1px solid #222;
		}
		.evg-fault-meta {
			flex: 1;
			display: flex;
			flex-direction: column;
			gap: 6px;
			min-width: 0;
		}
		.evg-fault-cell select {
			background-color: #0a0a0a !important;
			border: 1px solid #28282b !important;
			color: #d4af37 !important;
			padding: 5px 8px;
			font-size: 11px;
			border-radius: 4px;
			width: 100%;
			outline: none;
		}
		.evg-fault-cell select option {
			background-color: #141416 !important;
			color: #ffffff !important;
		}
		.evg-preview-toggle-wrap {
			display: flex;
			align-items: center;
			gap: 6px;
			cursor: pointer;
			user-select: none;
		}
		.evg-preview-toggle-wrap input[type="checkbox"] {
			margin: 0;
			accent-color: #d4af37;
			cursor: pointer;
		}
		.evg-preview-toggle-wrap span {
			font-size: 11px;
			font-weight: 700;
			color: #8e8e93;
			text-transform: uppercase;
			letter-spacing: 0.4px;
		}
		.evg-preview-toggle-wrap input[type="checkbox"]:checked + span {
			color: #d4af37;
		}

		.evg-fault-cell .btn-del-img {
			width: 24px;
			height: 24px;
			background: rgba(0, 0, 0, 0.85);
			border: 1px solid #ff453a;
			color: #ff453a;
			border-radius: 50%;
			cursor: pointer;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: 12px;
			font-weight: bold;
			flex-shrink: 0;
			transition: all 0.2s ease;
		}
		.evg-fault-cell .btn-del-img:hover {
			background: #ff453a;
			color: #ffffff;
		}

		.evg-btn-submit {
			width: 100%;
			background: #d4af37;
			color: #0a0a0a;
			border: none;
			padding: 16px;
			font-size: 14px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			border-radius: 8px;
			cursor: pointer;
			transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
		}
		.evg-btn-submit:hover {
			background: #f3e5ab;
			box-shadow: 0 6px 20px rgba(212, 175, 55, 0.3);
		}

		.evg-counter-badge {
			background: #1a1a1d;
			border: 1px solid #2c2c30;
			color: #d4af37;
			font-size: 10px;
			font-weight: 800;
			padding: 2px 8px;
			border-radius: 12px;
			font-family: monospace;
		}

		@media (max-width: 980px) {
			.evg-terminal-layout {
				grid-template-columns: 1fr;
			}
			.evg-score-matrix {
				grid-template-columns: repeat(2, 1fr);
			}
		}
	</style>

	<div class="evg-desk-header">
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_grading-desk' ) ); ?>" style="color: #d4af37; text-decoration: none; font-size: 13px; font-weight: 700; margin-bottom: 8px; display: inline-block;">
				&larr; <?php esc_html_e( 'Back to Grading Queue', 'evg' ); ?>
			</a>
			<h1><?php echo esc_html( $evg_card->card_name ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: Submission Order number, 2: Card Set Name */
					esc_html__( 'Submission Order Ref #%1$s — Set: %2$s', 'evg' ),
					esc_html( $evg_card->order_number ),
					esc_html( $evg_card->set_name )
				);
				?>
			</p>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_grading-desk' ) ); ?>" id="evg-grading-form">
		<?php wp_nonce_field( 'evg_save_grade', 'evg_save_grade_nonce' ); ?>
		<input type="hidden" name="card_id" value="<?php echo esc_attr( $evg_card->id ); ?>">
		<input type="hidden" name="submission_id" value="<?php echo esc_attr( $evg_card->submission_id ); ?>">

		<div class="evg-terminal-layout">
			<!-- Sidebar: Specification & Fault Evidence -->
			<div>
				<div class="evg-card-box">
					<div class="evg-card-box-header">
						<h2><?php esc_html_e( 'Card Specification', 'evg' ); ?></h2>
					</div>
					<div class="evg-card-box-body">
						<ul class="evg-info-list">
							<li><span class="key"><?php esc_html_e( 'Order Ref', 'evg' ); ?></span><span class="val" style="color: #d4af37; font-family: monospace;">#<?php echo esc_html( $evg_card->order_number ); ?></span></li>
							<li><span class="key"><?php esc_html_e( 'Set Name', 'evg' ); ?></span><span class="val"><?php echo esc_html( $evg_card->set_name ); ?></span></li>
							<li><span class="key"><?php esc_html_e( 'Card Set Number', 'evg' ); ?></span><span class="val">#<?php echo esc_html( $evg_card->card_number ? $evg_card->card_number : 'N/A' ); ?></span></li>
							<li><span class="key"><?php esc_html_e( 'Language', 'evg' ); ?></span><span class="val"><?php echo esc_html( $evg_card->language ); ?></span></li>
							<li><span class="key"><?php esc_html_e( 'Declared State', 'evg' ); ?></span><span class="val"><?php echo esc_html( $evg_card->estimated_condition ? $evg_card->estimated_condition : __( 'Raw', 'evg' ) ); ?></span></li>
							<li><span class="key"><?php esc_html_e( 'Selected Slab', 'evg' ); ?></span><span class="val" style="color: #d4af37;"><?php echo esc_html( $evg_card->label_option ); ?></span></li>
						</ul>

						<?php if ( ! empty( $evg_card->customer_notes ) ) : ?>
							<div style="margin-top: 14px; padding: 12px; background: #141416; border-radius: 6px; border: 1px solid #222224; font-size: 12px; color: #8e8e93;">
								<strong style="color: #ffffff; display: block; margin-bottom: 2px;"><?php esc_html_e( 'Collector Notes:', 'evg' ); ?></strong>
								<em>"<?php echo esc_html( $evg_card->customer_notes ); ?>"</em>
							</div>
						<?php endif; ?>
					</div>
				</div>

				<!-- Fault Evidence Panel -->
				<div class="evg-card-box" style="margin-top: 20px;">
					<div class="evg-card-box-header">
						<h2><?php esc_html_e( 'Fault & Defect Evidence', 'evg' ); ?></h2>
						<span id="evg-free-counter" class="evg-counter-badge"><?php esc_html_e( '0/3 Free Previews', 'evg' ); ?></span>
					</div>
					<div class="evg-card-box-body">
						<p style="font-size: 11px; color: #8e8e93; margin: 0 0 12px 0; line-height: 1.5;">
							<?php esc_html_e( 'Upload defect scans at once. Check up to 3 for free preview; remaining scans blur behind the £0.99 portfolio paywall.', 'evg' ); ?>
						</p>

						<div id="evg-fault-preview-container" class="evg-fault-grid">
							<?php if ( ! empty( $evg_faults ) ) : ?>
								<?php foreach ( $evg_faults as $index => $fault ) : ?>
									<div class="evg-fault-cell <?php echo $fault->is_free_preview ? 'is-preview-active' : ''; ?>">
										<img src="<?php echo esc_url( $fault->image_url ); ?>" alt="<?php esc_attr_e( 'Fault Evidence', 'evg' ); ?>">
										<div class="evg-fault-meta">
											<input type="hidden" name="fault_images[<?php echo esc_attr( $index ); ?>]" value="<?php echo esc_attr( $fault->image_url ); ?>">
											<select name="fault_types[<?php echo esc_attr( $index ); ?>]">
												<?php foreach ( $evg_fault_types_available as $ft_key => $ft_label ) : ?>
													<option value="<?php echo esc_attr( $ft_key ); ?>" <?php selected( $fault->fault_type, $ft_key ); ?>><?php echo esc_html( $ft_label ); ?></option>
												<?php endforeach; ?>
											</select>
											<label class="evg-preview-toggle-wrap">
												<input type="checkbox" name="free_preview_indices[]" value="<?php echo esc_attr( $index ); ?>" class="evg-preview-checkbox" <?php checked( (int) $fault->is_free_preview, 1 ); ?>>
												<span><?php esc_html_e( 'Free Preview (Max 3)', 'evg' ); ?></span>
											</label>
										</div>
										<button type="button" class="btn-del-img" title="<?php esc_attr_e( 'Remove Photo', 'evg' ); ?>">&times;</button>
									</div>
								<?php endforeach; ?>
							<?php endif; ?>
						</div>

						<button type="button" id="evg-upload-fault" class="evg-btn-submit" style="background: #1c1c1f; color: #d4af37; border: 1px solid #2c2c30; padding: 11px; font-size: 12px; margin-top: 4px;">
							+ <?php esc_html_e( 'Batch Upload Defect Photos', 'evg' ); ?>
						</button>
					</div>
				</div>
			</div>

			<!-- Main: Scoring Console -->
			<div>
				<div class="evg-card-box">
					<div class="evg-card-box-header">
						<h2><?php esc_html_e( 'Category Assessment Criteria (1-10 Whole Scale)', 'evg' ); ?></h2>
					</div>
					<div class="evg-card-box-body">

						<!-- Manual EVG Card Number Assignment -->
						<div class="evg-cert-assign-box">
							<div>
								<label><?php esc_html_e( 'EVG Card Number / Certificate ID *', 'evg' ); ?></label>
								<span style="font-size: 11px; color: #8e8e93; display: block;">
									<?php esc_html_e( 'Enter the manual grading number for this physical specimen (starting from 0100).', 'evg' ); ?>
								</span>
							</div>
							<div>
								<input type="text" name="cert_number" value="<?php echo esc_attr( ! empty( $evg_card->cert_number ) ? $evg_card->cert_number : '0100' ); ?>" required placeholder="0100">
							</div>
						</div>

						<!-- Sub-Category Matrix -->
						<div class="evg-score-matrix">
							<div class="evg-score-card">
								<label><?php esc_html_e( 'Centring', 'evg' ); ?></label>
								<input type="number" id="sub_centre" name="centreing_score" class="evg-score-field sub-calc" min="1" max="10" step="1" required value="<?php echo $evg_assessment ? esc_attr( (int) $evg_assessment->centreing_score ) : ''; ?>">
							</div>
							<div class="evg-score-card">
								<label><?php esc_html_e( 'Corners', 'evg' ); ?></label>
								<input type="number" id="sub_corner" name="corner_score" class="evg-score-field sub-calc" min="1" max="10" step="1" required value="<?php echo $evg_assessment ? esc_attr( (int) $evg_assessment->corner_score ) : ''; ?>">
							</div>
							<div class="evg-score-card">
								<label><?php esc_html_e( 'Edges', 'evg' ); ?></label>
								<input type="number" id="sub_edge" name="edge_score" class="evg-score-field sub-calc" min="1" max="10" step="1" required value="<?php echo $evg_assessment ? esc_attr( (int) $evg_assessment->edge_score ) : ''; ?>">
							</div>
							<div class="evg-score-card">
								<label><?php esc_html_e( 'Surface', 'evg' ); ?></label>
								<input type="number" id="sub_surface" name="surface_score" class="evg-score-field sub-calc" min="1" max="10" step="1" required value="<?php echo $evg_assessment ? esc_attr( (int) $evg_assessment->surface_score ) : ''; ?>">
							</div>
						</div>

						<!-- Grader Comments -->
						<div style="margin-bottom: 18px;">
							<label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #8e8e93; margin-bottom: 6px;">
								<?php esc_html_e( 'Internal Inspection Notes (Confidential Staff Only)', 'evg' ); ?>
							</label>
							<textarea name="internal_notes" class="evg-text-area" rows="3" placeholder="<?php esc_attr_e( 'Document micro-flaws, alterations, or authenticity reasoning...', 'evg' ); ?>"><?php echo $evg_assessment ? esc_textarea( $evg_assessment->internal_notes ) : ''; ?></textarea>
						</div>

						<div style="margin-bottom: 18px;">
							<label style="display: block; font-size: 11px; font-weight: 700; text-transform: uppercase; color: #8e8e93; margin-bottom: 6px;">
								<?php esc_html_e( 'Public Grade Certificate Comments', 'evg' ); ?>
							</label>
							<textarea name="grader_comments" class="evg-text-area" rows="2" placeholder="<?php esc_attr_e( 'Summary of condition attributes for the collector...', 'evg' ); ?>"><?php echo $evg_assessment ? esc_textarea( $evg_assessment->grader_comments ) : ''; ?></textarea>
						</div>

						<!-- Final Grade Selector -->
						<div class="evg-final-banner">
							<div>
								<div class="evg-final-title"><?php esc_html_e( 'Assigned Grade Standard', 'evg' ); ?></div>
								<div class="evg-final-subtitle"><?php esc_html_e( 'Strict EVG Whole Number Scale (1-10). No 9.5 or half points.', 'evg' ); ?></div>
							</div>
							<div>
								<select id="evg_final_grade_select" name="final_grade" class="evg-grade-select" required>
									<option value="" disabled <?php selected( empty( $evg_card->final_grade ), true ); ?>>&mdash;</option>
									<?php 
									for ( $i = 10; $i >= 1; $i-- ) {
										$evg_selected = ( (int) $evg_card->final_grade === $i ) ? 'selected' : '';
										echo '<option value="' . esc_attr( $i ) . '" ' . esc_attr( $evg_selected ) . '>' . esc_html( $i ) . '</option>';
									}
									?>
								</select>
							</div>
						</div>

						<button type="submit" class="evg-btn-submit">
							&#128737; <?php esc_html_e( 'Seal Final Grade & Route to QC', 'evg' ); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
	</form>

	<script>
		jQuery(document).ready(function($){
			var faultTypesOptions = '<?php 
				$evg_opts = '';
				foreach ( $evg_fault_types_available as $k => $v ) {
					$evg_opts .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $v ) . '</option>';
				}
				echo $evg_opts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>';

			function updateFreePreviewCounter() {
				var checkedCount = $('.evg-preview-checkbox:checked').length;
				$('#evg-free-counter').text(checkedCount + '<?php echo esc_js( __( '/3 Free Previews', 'evg' ) ); ?>');
			}
			updateFreePreviewCounter();

			$(document).on('change', '.evg-preview-checkbox', function() {
				var checkedCount = $('.evg-preview-checkbox:checked').length;
				if (checkedCount > 3) {
					alert('<?php echo esc_js( __( 'You can only select up to 3 free preview images. The remaining images will automatically blur behind the £0.99 portfolio paywall.', 'evg' ) ); ?>');
					$(this).prop('checked', false);
				}
				if ($(this).is(':checked')) {
					$(this).closest('.evg-fault-cell').addClass('is-preview-active');
				} else {
					$(this).closest('.evg-fault-cell').removeClass('is-preview-active');
				}
				updateFreePreviewCounter();
			});

			function reindexFaultElements() {
				$('#evg-fault-preview-container .evg-fault-cell').each(function(idx) {
					$(this).find('input[type="hidden"]').attr('name', 'fault_images[' + idx + ']');
					$(this).find('select').attr('name', 'fault_types[' + idx + ']');
					$(this).find('.evg-preview-checkbox').val(idx);
				});
				updateFreePreviewCounter();
			}

			var custom_uploader;
			$('#evg-upload-fault').click(function(e) {
				e.preventDefault();
				if (custom_uploader) { 
					custom_uploader.open(); 
					return; 
				}
				custom_uploader = wp.media({
					title: '<?php echo esc_js( __( 'Upload Defect Imagery', 'evg' ) ); ?>',
					button: { text: '<?php echo esc_js( __( 'Attach Images', 'evg' ) ); ?>' },
					multiple: true
				});
				custom_uploader.on('select', function() {
					var selection = custom_uploader.state().get('selection');
					selection.each(function(attachment) {
						var attJSON = attachment.toJSON();
						var currentChecked = $('.evg-preview-checkbox:checked').length;
						var autoCheck = (currentChecked < 3) ? 'checked' : '';
						var activeClass = autoCheck ? 'is-preview-active' : '';

						var html = '<div class="evg-fault-cell ' + activeClass + '">' +
										'<img src="' + attJSON.url + '" alt="<?php echo esc_js( __( 'Defect', 'evg' ) ); ?>">' +
										'<div class="evg-fault-meta">' +
											'<input type="hidden" name="fault_images[]" value="' + attJSON.url + '">' +
											'<select name="fault_types[]">' + faultTypesOptions + '</select>' +
											'<label class="evg-preview-toggle-wrap">' +
												'<input type="checkbox" name="free_preview_indices[]" value="0" class="evg-preview-checkbox" ' + autoCheck + '>' +
												'<span><?php echo esc_js( __( 'Free Preview (Max 3)', 'evg' ) ); ?></span>' +
											'</label>' +
										'</div>' +
										'<button type="button" class="btn-del-img" title="<?php echo esc_js( __( 'Remove Photo', 'evg' ) ); ?>">&times;</button>' +
									'</div>';
						$('#evg-fault-preview-container').append(html);
					});
					reindexFaultElements();
				});
				custom_uploader.open();
			});

			$(document).on('click', '.btn-del-img', function(){
				$(this).closest('.evg-fault-cell').remove();
				reindexFaultElements();
			});

			// Calculate whole number floor suggestion.
			$('.sub-calc').on('input change', function() {
				var c  = parseInt($('#sub_centre').val(), 10) || 0;
				var cr = parseInt($('#sub_corner').val(), 10) || 0;
				var e  = parseInt($('#sub_edge').val(), 10) || 0;
				var s  = parseInt($('#sub_surface').val(), 10) || 0;

				if (c > 0 && cr > 0 && e > 0 && s > 0) {
					var lowest    = Math.min(c, cr, e, s);
					var avg       = Math.round((c + cr + e + s) / 4);
					var suggested = Math.min(avg, lowest + 1);
					if (!$('#evg_final_grade_select').val()) {
						$('#evg_final_grade_select').val(suggested);
					}
				}
			});
		});
	</script>
	<?php
}