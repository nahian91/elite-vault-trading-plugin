<?php
/**
 * EVG Module: Public Marketplace (Pro Edition)
 * Manages inventory, retail pricing, stock quantity, slab info, and card listings for the public directory.
 * Standard pagination configured to 5 items per page.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST/GET marketplace actions (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_marketplace_actions' );

function evg_handle_marketplace_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_marketplace' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
		return;
	}

	global $wpdb;
	$evg_table_marketplace = $wpdb->prefix . 'evg_marketplace';

	$evg_categories = array(
		'Elite Vault Graded Cards',
		'Ungraded Cards',
		'Featured Cards',
		'New Arrivals',
		'High Value Cards',
		'Collections/Bundles',
	);

	// ---------------------------------------------------------
	// 1. Handle Form Submissions (Create / Edit Listing)
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_save_listing_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_save_listing_nonce'] ) ), 'evg_save_listing' ) ) {

			$evg_listing_id       = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
			$evg_card_id          = ( ! empty( $_POST['card_id'] ) && absint( $_POST['card_id'] ) > 0 ) ? absint( $_POST['card_id'] ) : null;
			$evg_card_title       = isset( $_POST['card_title'] ) ? sanitize_text_field( wp_unslash( $_POST['card_title'] ) ) : '';
			$evg_set_name         = isset( $_POST['set_name'] ) ? sanitize_text_field( wp_unslash( $_POST['set_name'] ) ) : '';
			$evg_card_number      = isset( $_POST['card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['card_number'] ) ) : '';
			$evg_language         = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'English';
			$evg_grading_company  = isset( $_POST['grading_company'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_company'] ) ) : 'Elite Vault Grading';
			$evg_slab_information = isset( $_POST['slab_information'] ) ? sanitize_text_field( wp_unslash( $_POST['slab_information'] ) ) : '';
			$evg_assigned_grade   = ( isset( $_POST['assigned_grade'] ) && '' !== trim( sanitize_text_field( wp_unslash( $_POST['assigned_grade'] ) ) ) ) ? absint( $_POST['assigned_grade'] ) : null;
			$evg_image_url        = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';
			$evg_raw_price        = isset( $_POST['price'] ) ? (float) wp_unslash( $_POST['price'] ) : 0.00;
			$evg_price            = number_format( max( 0.00, $evg_raw_price ), 2, '.', '' );
			$evg_stock_quantity   = isset( $_POST['stock_quantity'] ) ? max( 0, absint( $_POST['stock_quantity'] ) ) : 0;
			$evg_raw_category     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : 'Elite Vault Graded Cards';
			$evg_raw_status       = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : 'Available';

			$evg_allowed_statuses = array( 'Available', 'Sold', 'Hidden' );
			$evg_status           = in_array( $evg_raw_status, $evg_allowed_statuses, true ) ? $evg_raw_status : 'Available';
			$evg_category         = in_array( $evg_raw_category, $evg_categories, true ) ? $evg_raw_category : 'Elite Vault Graded Cards';

			if ( 0 === $evg_stock_quantity && 'Available' === $evg_status ) {
				$evg_status = 'Sold';
			}

			if ( 0 === $evg_listing_id && ! empty( $evg_card_id ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$evg_existing_listing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$evg_table_marketplace} WHERE card_id = %d LIMIT 1", $evg_card_id ) );
				if ( $evg_existing_listing ) {
					$evg_listing_id = absint( $evg_existing_listing );
				}
			}

			$evg_data = array(
				'card_id'          => $evg_card_id,
				'card_title'       => $evg_card_title,
				'set_name'         => $evg_set_name,
				'card_number'      => $evg_card_number,
				'language'         => $evg_language,
				'grading_company'  => $evg_grading_company,
				'slab_information' => $evg_slab_information,
				'assigned_grade'   => $evg_assigned_grade,
				'image_url'        => $evg_image_url,
				'price'            => $evg_price,
				'stock_quantity'   => $evg_stock_quantity,
				'category'         => $evg_category,
				'status'           => $evg_status,
			);

			$evg_data_formats = array(
				( null === $evg_card_id ? null : '%d' ),
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				'%s',
				( null === $evg_assigned_grade ? null : '%d' ),
				'%s',
				'%s',
				'%d',
				'%s',
				'%s',
			);

			if ( $evg_listing_id > 0 ) {
				$wpdb->update(
					$evg_table_marketplace,
					$evg_data,
					array( 'id' => $evg_listing_id ),
					$evg_data_formats,
					array( '%d' )
				);

				if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
					Elite_Vault_Grading_System::log_activity( "Updated Marketplace Listing #{$evg_listing_id} ({$evg_card_title})." );
				}

				evg_marketplace_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace&updated=1' ) );
			} else {
				$evg_data['listed_date'] = current_time( 'mysql' );
				$evg_data_formats[]      = '%s';
				$wpdb->insert( $evg_table_marketplace, $evg_data, $evg_data_formats );
				$evg_new_id              = $wpdb->insert_id;

				if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
					Elite_Vault_Grading_System::log_activity( "Created Marketplace Listing #{$evg_new_id} ({$evg_card_title})." );
				}

				evg_marketplace_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace&created=1' ) );
			}
		}
	}

	// ---------------------------------------------------------
	// 2. Handle Quick Status Toggles (Sold / Available)
	// ---------------------------------------------------------
	if ( isset( $_GET['toggle_status'], $_GET['listing_id'], $_GET['_wpnonce'] ) ) {
		$evg_listing_id = absint( $_GET['listing_id'] );
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'evg_toggle_status_' . $evg_listing_id ) ) {
			$evg_raw_toggle      = sanitize_text_field( wp_unslash( $_GET['toggle_status'] ) );
			$evg_allowed_toggles = array( 'Available', 'Sold', 'Hidden' );
			$evg_new_status      = in_array( $evg_raw_toggle, $evg_allowed_toggles, true ) ? $evg_raw_toggle : 'Available';

			$wpdb->update(
				$evg_table_marketplace,
				array( 'status' => $evg_new_status ),
				array( 'id' => $evg_listing_id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
				Elite_Vault_Grading_System::log_activity( "Toggled Marketplace Listing #{$evg_listing_id} status to {$evg_new_status}." );
			}

			evg_marketplace_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace&status_updated=1' ) );
		}
	}

	// ---------------------------------------------------------
	// 3. Handle Deletion (Purge Listing)
	// ---------------------------------------------------------
	if ( isset( $_GET['delete_listing'], $_GET['_wpnonce'] ) ) {
		$evg_del_id = absint( $_GET['delete_listing'] );
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'evg_delete_listing_' . $evg_del_id ) ) {
			$wpdb->delete( $evg_table_marketplace, array( 'id' => $evg_del_id ), array( '%d' ) );

			if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
				Elite_Vault_Grading_System::log_activity( "Removed Marketplace Listing ID {$evg_del_id}." );
			}

			evg_marketplace_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace&deleted=1' ) );
		}
	}
}

/**
 * Universal redirect helper.
 *
 * @param string $location Destination URL.
 */
function evg_marketplace_safe_redirect( $location ) {
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
 * Render function for the Marketplace tab.
 */
function evg_marketplace_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to manage the Marketplace.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;

	$evg_table_marketplace = $wpdb->prefix . 'evg_marketplace';
	$evg_table_cards       = $wpdb->prefix . 'evg_cards';

	$evg_categories = array(
		'Elite Vault Graded Cards',
		'Ungraded Cards',
		'Featured Cards',
		'New Arrivals',
		'High Value Cards',
		'Collections/Bundles',
	);

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['created'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Card published successfully.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Listing updated successfully.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['status_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Status updated.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Listing removed.', 'evg' ) . '</p></div>';
	}

	$evg_action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : 'list';
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	if ( 'add' === $evg_action || 'edit' === $evg_action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$evg_listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		evg_render_pro_marketplace_form( $evg_listing_id, $evg_table_marketplace, $evg_table_cards, $evg_categories );
	} else {
		evg_render_pro_marketplace_list( $evg_table_marketplace );
	}
}

/**
 * Render Public Marketplace Directory Table
 *
 * @param string $evg_table_marketplace Marketplace DB table name.
 */
function evg_render_pro_marketplace_list( $evg_table_marketplace ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_listings = $wpdb->get_results( "SELECT * FROM {$evg_table_marketplace} ORDER BY id DESC" );
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

		.evg-mkt-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 28px;
			flex-wrap: wrap;
			gap: 15px;
		}
		.evg-mkt-header h1 {
			font-size: 26px;
			font-weight: 800;
			margin: 0 0 6px 0;
			color: #ffffff;
			letter-spacing: -0.5px;
		}
		.evg-mkt-header p {
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

		.evg-mkt-table {
			width: 100%;
			border-collapse: separate;
			border-spacing: 0;
		}
		.evg-mkt-table thead th {
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
		.evg-mkt-table tbody td {
			padding: 16px 20px;
			border-bottom: 1px solid #1a1a1d;
			color: #ffffff;
			font-size: 13px;
			vertical-align: middle;
		}
		.evg-mkt-table tbody tr {
			transition: background 0.2s ease;
		}
		.evg-mkt-table tbody tr:hover {
			background: rgba(212, 175, 55, 0.02);
		}
		.evg-mkt-table tbody tr:last-child td {
			border-bottom: none;
		}

		.evg-card-thumb-cell {
			width: 48px;
			height: 64px;
			border-radius: 6px;
			border: 1px solid #2a2a2e;
			overflow: hidden;
			background: #000000;
			display: flex;
			align-items: center;
			justify-content: center;
			flex-shrink: 0;
		}
		.evg-card-thumb-cell img {
			width: 100%;
			height: 100%;
			object-fit: cover;
		}

		.evg-cat-tag {
			display: inline-block;
			background: #1c1c1f;
			border: 1px solid #2c2c30;
			color: #ffffff;
			font-size: 11px;
			font-weight: 600;
			padding: 3px 8px;
			border-radius: 4px;
		}

		.evg-grade-chip {
			background: #d4af37;
			color: #0a0a0a;
			font-weight: 900;
			font-size: 10px;
			padding: 2px 6px;
			border-radius: 4px;
			margin-left: 6px;
			display: inline-block;
			vertical-align: middle;
		}

		.evg-status-dot {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-size: 12px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.evg-status-Available { color: #34c759; }
		.evg-status-Sold { color: #ff453a; }
		.evg-status-Hidden { color: #8e8e93; }

		.evg-btn-add {
			background: var(--evg-gold);
			color: #0a0a0a !important;
			padding: 10px 20px;
			font-size: 12px;
			font-weight: 800;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			border-radius: 8px;
			text-decoration: none;
			display: inline-flex;
			align-items: center;
			gap: 6px;
			transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
		}
		.evg-btn-add:hover {
			background: #f3e5ab;
			box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
			color: #000000 !important;
		}

		.evg-btn-action-edit {
			border: 1px solid var(--evg-border);
			color: #ffffff;
			padding: 6px 12px;
			font-size: 12px;
			font-weight: 600;
			border-radius: 6px;
			text-decoration: none;
			margin-right: 6px;
			transition: all 0.2s ease;
		}
		.evg-btn-action-edit:hover {
			border-color: var(--evg-gold);
			color: var(--evg-gold);
			background: rgba(212, 175, 55, 0.08);
		}

		.evg-btn-action-del {
			border: 1px solid rgba(255, 69, 58, 0.3);
			color: #ff453a;
			padding: 6px 10px;
			font-size: 12px;
			font-weight: 600;
			border-radius: 6px;
			text-decoration: none;
			transition: all 0.2s ease;
		}
		.evg-btn-action-del:hover {
			background: #ff453a;
			color: #ffffff;
		}

		.evg-btn-quick-toggle {
			border: 1px solid #28282b;
			color: #8e8e93;
			padding: 5px 8px;
			font-size: 11px;
			border-radius: 6px;
			text-decoration: none;
			margin-right: 4px;
		}
		.evg-btn-quick-toggle:hover {
			color: #ffffff;
			border-color: var(--evg-gold);
		}

		/* DataTables Pagination Styling */
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

	<div class="evg-mkt-header">
		<div>
			<h1><?php esc_html_e( 'Marketplace', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Manage certified inventory, valuations, stock levels, and store listings.', 'evg' ); ?></p>
		</div>
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace&action=add' ) ); ?>" class="evg-btn-add">
				+ <?php esc_html_e( 'Add Listing', 'evg' ); ?>
			</a>
		</div>
	</div>

	<div class="evg-panel-shell">
		<table class="evg-mkt-table evg-marketplace-datatable">
			<thead>
				<tr>
					<th style="width: 50px;"><?php esc_html_e( 'Visual', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Card & Slab', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Category', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Price (£)', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Status', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Controls', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_listings ) ) : ?>
					<?php foreach ( $evg_listings as $item ) : 
						$evg_status_class  = 'evg-status-' . sanitize_html_class( $item->status );
						$evg_display_title = $item->card_title ? $item->card_title : __( 'Graded Card', 'evg' );
					?>
						<tr>
							<td>
								<div class="evg-card-thumb-cell">
									<?php if ( ! empty( $item->image_url ) ) : ?>
										<img src="<?php echo esc_url( $item->image_url ); ?>" alt="<?php echo esc_attr( $evg_display_title ); ?>">
									<?php else : ?>
										<span style="font-size: 10px; color: var(--evg-text-muted);"><?php esc_html_e( 'RAW', 'evg' ); ?></span>
									<?php endif; ?>
								</div>
							</td>
							<td>
								<strong style="color: #ffffff; font-size: 14px;"><?php echo esc_html( $evg_display_title ); ?></strong>
								<?php if ( ! empty( $item->assigned_grade ) ) : ?>
									<span class="evg-grade-chip"><?php echo esc_html( ( $item->grading_company ? $item->grading_company : 'EVG' ) . ' ' . $item->assigned_grade ); ?></span>
								<?php endif; ?>
								<br>
								<span style="color: var(--evg-text-muted); font-size: 12px;">
									<?php echo esc_html( $item->set_name ); ?> #<?php echo esc_html( $item->card_number ? $item->card_number : 'N/A' ); ?> (<?php echo esc_html( $item->language ); ?>)
									<?php if ( ! empty( $item->slab_information ) ) : ?>
										&bull; <em><?php echo esc_html( $item->slab_information ); ?></em>
									<?php endif; ?>
								</span>
							</td>
							<td>
								<span class="evg-cat-tag"><?php echo esc_html( $item->category ); ?></span>
							</td>
							<td>
								<strong style="color: <?php echo (int) $item->stock_quantity > 0 ? '#34c759' : '#ff453a'; ?>;">
									<?php echo esc_html( number_format_i18n( absint( $item->stock_quantity ) ) ); ?>
								</strong>
							</td>
							<td>
								<span style="font-weight: 800; font-size: 15px; color: var(--evg-gold);">
									&pound;<?php echo esc_html( number_format_i18n( (float) $item->price, 2 ) ); ?>
								</span>
							</td>
							<td>
								<span class="evg-status-dot <?php echo esc_attr( $evg_status_class ); ?>">
									&bull; <?php echo esc_html( $item->status ); ?>
								</span>
							</td>
							<td style="text-align: right; white-space: nowrap;">
								<?php if ( 'Available' === $item->status ) : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&toggle_status=Sold&listing_id=' . $item->id ), 'evg_toggle_status_' . $item->id ) ); ?>" class="evg-btn-quick-toggle" title="<?php esc_attr_e( 'Mark as Sold', 'evg' ); ?>">
										<?php esc_html_e( 'Sold', 'evg' ); ?>
									</a>
								<?php else : ?>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&toggle_status=Available&listing_id=' . $item->id ), 'evg_toggle_status_' . $item->id ) ); ?>" class="evg-btn-quick-toggle" title="<?php esc_attr_e( 'Mark Available', 'evg' ); ?>">
										<?php esc_html_e( 'Available', 'evg' ); ?>
									</a>
								<?php endif; ?>

								<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace&action=edit&listing_id=' . $item->id ) ); ?>" class="evg-btn-action-edit">
									<?php esc_html_e( 'Edit', 'evg' ); ?>
								</a>
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&delete_listing=' . $item->id ), 'evg_delete_listing_' . $item->id ) ); ?>" class="evg-btn-action-del" onclick="return confirm('<?php echo esc_js( __( 'Permanently remove this card from the marketplace?', 'evg' ) ); ?>');">
									&times;
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="7" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
							<?php esc_html_e( 'No marketplace listings found.', 'evg' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-marketplace-datatable tbody tr').length > 0 && $('.evg-marketplace-datatable tbody tr td').length > 1) {
				$('.evg-marketplace-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"order": [[ 4, "desc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Filter Catalog:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Show _MENU_ listings', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ listings', 'evg' ) ); ?>",
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
 * Render Publish / Edit Marketplace Listing Form
 *
 * @param int    $listing_id            Listing ID to edit.
 * @param string $evg_table_marketplace Marketplace DB table name.
 * @param string $evg_table_cards       Cards DB table name.
 * @param array  $evg_categories        List of available categories.
 */
function evg_render_pro_marketplace_form( $listing_id, $evg_table_marketplace, $evg_table_cards, $evg_categories ) {
	global $wpdb;

	$evg_listing = null;
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_available_graded_cards = $wpdb->get_results(
		"SELECT c.*, s.order_number 
		FROM {$evg_table_cards} c
		JOIN {$wpdb->prefix}evg_submissions s ON c.submission_id = s.id
		WHERE c.grading_status IN ('Encapsulation', 'Completed')
		ORDER BY c.id DESC LIMIT 150"
	);

	if ( $listing_id > 0 ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$evg_listing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$evg_table_marketplace} WHERE id = %d", $listing_id ) );
	}
	?>
	<style>
		.evg-form-container {
			max-width: 720px;
			background: #0f0f11;
			border: 1px solid #222224;
			border-radius: 14px;
			overflow: hidden;
			box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
			margin-top: 10px;
		}
		.evg-form-header {
			padding: 18px 24px;
			background: #141416;
			border-bottom: 1px solid #1f1f22;
		}
		.evg-form-header h2 {
			margin: 0;
			font-size: 14px;
			font-weight: 700;
			color: #ffffff;
			text-transform: uppercase;
			letter-spacing: 0.8px;
		}
		.evg-form-body {
			padding: 24px;
		}

		.evg-input-grid-2 {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 16px;
		}
		.evg-input-group {
			margin-bottom: 20px;
		}
		.evg-input-group label {
			display: block;
			color: #d4af37;
			font-size: 11px;
			font-weight: 700;
			text-transform: uppercase;
			letter-spacing: 0.8px;
			margin-bottom: 8px;
		}
		.evg-control-field {
			width: 100%;
			background-color: #141416 !important;
			border: 1px solid #28282b !important;
			color: #ffffff !important;
			padding: 12px 14px;
			font-size: 13px;
			border-radius: 8px;
			outline: none;
			box-sizing: border-box;
		}
		.evg-control-field:focus {
			border-color: #d4af37 !important;
		}

		.evg-btn-publish {
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
		.evg-btn-publish:hover {
			background: #f3e5ab;
			box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
		}
	</style>

	<div class="evg-mkt-header">
		<div>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace' ) ); ?>" style="color: #d4af37; text-decoration: none; font-size: 13px; font-weight: 700; margin-bottom: 8px; display: inline-block;">
				&larr; <?php esc_html_e( 'Back', 'evg' ); ?>
			</a>
			<h1><?php echo $evg_listing ? esc_html__( 'Edit Listing', 'evg' ) : esc_html__( 'Add Listing', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Configure inventory parameters, pricing, and stock status.', 'evg' ); ?></p>
		</div>
	</div>

	<div class="evg-form-container">
		<div class="evg-form-header">
			<h2><?php esc_html_e( 'Parameters', 'evg' ); ?></h2>
		</div>
		<div class="evg-form-body">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace' ) ); ?>">
				<?php wp_nonce_field( 'evg_save_listing', 'evg_save_listing_nonce' ); ?>
				
				<?php if ( $evg_listing ) : ?>
					<input type="hidden" name="listing_id" value="<?php echo esc_attr( $evg_listing->id ); ?>">
				<?php endif; ?>

				<div class="evg-input-group">
					<label><?php esc_html_e( 'Import Graded Card (Optional)', 'evg' ); ?></label>
					<select id="evg_graded_card_picker" name="card_id" class="evg-control-field">
						<option value=""><?php esc_html_e( '— Custom / Standalone —', 'evg' ); ?></option>
						<?php foreach ( $evg_available_graded_cards as $c ) : ?>
							<option value="<?php echo esc_attr( $c->id ); ?>" 
									data-title="<?php echo esc_attr( $c->card_name ); ?>"
									data-set="<?php echo esc_attr( $c->set_name ); ?>"
									data-number="<?php echo esc_attr( $c->card_number ); ?>"
									data-lang="<?php echo esc_attr( $c->language ); ?>"
									data-grade="<?php echo esc_attr( $c->final_grade ); ?>"
									data-image="<?php echo esc_attr( $c->front_image_url ); ?>"
									<?php if ( $evg_listing ) selected( $evg_listing->card_id, $c->id ); ?>>
								<?php echo esc_html( $c->card_name ); ?> &mdash; <?php echo esc_html( $c->set_name ); ?> #<?php echo esc_html( $c->card_number ); ?> (Grade: <?php echo esc_html( $c->final_grade ? $c->final_grade : __( 'Ungraded', 'evg' ) ); ?>) [Order #<?php echo esc_html( $c->order_number ); ?>]
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="evg-input-group">
					<label><?php esc_html_e( 'Title', 'evg' ); ?></label>
					<input type="text" id="mkt_card_title" name="card_title" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->card_title ) : ''; ?>" required placeholder="<?php esc_attr_e( 'e.g. Charizard VMAX', 'evg' ); ?>">
				</div>

				<div class="evg-input-grid-2">
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Set', 'evg' ); ?></label>
						<input type="text" id="mkt_set_name" name="set_name" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->set_name ) : ''; ?>" required placeholder="<?php esc_attr_e( 'e.g. Shining Fates', 'evg' ); ?>">
					</div>
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Number', 'evg' ); ?></label>
						<input type="text" id="mkt_card_number" name="card_number" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->card_number ) : ''; ?>" placeholder="<?php esc_attr_e( 'e.g. 074/072', 'evg' ); ?>">
					</div>
				</div>

				<div class="evg-input-grid-2">
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Language', 'evg' ); ?></label>
						<select id="mkt_language" name="language" class="evg-control-field">
							<option value="English" <?php if ( $evg_listing ) selected( $evg_listing->language, 'English' ); ?>><?php esc_html_e( 'English', 'evg' ); ?></option>
							<option value="Japanese" <?php if ( $evg_listing ) selected( $evg_listing->language, 'Japanese' ); ?>><?php esc_html_e( 'Japanese', 'evg' ); ?></option>
							<option value="Other" <?php if ( $evg_listing ) selected( $evg_listing->language, 'Other' ); ?>><?php esc_html_e( 'Other', 'evg' ); ?></option>
						</select>
					</div>
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Company', 'evg' ); ?></label>
						<input type="text" name="grading_company" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->grading_company ) : 'Elite Vault Grading'; ?>" required>
					</div>
				</div>

				<div class="evg-input-grid-2">
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Grade (1-10)', 'evg' ); ?></label>
						<select id="mkt_assigned_grade" name="assigned_grade" class="evg-control-field">
							<option value=""><?php esc_html_e( 'Raw / Ungraded', 'evg' ); ?></option>
							<?php for ( $g = 10; $g >= 1; $g-- ) : ?>
								<option value="<?php echo esc_attr( $g ); ?>" <?php if ( $evg_listing ) selected( $evg_listing->assigned_grade, $g ); ?>>
									<?php echo esc_html( $g ); ?>
								</option>
							<?php endfor; ?>
						</select>
					</div>
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Slab Style', 'evg' ); ?></label>
						<input type="text" name="slab_information" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->slab_information ) : 'Elite Vault Sealed Protective Slab'; ?>" placeholder="<?php esc_attr_e( 'e.g. Standard Slab', 'evg' ); ?>">
					</div>
				</div>

				<div class="evg-input-group">
					<label><?php esc_html_e( 'Image URL', 'evg' ); ?></label>
					<div style="display: flex; gap: 8px;">
						<input type="text" id="mkt_image_url" name="image_url" class="evg-control-field" value="<?php echo $evg_listing ? esc_attr( $evg_listing->image_url ) : ''; ?>" placeholder="https://...">
						<button type="button" id="evg_upload_mkt_btn" class="button" style="background:#222; border-color:#444; color:#fff; padding:0 16px;">
							<?php esc_html_e( 'Upload', 'evg' ); ?>
						</button>
					</div>
				</div>

				<div class="evg-input-grid-2">
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Price (£)', 'evg' ); ?></label>
						<input type="number" name="price" class="evg-control-field" min="0.00" step="0.01" value="<?php echo $evg_listing ? esc_attr( number_format( (float) $evg_listing->price, 2, '.', '' ) ) : ''; ?>" required placeholder="0.00">
					</div>
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Stock', 'evg' ); ?></label>
						<input type="number" name="stock_quantity" class="evg-control-field" min="0" value="<?php echo $evg_listing ? esc_attr( $evg_listing->stock_quantity ) : '1'; ?>" required>
					</div>
				</div>

				<div class="evg-input-grid-2">
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Category', 'evg' ); ?></label>
						<select name="category" class="evg-control-field" required>
							<?php foreach ( $evg_categories as $cat ) : ?>
								<option value="<?php echo esc_attr( $cat ); ?>" <?php if ( $evg_listing ) selected( $evg_listing->category, $cat ); ?>>
									<?php echo esc_html( $cat ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="evg-input-group">
						<label><?php esc_html_e( 'Status', 'evg' ); ?></label>
						<select name="status" class="evg-control-field" required>
							<option value="Available" <?php if ( $evg_listing ) selected( $evg_listing->status, 'Available' ); ?>><?php esc_html_e( 'Available', 'evg' ); ?></option>
							<option value="Sold" <?php if ( $evg_listing ) selected( $evg_listing->status, 'Sold' ); ?>><?php esc_html_e( 'Sold', 'evg' ); ?></option>
							<option value="Hidden" <?php if ( $evg_listing ) selected( $evg_listing->status, 'Hidden' ); ?>><?php esc_html_e( 'Hidden', 'evg' ); ?></option>
						</select>
					</div>
				</div>

				<button type="submit" class="evg-btn-publish">
					<?php echo $evg_listing ? esc_html__( 'Update Listing', 'evg' ) : esc_html__( 'Publish Listing', 'evg' ); ?>
				</button>
			</form>
		</div>
	</div>

	<script>
		jQuery(document).ready(function($) {
			$('#evg_graded_card_picker').on('change', function() {
				var opt = $(this).find(':selected');
				if (opt.val()) {
					if (opt.data('title')) $('#mkt_card_title').val(opt.data('title'));
					if (opt.data('set')) $('#mkt_set_name').val(opt.data('set'));
					if (opt.data('number')) $('#mkt_card_number').val(opt.data('number'));
					if (opt.data('lang')) $('#mkt_language').val(opt.data('lang'));
					if (opt.data('grade')) $('#mkt_assigned_grade').val(opt.data('grade'));
					if (opt.data('image')) $('#mkt_image_url').val(opt.data('image'));
				}
			});

			$('#evg_upload_mkt_btn').on('click', function(e) {
				e.preventDefault();
				if (typeof wp !== 'undefined' && wp.media) {
					var custom_uploader = wp.media({
						title: '<?php echo esc_js( __( 'Select Card Image', 'evg' ) ); ?>',
						button: { text: '<?php echo esc_js( __( 'Use Image', 'evg' ) ); ?>' },
						multiple: false
					}).on('select', function() {
						var attachment = custom_uploader.state().get('selection').first().toJSON();
						$('#mkt_image_url').val(attachment.url);
					}).open();
				} else {
					alert('<?php echo esc_js( __( 'WordPress Media Library is not available. Please paste an image URL directly.', 'evg' ) ); ?>');
				}
			});
		});
	</script>
	<?php
}