<?php
/**
 * EVG Module: Customer Accounts (Pro Dashboard Design)
 * High-end collector profile UI, lifetime value analytics, address metadata, and dual-stream ledger.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render function for the Customers tab.
 */
function evg_customers_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to view Customer Accounts.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;
	$evg_table_submissions = $wpdb->prefix . 'evg_submissions';

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$evg_action      = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	$evg_customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'view' === $evg_action && $evg_customer_id > 0 ) {
		evg_render_pro_customer_profile( $evg_customer_id, $evg_table_submissions );
	} else {
		evg_render_pro_customers_list( $evg_table_submissions );
	}
}

/**
 * Pro ListView: Registered Collectors
 *
 * @param string $evg_table_submissions Submissions DB table name.
 */
function evg_render_pro_customers_list( $evg_table_submissions ) {
	global $wpdb;

	$evg_table_orders = $wpdb->prefix . 'evg_orders';

	// SQL strict-mode compliant customer valuation query combining Submissions and Marketplace orders.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_customers = $wpdb->get_results(
		"SELECT u.ID, u.user_email, u.display_name, u.user_registered,
				COALESCE(sub.total_submissions, 0) + COALESCE(ord.total_marketplace_orders, 0) AS total_orders,
				COALESCE(sub.submission_spend, 0) + COALESCE(ord.marketplace_spend, 0) AS total_spent
		FROM {$wpdb->users} u
		LEFT JOIN (
			SELECT customer_id, 
					COUNT(id) AS total_submissions, 
					SUM(total_amount) AS submission_spend 
			FROM {$evg_table_submissions} 
			GROUP BY customer_id
		) sub ON u.ID = sub.customer_id
		LEFT JOIN (
			SELECT customer_id, 
					COUNT(id) AS total_marketplace_orders, 
					SUM(amount_paid) AS marketplace_spend 
			FROM {$evg_table_orders} 
			WHERE payment_status = 'Paid'
			GROUP BY customer_id
		) ord ON u.ID = ord.customer_id
		ORDER BY u.user_registered DESC"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	?>
	<style>
		:root {
			--evg-gold: #d4af37;
			--evg-gold-light: #f3e5ab;
			--evg-gold-glow: rgba(212, 175, 55, 0.15);
			--evg-bg-card: #0f0f0f;
			--evg-bg-card-alt: #141416;
			--evg-border: #222224;
			--evg-text-muted: #8e8e93;
		}

		.evg-pro-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 28px;
			flex-wrap: wrap;
			gap: 15px;
		}
		.evg-pro-header h1 {
			font-size: 26px;
			font-weight: 800;
			letter-spacing: -0.5px;
			color: #ffffff;
			margin: 0 0 6px 0;
		}
		.evg-pro-header p {
			color: var(--evg-text-muted);
			font-size: 13px;
			margin: 0;
		}

		.evg-pro-panel {
			background: var(--evg-bg-card);
			border: 1px solid var(--evg-border);
			border-radius: 14px;
			overflow-x: auto;
			box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
		}

		.evg-pro-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}
		.evg-pro-table thead th {
			background: #121212;
			color: var(--evg-text-muted);
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 1px;
			padding: 16px 20px;
			border-bottom: 1px solid var(--evg-border);
			text-align: left;
			white-space: nowrap;
		}
		.evg-pro-table tbody td {
			padding: 18px 20px;
			border-bottom: 1px solid #1a1a1a;
			color: #ffffff;
			font-size: 13px;
			vertical-align: middle;
		}
		.evg-pro-table tbody tr {
			transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
		}
		.evg-pro-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.03);
		}
		.evg-pro-header h1 {
	color: #fff;
}
		.evg-pro-table tbody tr:last-child td {
			border-bottom: none;
		}

		.evg-collector-tag {
			display: inline-flex;
			align-items: center;
			gap: 12px;
		}
		.evg-mini-avatar {
			width: 38px;
			height: 38px;
			border-radius: 50%;
			border: 1.5px solid var(--evg-gold);
			object-fit: cover;
			background: #1c1c1e;
		}
		.evg-collector-name {
			font-weight: 700;
			color: #ffffff;
			display: block;
		}
		.evg-collector-email {
			font-size: 12px;
			color: var(--evg-text-muted);
		}

		.evg-pill-count {
			background: #18181a;
			border: 1px solid #2c2c2e;
			color: var(--evg-gold);
			font-weight: 700;
			padding: 4px 12px;
			border-radius: 20px;
			font-size: 12px;
			display: inline-block;
		}

		.evg-spent-badge {
			font-weight: 800;
			font-size: 14px;
			color: #34c759;
			letter-spacing: -0.3px;
		}

		.evg-btn-view {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 7px 16px;
			border-radius: 8px;
			background: transparent;
			border: 1px solid var(--evg-border);
			color: #ffffff;
			font-size: 12px;
			font-weight: 600;
			text-decoration: none;
			transition: all 0.2s ease;
			white-space: nowrap;
		}
		.evg-btn-view:hover {
			border-color: var(--evg-gold);
			color: var(--evg-gold);
			background: var(--evg-gold-glow);
			transform: translateY(-1px);
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

	<div class="evg-pro-header">
		<div>
			<h1><?php esc_html_e( 'Customers', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Manage registered collectors, evaluate lifetime valuation, and audit historic submissions.', 'evg' ); ?></p>
		</div>
		<div style="color: var(--evg-text-muted); font-family: monospace; font-size: 12px;">
			<?php
			printf(
				/* translators: %s: Formatted count of total customer accounts */
				esc_html__( '%s Accounts Total', 'evg' ),
				esc_html( number_format_i18n( count( $evg_customers ) ) )
			);
			?>
		</div>
	</div>

	<div class="evg-pro-panel">
		<table class="evg-pro-table evg-customers-datatable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Collector', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Joined Date', 'evg' ); ?></th>
					<th style="text-align: center;"><?php esc_html_e( 'Orders Total', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Lifetime Value', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Action', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_customers ) ) : ?>
					<?php foreach ( $evg_customers as $customer ) : 
						$evg_joined_time = strtotime( $customer->user_registered );
					?>
						<tr>
							<td>
								<div class="evg-collector-tag">
									<?php echo get_avatar( $customer->ID, 38, '', '', array( 'class' => 'evg-mini-avatar' ) ); ?>
									<div>
										<span class="evg-collector-name"><?php echo esc_html( $customer->display_name ? $customer->display_name : __( 'Collector', 'evg' ) ); ?></span>
										<span class="evg-collector-email"><?php echo esc_html( $customer->user_email ); ?></span>
									</div>
								</div>
							</td>
							<td data-order="<?php echo esc_attr( $evg_joined_time ); ?>" style="color: var(--evg-text-muted);">
								<?php echo esc_html( date_i18n( 'M j, Y', $evg_joined_time ) ); ?>
							</td>
							<td style="text-align: center;">
								<span class="evg-pill-count"><?php echo esc_html( number_format_i18n( absint( $customer->total_orders ) ) ); ?></span>
							</td>
							<td>
								<span class="evg-spent-badge">&pound;<?php echo esc_html( number_format_i18n( (float) $customer->total_spent, 2 ) ); ?></span>
							</td>
							<td style="text-align: right;">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_customers&action=view&customer_id=' . $customer->ID ) ); ?>" class="evg-btn-view">
									<?php esc_html_e( 'View Profile &rarr;', 'evg' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-customers-datatable tbody tr').length > 0 && $('.evg-customers-datatable tbody tr td').length > 1) {
				$('.evg-customers-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"order": [[ 1, "desc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Search Registry:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Display _MENU_ collectors per page', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ customer accounts', 'evg' ) ); ?>",
						"paginate": {
							"previous": "<?php echo esc_js( __( '&larr; Prev', 'evg' ) ); ?>",
							"next": "<?php echo esc_js( __( 'Next &rarr;', 'evg' ) ); ?>"
						}
					}
				});
			}
		});
	</script>
	<?php
}

/**
 * Pro View: Collector Detailed Profile & Ledger matching Create Account specifications
 *
 * @param int    $customer_id           Target user ID.
 * @param string $evg_table_submissions Submissions DB table name.
 */
function evg_render_pro_customer_profile( $customer_id, $evg_table_submissions ) {
	global $wpdb;

	$evg_user_info = get_userdata( $customer_id );
	if ( ! $evg_user_info ) {
		echo '<p style="color:#ff453a;">' . esc_html__( 'Collector profile not found.', 'evg' ) . '</p>';
		return;
	}

	$evg_table_orders = $wpdb->prefix . 'evg_orders';

	// Meta parameters stored via the Create Account form.
	$evg_first_name    = get_user_meta( $customer_id, 'first_name', true );
	$evg_last_name     = get_user_meta( $customer_id, 'last_name', true );
	$evg_house_number  = get_user_meta( $customer_id, 'evg_house_number', true );
	$evg_street        = get_user_meta( $customer_id, 'evg_street_address', true );
	$evg_city          = get_user_meta( $customer_id, 'evg_town_city', true );
	$evg_county        = get_user_meta( $customer_id, 'evg_county', true );
	$evg_postcode      = get_user_meta( $customer_id, 'evg_postcode', true );
	$evg_country       = get_user_meta( $customer_id, 'evg_country', true );
	$evg_mobile        = get_user_meta( $customer_id, 'evg_mobile_number', true );
	$evg_pref_updates  = get_user_meta( $customer_id, 'evg_pref_updates', true );
	$evg_opt_in_offers = get_user_meta( $customer_id, 'evg_opt_in_promotions', true );
	$evg_is_over_18    = get_user_meta( $customer_id, 'evg_age_consent', true );

	if ( empty( $evg_country ) ) {
		$evg_country = 'United Kingdom';
	}
	$evg_roles_list = implode( ', ', array_map( 'ucfirst', (array) $evg_user_info->roles ) );

	// 1. Fetch Grading Submissions.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_submissions = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$evg_table_submissions} 
			WHERE customer_id = %d 
			ORDER BY submission_date DESC",
			$customer_id
		)
	);

	// 2. Fetch Marketplace Orders.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_marketplace_orders = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT o.*, m.card_title, m.assigned_grade, m.set_name 
			FROM {$evg_table_orders} o
			LEFT JOIN {$wpdb->prefix}evg_marketplace m ON o.marketplace_item_id = m.id
			WHERE o.customer_id = %d 
			ORDER BY o.purchased_at DESC",
			$customer_id
		)
	);

	// Dual-Stream Financial Calculations.
	$evg_sub_spent     = 0.00;
	$evg_sub_paid_cnt  = 0;
	$evg_active_subs   = 0;
	$evg_total_cards   = 0;

	if ( ! empty( $evg_submissions ) ) {
		foreach ( $evg_submissions as $sub ) {
			if ( 'Paid' === $sub->payment_status ) {
				$evg_sub_spent += (float) $sub->total_amount;
				$evg_sub_paid_cnt++;
			}
			if ( ! in_array( $sub->current_stage, array( 'Completed', 'Returned To Customer' ), true ) ) {
				$evg_active_subs++;
			}
			$evg_total_cards += absint( $sub->total_cards );
		}
	}

	$evg_mkt_spent    = 0.00;
	$evg_mkt_paid_cnt = 0;
	if ( ! empty( $evg_marketplace_orders ) ) {
		foreach ( $evg_marketplace_orders as $mord ) {
			if ( 'Paid' === $mord->payment_status ) {
				$evg_mkt_spent += (float) $mord->amount_paid;
				$evg_mkt_paid_cnt++;
			}
		}
	}

	$evg_lifetime_value = $evg_sub_spent + $evg_mkt_spent;
	$evg_total_orders   = count( $evg_submissions ) + count( $evg_marketplace_orders );
	$evg_total_paid     = $evg_sub_paid_cnt + $evg_mkt_paid_cnt;
	$evg_avg_order_val  = $evg_total_paid > 0 ? ( $evg_lifetime_value / $evg_total_paid ) : 0.00;
	?>
	<style>
		:root {
			--evg-gold: #d4af37;
			--evg-gold-light: #f3e5ab;
			--evg-gold-faint: rgba(212, 175, 55, 0.08);
			--evg-gold-border: rgba(212, 175, 55, 0.25);
			--evg-bg-base: #0a0a0c;
			--evg-bg-panel: #0f0f12;
			--evg-bg-elevated: #141418;
			--evg-border-subtle: #1e1e24;
			--evg-text-primary: #ffffff;
			--evg-text-secondary: #8e8e93;
			--evg-success: #34c759;
		}

		.evg-profile-grid {
			display: grid;
			grid-template-columns: 410px 1fr;
			gap: 28px;
			align-items: start;
		}

		.evg-card {
			background: #0f0f11;
			border: 1px solid #222224;
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
			margin-bottom: 24px;
		}

		.evg-card-hero {
			background: linear-gradient(180deg, #18181a 0%, #0f0f11 100%);
			padding: 32px 24px 22px;
			text-align: center;
			border-bottom: 1px solid #1f1f22;
		}
		.evg-avatar-pro {
			width: 92px;
			height: 92px;
			border-radius: 50%;
			border: 2px solid #d4af37;
			padding: 3px;
			background: #0a0a0a;
			box-shadow: 0 8px 24px rgba(212, 175, 55, 0.2);
			object-fit: cover;
		}
		.evg-profile-hero-name {
			font-size: 20px;
			font-weight: 800;
			color: #ffffff;
			margin: 14px 0 3px 0;
		}
		.evg-profile-hero-email {
			font-size: 13px;
			color: #8e8e93;
			margin: 0;
			word-break: break-all;
		}

		.evg-stats-matrix {
			display: grid;
			grid-template-columns: repeat(3, 1fr);
			padding: 16px;
			background: #141416;
			border-radius: 10px;
			border: 1px solid #222224;
			margin: 20px;
			gap: 8px;
		}
		.evg-stat-cell {
			text-align: center;
		}
		.evg-stat-cell:not(:last-child) {
			border-right: 1px solid #252528;
		}
		.evg-stat-cell .number {
			font-size: 16px;
			font-weight: 800;
			color: #ffffff;
			display: block;
		}
		.evg-stat-cell .label {
			font-size: 10px;
			color: #8e8e93;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			font-weight: 600;
		}

		.evg-info-pane {
			padding: 0 24px 24px;
		}
		.evg-pane-title {
			font-size: 11px;
			text-transform: uppercase;
			letter-spacing: 1.2px;
			color: #d4af37;
			font-weight: 800;
			margin: 20px 0 12px;
			display: flex;
			align-items: center;
			gap: 8px;
		}
		.evg-pane-title::after {
			content: '';
			flex: 1;
			height: 1px;
			background: #222224;
		}
		.evg-info-row {
			display: flex;
			justify-content: space-between;
			align-items: center;
			padding: 10px 0;
			font-size: 12px;
			border-bottom: 1px solid #1a1a1c;
		}
		.evg-info-row:last-child {
			border-bottom: none;
		}
		.evg-info-row .key {
			color: #8e8e93;
			font-weight: 600;
		}
		.evg-info-row .val {
			color: #ffffff;
			font-weight: 600;
			text-align: right;
		}

		.evg-badge-consent {
			display: inline-flex;
			align-items: center;
			gap: 5px;
			font-size: 11px;
			font-weight: 700;
			padding: 3px 8px;
			border-radius: 4px;
		}
		.evg-consent-yes {
			background: rgba(52, 199, 89, 0.1);
			color: #34c759;
			border: 1px solid rgba(52, 199, 89, 0.3);
		}
		.evg-consent-no {
			background: rgba(142, 142, 147, 0.1);
			color: #8e8e93;
			border: 1px solid rgba(142, 142, 147, 0.3);
		}

		/* Executive Ledger Card & Table Styles */
		.evg-table-card {
			background: var(--evg-bg-panel);
			border: 1px solid var(--evg-border-subtle);
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.6);
		}

		.evg-table-header {
			padding: 20px 24px;
			background: var(--evg-bg-elevated);
			border-bottom: 1px solid var(--evg-border-subtle);
			display: flex;
			justify-content: space-between;
			align-items: center;
			flex-wrap: wrap;
			gap: 12px;
		}

		.evg-ledger-tabs {
			display: flex;
			gap: 8px;
		}
		.evg-tab-btn {
			background: #0f0f11;
			border: 1px solid #28282b;
			color: #8e8e93;
			padding: 8px 16px;
			border-radius: 6px;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.6px;
			cursor: pointer;
			transition: all 0.2s ease;
		}
		.evg-tab-btn.active, .evg-tab-btn:hover {
			border-color: #d4af37;
			color: #d4af37;
			background: rgba(212, 175, 55, 0.05);
		}

		.evg-badge-count {
			background: #18181e;
			border: 1px solid var(--evg-border-subtle);
			color: var(--evg-gold);
			font-size: 11px;
			font-weight: 700;
			padding: 4px 12px;
			border-radius: 20px;
			font-family: monospace;
		}

		.evg-table-responsive {
			overflow-x: auto;
		}

		.evg-ledger-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}

		.evg-ledger-table thead th {
			background: #111115;
			color: var(--evg-text-secondary);
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			padding: 16px 22px;
			border-bottom: 1px solid var(--evg-border-subtle);
			text-align: left;
			white-space: nowrap;
		}

		.evg-ledger-table tbody td {
			padding: 18px 22px;
			border-bottom: 1px solid #16161c;
			font-size: 13px;
			vertical-align: middle;
			color: #ffffff;
		}

		.evg-ledger-table tbody tr {
			transition: background 0.2s ease;
		}

		.evg-ledger-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.02);
		}

		.evg-ledger-table tbody tr:last-child td {
			border-bottom: none;
		}

		/* Data Elements */
		.evg-order-tag {
			font-family: "SFMono-Regular", Consolas, monospace;
			font-weight: 800;
			color: var(--evg-gold);
			letter-spacing: 0.5px;
		}

		.evg-date {
			display: block;
			font-weight: 600;
			color: var(--evg-text-primary);
		}

		.evg-time {
			display: block;
			font-size: 11px;
			color: var(--evg-text-secondary);
		}

		.evg-pill-units {
			display: inline-block;
			background: #15151a;
			border: 1px solid #282830;
			color: #ffffff;
			padding: 3px 10px;
			border-radius: 12px;
			font-weight: 700;
			font-size: 12px;
		}

		.evg-stage-badge {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			padding: 4px 10px;
			border-radius: 6px;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.4px;
		}

		.stage-active {
			background: var(--evg-gold-faint);
			border: 1px solid var(--evg-gold-border);
			color: var(--evg-gold);
		}

		.stage-complete {
			background: rgba(52, 199, 89, 0.08);
			border: 1px solid rgba(52, 199, 89, 0.25);
			color: var(--evg-success);
		}

		.evg-status-dot {
			width: 6px;
			height: 6px;
			border-radius: 50%;
			background: var(--evg-gold);
			box-shadow: 0 0 8px var(--evg-gold);
		}

		.evg-amount {
			font-weight: 800;
			font-size: 14px;
			color: #ffffff;
			letter-spacing: -0.2px;
		}

		.evg-btn-action {
			display: inline-flex;
			align-items: center;
			padding: 6px 14px;
			background: transparent;
			border: 1px solid #282830;
			border-radius: 6px;
			color: var(--evg-text-primary);
			font-size: 12px;
			font-weight: 600;
			text-decoration: none;
			transition: all 0.2s ease;
			white-space: nowrap;
		}

		.evg-btn-action:hover {
			border-color: var(--evg-gold);
			color: var(--evg-gold);
			background: var(--evg-gold-faint);
			transform: translateX(2px);
		}

		.text-center { text-align: center; }
		.text-end { text-align: right; }

		@media (max-width: 1080px) {
			.evg-profile-grid {
				grid-template-columns: 1fr;
			}
		}
	</style>

	<div class="evg-pro-header">
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_customers' ) ); ?>" class="evg-btn-view" style="margin-bottom: 12px; display: inline-flex;">
				&larr; <?php esc_html_e( 'Back to Customers', 'evg' ); ?>
			</a>
			<h1><?php echo esc_html( $evg_user_info->display_name ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: 1: Collector User ID, 2: Registration date */
					esc_html__( 'Collector ID #%1$d &bull; Registered on %2$s', 'evg' ),
					absint( $evg_user_info->ID ),
					esc_html( date_i18n( 'F j, Y, H:i', strtotime( $evg_user_info->user_registered ) ) ) . ' UTC'
				);
				?>
			</p>
		</div>
	</div>

	<div class="evg-profile-grid">
		<!-- Left Sidebar: Customer Identity, UK Logistics & Registration Consents -->
		<div>
			<div class="evg-card">
				<div class="evg-card-hero">
					<?php echo get_avatar( $evg_user_info->ID, 92, '', '', array( 'class' => 'evg-avatar-pro' ) ); ?>
					<h3 class="evg-profile-hero-name"><?php echo esc_html( $evg_user_info->display_name ); ?></h3>
					<p class="evg-profile-hero-email"><?php echo esc_html( $evg_user_info->user_email ); ?></p>
					<span style="font-family: monospace; font-size: 11px; color: var(--evg-gold); margin-top: 4px; display: inline-block;">
						@<?php echo esc_html( $evg_user_info->user_login ); ?>
					</span>
				</div>

				<div class="evg-stats-matrix">
					<div class="evg-stat-cell">
						<span class="number"><?php echo esc_html( number_format_i18n( $evg_total_orders ) ); ?></span>
						<span class="label"><?php esc_html_e( 'Orders', 'evg' ); ?></span>
					</div>
					<div class="evg-stat-cell">
						<span class="number" style="color: #ff9f0a;"><?php echo esc_html( number_format_i18n( $evg_active_subs ) ); ?></span>
						<span class="label"><?php esc_html_e( 'In Queue', 'evg' ); ?></span>
					</div>
					<div class="evg-stat-cell">
						<span class="number" style="color: #34c759;">&pound;<?php echo esc_html( number_format_i18n( (float) $evg_lifetime_value, 0 ) ); ?></span>
						<span class="label"><?php esc_html_e( 'LTV', 'evg' ); ?></span>
					</div>
				</div>

				<div class="evg-info-pane">
					<!-- SECTION 01: IDENTITY VERIFICATION -->
					<div class="evg-pane-title"><?php esc_html_e( '01 // Identity Profile', 'evg' ); ?></div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'First Name', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_first_name ) ? esc_html( $evg_first_name ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Last Name', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_last_name ) ? esc_html( $evg_last_name ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'User Login ID', 'evg' ); ?></span>
						<span class="val" style="font-family: monospace;">#<?php echo esc_html( $evg_user_info->ID ); ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Assigned Role', 'evg' ); ?></span>
						<span class="val" style="color: var(--evg-gold);"><?php echo esc_html( $evg_roles_list ); ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Contact Phone', 'evg' ); ?></span>
						<span class="val" style="color: var(--evg-gold); font-family: monospace;"><?php echo ! empty( $evg_mobile ) ? esc_html( $evg_mobile ) : '&mdash;'; ?></span>
					</div>

					<!-- SECTION 02: UK LOGISTICS SPECIFICATIONS -->
					<div class="evg-pane-title"><?php esc_html_e( '02 // UK Logistics & Delivery', 'evg' ); ?></div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'House Number / Name', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_house_number ) ? esc_html( $evg_house_number ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Street Address', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_street ) ? esc_html( $evg_street ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Town / City', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_city ) ? esc_html( $evg_city ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'County', 'evg' ); ?></span>
						<span class="val"><?php echo ! empty( $evg_county ) ? esc_html( $evg_county ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Postcode', 'evg' ); ?></span>
						<span class="val" style="color: var(--evg-gold); font-family: monospace; font-size: 13px; font-weight: 800;"><?php echo ! empty( $evg_postcode ) ? esc_html( $evg_postcode ) : '&mdash;'; ?></span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Country Locked', 'evg' ); ?></span>
						<span class="val" style="color: #34c759;">&#10003; <?php echo esc_html( $evg_country ); ?></span>
					</div>

					<!-- SECTION 03: REGISTRATION CONSENTS & PREFERENCES -->
					<div class="evg-pane-title"><?php esc_html_e( '03 // Consents & Compliance', 'evg' ); ?></div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Age Verification (&gt;18)', 'evg' ); ?></span>
						<span class="val">
							<?php if ( 'yes' === $evg_is_over_18 || '1' === $evg_is_over_18 ) : ?>
								<span class="evg-badge-consent evg-consent-yes">&#10003; <?php esc_html_e( 'Confirmed 18+', 'evg' ); ?></span>
							<?php else : ?>
								<span class="evg-badge-consent evg-consent-no">&times; <?php esc_html_e( 'Pending', 'evg' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Grading Email Updates', 'evg' ); ?></span>
						<span class="val">
							<?php if ( 'yes' === $evg_pref_updates || empty( $evg_pref_updates ) ) : ?>
								<span class="evg-badge-consent evg-consent-yes">&#10003; <?php esc_html_e( 'Subscribed', 'evg' ); ?></span>
							<?php else : ?>
								<span class="evg-badge-consent evg-consent-no">&times; <?php esc_html_e( 'Opted Out', 'evg' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Marketing Offers', 'evg' ); ?></span>
						<span class="val">
							<?php if ( 'yes' === $evg_opt_in_offers || '1' === $evg_opt_in_offers ) : ?>
								<span class="evg-badge-consent evg-consent-yes">&#10003; <?php esc_html_e( 'Consented', 'evg' ); ?></span>
							<?php else : ?>
								<span class="evg-badge-consent evg-consent-no">&times; <?php esc_html_e( 'Declined', 'evg' ); ?></span>
							<?php endif; ?>
						</span>
					</div>
					<div class="evg-info-row">
						<span class="key"><?php esc_html_e( 'Average Order Value', 'evg' ); ?></span>
						<span class="val" style="color: #34c759;">&pound;<?php echo esc_html( number_format_i18n( (float) $evg_avg_order_val, 2 ) ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- Right Column: Dual-Stream Ledger Views (Submissions vs Marketplace) -->
		<div>
			<div class="evg-table-card">
				<div class="evg-table-header">
					<div class="evg-ledger-tabs">
						<button type="button" class="evg-tab-btn active" onclick="evgSwitchLedger('subs', this);">
							<?php esc_html_e( 'Grading Submissions', 'evg' ); ?>
						</button>
						<button type="button" class="evg-tab-btn" onclick="evgSwitchLedger('orders', this);">
							<?php esc_html_e( 'Marketplace Slabs', 'evg' ); ?>
						</button>
					</div>
					<span class="evg-badge-count" id="evg-ledger-count">
						<?php printf( esc_html__( '%d Entries Logged', 'evg' ), count( $evg_submissions ) ); ?>
					</span>
				</div>

				<!-- Ledger Tab 1: Grading Submissions Table -->
				<div id="evg-ledger-subs" class="evg-table-responsive">
					<table class="evg-ledger-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Date', 'evg' ); ?></th>
								<th class="text-center"><?php esc_html_e( 'Cards', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Pipeline Stage', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Billed', 'evg' ); ?></th>
								<th class="text-end"><?php esc_html_e( 'Actions', 'evg' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $evg_submissions ) ) : ?>
								<?php foreach ( $evg_submissions as $sub ) : 
									$is_done   = in_array( $sub->current_stage, array( 'Completed', 'Returned To Customer' ), true );
									$timestamp = strtotime( $sub->submission_date );
								?>
									<tr>
										<td>
											<span class="evg-order-tag">#<?php echo esc_html( $sub->order_number ); ?></span>
										</td>
										<td>
											<span class="evg-date"><?php echo esc_html( date_i18n( 'M j, Y', $timestamp ) ); ?></span>
											<span class="evg-time"><?php echo esc_html( date_i18n( 'H:i', $timestamp ) ); ?> UTC</span>
										</td>
										<td class="text-center">
											<span class="evg-pill-units"><?php echo esc_html( number_format_i18n( absint( $sub->total_cards ) ) ); ?></span>
										</td>
										<td>
											<?php if ( $is_done ) : ?>
												<span class="evg-stage-badge stage-complete">
													&#10003; <?php echo esc_html( $sub->current_stage ); ?>
												</span>
											<?php else : ?>
												<span class="evg-stage-badge stage-active">
													<span class="evg-status-dot"></span> <?php echo esc_html( $sub->current_stage ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="evg-amount">&pound;<?php echo esc_html( number_format_i18n( (float) $sub->total_amount, 2 ) ); ?></span>
										</td>
										<td class="text-end">
											<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub->id ) ); ?>" class="evg-btn-action">
												<?php esc_html_e( 'Inspect &rarr;', 'evg' ); ?>
											</a>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="6" style="text-align: center; color: var(--evg-text-secondary); padding: 40px;">
										<?php esc_html_e( 'No grading submissions logged for this collector.', 'evg' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Ledger Tab 2: Marketplace Orders Table -->
				<div id="evg-ledger-orders" class="evg-table-responsive" style="display: none;">
					<table class="evg-ledger-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Purchased Slab', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Date', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Shipping', 'evg' ); ?></th>
								<th><?php esc_html_e( 'Amount', 'evg' ); ?></th>
								<th class="text-end"><?php esc_html_e( 'Tracking', 'evg' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php if ( ! empty( $evg_marketplace_orders ) ) : ?>
								<?php foreach ( $evg_marketplace_orders as $mord ) : 
									$mkt_timestamp = strtotime( $mord->purchased_at );
								?>
									<tr>
										<td>
											<span class="evg-order-tag">#<?php echo esc_html( $mord->order_number ); ?></span>
										</td>
										<td>
											<strong style="color: #ffffff;"><?php echo esc_html( $mord->card_title ? $mord->card_title : __( 'Graded Slab', 'evg' ) ); ?></strong>
											<?php if ( ! empty( $mord->assigned_grade ) ) : ?>
												<span style="background: var(--evg-gold); color: #000; font-weight: 800; font-size: 10px; padding: 2px 6px; border-radius: 4px; margin-left: 4px;">
													EVG <?php echo esc_html( $mord->assigned_grade ); ?>
												</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="evg-date"><?php echo esc_html( date_i18n( 'M j, Y', $mkt_timestamp ) ); ?></span>
											<span class="evg-time"><?php echo esc_html( date_i18n( 'H:i', $mkt_timestamp ) ); ?> UTC</span>
										</td>
										<td>
											<span class="evg-stage-badge <?php echo 'Delivered' === $mord->shipping_status ? 'stage-complete' : 'stage-active'; ?>">
												&bull; <?php echo esc_html( $mord->shipping_status ); ?>
											</span>
										</td>
										<td>
											<span class="evg-amount">&pound;<?php echo esc_html( number_format_i18n( (float) $mord->amount_paid, 2 ) ); ?></span>
										</td>
										<td class="text-end" style="font-family: monospace; color: var(--evg-gold-light);">
											<?php echo ! empty( $mord->tracking_number ) ? esc_html( $mord->tracking_number ) : '&mdash;'; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php else : ?>
								<tr>
									<td colspan="6" style="text-align: center; color: var(--evg-text-secondary); padding: 40px;">
										<?php esc_html_e( 'No marketplace purchases found for this account.', 'evg' ); ?>
									</td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<script>
		function evgSwitchLedger(tab, btn) {
			document.querySelectorAll('.evg-tab-btn').forEach(function(b) {
				b.classList.remove('active');
			});
			btn.classList.add('active');

			var subsTable  = document.getElementById('evg-ledger-subs');
			var mktTable   = document.getElementById('evg-ledger-orders');
			var counterEl  = document.getElementById('evg-ledger-count');

			if ('subs' === tab) {
				subsTable.style.display = 'block';
				mktTable.style.display  = 'none';
				counterEl.textContent   = '<?php printf( esc_js( __( '%d Submissions Logged', 'evg' ) ), count( $evg_submissions ) ); ?>';
			} else {
				subsTable.style.display = 'none';
				mktTable.style.display  = 'block';
				counterEl.textContent   = '<?php printf( esc_js( __( '%d Purchases Logged', 'evg' ) ), count( $evg_marketplace_orders ) ); ?>';
			}
		}
	</script>
	<?php
}