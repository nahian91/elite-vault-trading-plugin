<?php
/**
 * EVG Module: Marketplace Purchases & Orders Management
 * Dedicated admin ledger for tracking sales of shop cards, payments, outbound parcel tracking, and order removal.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST/GET marketplace order actions (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_marketplace_order_lifecycle_actions' );

function evg_handle_marketplace_order_lifecycle_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_marketplace-orders' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
		return;
	}

	global $wpdb;
	$evg_table_orders = $wpdb->prefix . 'evg_orders';

	// ---------------------------------------------------------
	// 1. Handle Order Deletion
	// ---------------------------------------------------------
	if ( isset( $_GET['action'], $_GET['order_id'] ) && 'delete_order' === $_GET['action'] ) {
		$evg_del_order_id = absint( $_GET['order_id'] );
		check_admin_referer( 'evg_delete_marketplace_order_' . $evg_del_order_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$evg_table_orders} WHERE id = %d", $evg_del_order_id ) );

		if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
			Elite_Vault_Grading_System::log_activity( "Deleted Marketplace Order #{$evg_del_order_id}." );
		}

		evg_marketplace_orders_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace-orders&order_deleted=1' ) );
	}

	// ---------------------------------------------------------
	// 2. Handle Order Updates
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_update_order_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_update_order_nonce'] ) ), 'evg_update_marketplace_order' ) ) {
			$evg_order_id        = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
			$evg_raw_ship_status = isset( $_POST['shipping_status'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_status'] ) ) : 'Processing';
			$evg_tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) ) : '';
			$evg_raw_pay_status  = isset( $_POST['payment_status'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_status'] ) ) : 'Paid';

			$evg_allowed_shipping = array( 'Processing', 'Dispatched', 'Delivered' );
			$evg_allowed_payment  = array( 'Paid', 'Pending', 'Refunded', 'Partial Refund' );

			$evg_shipping_status = in_array( $evg_raw_ship_status, $evg_allowed_shipping, true ) ? $evg_raw_ship_status : 'Processing';
			$evg_payment_status  = in_array( $evg_raw_pay_status, $evg_allowed_payment, true ) ? $evg_raw_pay_status : 'Paid';

			if ( $evg_order_id > 0 ) {
				$wpdb->update(
					$evg_table_orders,
					array(
						'shipping_status' => $evg_shipping_status,
						'tracking_number' => $evg_tracking_number,
						'payment_status'  => $evg_payment_status,
					),
					array( 'id' => $evg_order_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);

				if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
					Elite_Vault_Grading_System::log_activity( "Updated Marketplace Order #{$evg_order_id} (Shipping: {$evg_shipping_status}, Payment: {$evg_payment_status})" );
				}
			}

			evg_marketplace_orders_safe_redirect( admin_url( 'admin.php?page=evg_tab_marketplace-orders&order_updated=1' ) );
		}
	}
}

/**
 * Universal redirect helper that won't throw header warnings.
 *
 * @param string $location Destination URL.
 */
function evg_marketplace_orders_safe_redirect( $location ) {
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
 * Render Marketplace Orders Tab.
 */
function evg_marketplace_orders_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not possess the required privilege level for Marketplace Orders.', 'evg' ) . '</p></div>';
		return;
	}

	global $wpdb;

	$evg_table_orders      = $wpdb->prefix . 'evg_orders';
	$evg_table_marketplace = $wpdb->prefix . 'evg_marketplace';
	$evg_table_cards       = $wpdb->prefix . 'evg_cards';

	// ---------------------------------------------------------
	// Notifications Display
	// ---------------------------------------------------------
	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['order_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Marketplace order parameters successfully updated.', 'evg' ) . '</p></div>';
	}
	if ( isset( $_GET['order_deleted'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Marketplace order record permanently removed.', 'evg' ) . '</p></div>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// Retrieve orders.
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$evg_orders = $wpdb->get_results(
		"SELECT o.*, 
				u.display_name, u.user_email, 
				COALESCE(c.card_name, m.card_title, 'Graded Slab') AS item_title,
				COALESCE(c.set_name, m.set_name, 'EVG Vault') AS item_set,
				COALESCE(c.card_number, m.card_number, '') AS item_number,
				COALESCE(c.final_grade, m.assigned_grade, NULL) AS item_grade,
				COALESCE(c.front_image_url, m.image_url, '') AS item_image
		FROM {$evg_table_orders} o
		LEFT JOIN {$wpdb->users} u ON o.customer_id = u.ID
		LEFT JOIN {$evg_table_cards} c ON o.card_id = c.id
		LEFT JOIN {$evg_table_marketplace} m ON o.marketplace_item_id = m.id
		ORDER BY o.purchased_at DESC"
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

		.evg-order-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 15px; }
		.evg-order-header h1 { font-size: 26px; font-weight: 800; color: #fff; margin: 0 0 4px 0; }
		.evg-order-header p { color: var(--evg-text-muted); font-size: 13px; margin: 0; }
		
		.evg-order-table-shell { background: var(--evg-bg-card); border: 1px solid var(--evg-border); border-radius: 14px; overflow-x: auto; box-shadow: 0 16px 36px rgba(0,0,0,0.5); }
		.evg-order-table { width: 100%; border-collapse: separate; border-spacing: 0; }
		.evg-order-table thead th { background: #141416; color: var(--evg-text-muted); font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 16px 20px; border-bottom: 1px solid var(--evg-border); text-align: left; white-space: nowrap; }
		.evg-order-table tbody td { padding: 18px 20px; border-bottom: 1px solid #1a1a1d; color: #fff; font-size: 13px; vertical-align: middle; }
		.evg-order-table tbody tr { transition: background 0.2s ease; }
		.evg-order-table tbody tr:hover td { background: rgba(212, 175, 55, 0.02); }
		.evg-order-table tbody tr:last-child td { border-bottom: none; }
		
		.evg-order-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; text-transform: uppercase; font-family: monospace; display: inline-block; }
		.evg-ship-Processing { background: rgba(255, 159, 10, 0.1); color: #ff9f0a; border: 1px solid rgba(255, 159, 10, 0.3); }
		.evg-ship-Dispatched { background: rgba(52, 199, 89, 0.1); color: #34c759; border: 1px solid rgba(52, 199, 89, 0.3); }
		.evg-ship-Delivered  { background: rgba(10, 132, 255, 0.1); color: #0a84ff; border: 1px solid rgba(10, 132, 255, 0.3); }

		.evg-grade-mini-tag {
			background: var(--evg-gold);
			color: #0a0a0a;
			font-weight: 800;
			font-size: 10px;
			padding: 1px 6px;
			border-radius: 4px;
			margin-left: 6px;
			display: inline-block;
		}

		.evg-actions-row {
			display: inline-flex;
			align-items: center;
			gap: 8px;
			justify-content: flex-end;
		}

		.evg-btn-order-delete {
			display: inline-flex;
			align-items: center;
			justify-content: center;
			padding: 5px 9px;
			border-radius: 4px;
			background: rgba(255, 69, 58, 0.08);
			border: 1px solid rgba(255, 69, 58, 0.25);
			color: var(--evg-danger);
			font-size: 12px;
			font-weight: 700;
			text-decoration: none;
			transition: all 0.2s ease;
			cursor: pointer;
		}
		.evg-btn-order-delete:hover {
			background: var(--evg-danger);
			color: #ffffff;
			border-color: var(--evg-danger);
			box-shadow: 0 0 10px rgba(255, 69, 58, 0.35);
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

	<div class="evg-order-header">
		<div>
			<h1><?php esc_html_e( 'Marketplace Orders', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Audit sales of authenticated slabs, manage dispatches, and upload courier tracking references.', 'evg' ); ?></p>
		</div>
		<div style="color: var(--evg-text-muted); font-family: monospace; font-size: 12px;">
			<?php
			printf(
				/* translators: %s: Count of orders */
				esc_html__( '%s Orders Total', 'evg' ),
				esc_html( number_format_i18n( count( $evg_orders ) ) )
			);
			?>
		</div>
	</div>

	<div class="evg-order-table-shell">
		<table class="evg-order-table evg-orders-datatable">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Order Ref', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Purchased Slab', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Buyer Account', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Payment', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Shipping Status', 'evg' ); ?></th>
					<th><?php esc_html_e( 'Dispatch Tracking', 'evg' ); ?></th>
					<th style="text-align: right;"><?php esc_html_e( 'Actions', 'evg' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! empty( $evg_orders ) ) : ?>
					<?php foreach ( $evg_orders as $ord ) : 
						$evg_timestamp   = strtotime( $ord->purchased_at );
						$evg_del_ord_url = wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace-orders&action=delete_order&order_id=' . $ord->id ), 'evg_delete_marketplace_order_' . $ord->id );
					?>
						<tr>
							<td data-order="<?php echo esc_attr( $evg_timestamp ); ?>">
								<strong style="color: #d4af37; font-family: monospace;">#<?php echo esc_html( $ord->order_number ); ?></strong><br>
								<span style="font-size: 11px; color: var(--evg-text-muted);"><?php echo esc_html( date_i18n( 'M j, Y', $evg_timestamp ) ); ?></span>
							</td>
							<td>
								<strong><?php echo esc_html( $ord->item_title ); ?></strong>
								<?php if ( ! empty( $ord->item_grade ) ) : ?>
									<span class="evg-grade-mini-tag">
										EVG <?php echo esc_html( $ord->item_grade ); ?>
									</span>
								<?php endif; ?>
								<br><small style="color: #8e8e93;"><?php echo esc_html( $ord->item_set ); ?> <?php echo ! empty( $ord->item_number ) ? '#' . esc_html( $ord->item_number ) : ''; ?></small>
							</td>
							<td>
								<strong><?php echo esc_html( $ord->display_name ? $ord->display_name : __( 'Guest', 'evg' ) ); ?></strong><br>
								<small style="color: #8e8e93;"><?php echo esc_html( $ord->user_email ); ?></small>
							</td>
							<td><strong style="color: #fff; font-family: monospace;">&pound;<?php echo esc_html( number_format_i18n( (float) $ord->amount_paid, 2 ) ); ?></strong></td>
							<td><span style="color: #34c759; font-weight: 700; font-size: 11px;">&bull; <?php echo esc_html( $ord->payment_status ); ?></span></td>
							<td>
								<span class="evg-order-badge evg-ship-<?php echo esc_attr( $ord->shipping_status ); ?>">
									<?php echo esc_html( $ord->shipping_status ); ?>
								</span>
							</td>
							<td>
								<span style="font-family: monospace; color: #f3e5ab; font-size: 11px;">
									<?php echo esc_html( $ord->tracking_number ? $ord->tracking_number : '&mdash;' ); ?>
								</span>
							</td>
							<td style="text-align: right; white-space: nowrap;">
								<div class="evg-actions-row">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace-orders' ) ); ?>" style="display: inline-flex; gap: 6px; align-items: center;">
										<?php wp_nonce_field( 'evg_update_marketplace_order', 'evg_update_order_nonce' ); ?>
										<input type="hidden" name="order_id" value="<?php echo esc_attr( $ord->id ); ?>">
										<input type="hidden" name="payment_status" value="<?php echo esc_attr( $ord->payment_status ); ?>">
										
										<select name="shipping_status" style="background:#141416; border:1px solid #28282b; color:#fff; font-size:11px; padding:5px 8px; border-radius:4px;">
											<option value="Processing" <?php selected( $ord->shipping_status, 'Processing' ); ?>><?php esc_html_e( 'Processing', 'evg' ); ?></option>
											<option value="Dispatched" <?php selected( $ord->shipping_status, 'Dispatched' ); ?>><?php esc_html_e( 'Dispatched', 'evg' ); ?></option>
											<option value="Delivered" <?php selected( $ord->shipping_status, 'Delivered' ); ?>><?php esc_html_e( 'Delivered', 'evg' ); ?></option>
										</select>

										<input type="text" name="tracking_number" placeholder="<?php esc_attr_e( 'Tracking #', 'evg' ); ?>" value="<?php echo esc_attr( $ord->tracking_number ); ?>" style="background:#141416; border:1px solid #28282b; color:#fff; font-size:11px; padding:5px 8px; border-radius:4px; width: 110px;">
										
										<button type="submit" style="background:#d4af37; color:#000; border:none; padding:5px 12px; border-radius:4px; font-weight:700; font-size:11px; cursor:pointer;">
											<?php esc_html_e( 'Save', 'evg' ); ?>
										</button>
									</form>

									<a href="<?php echo esc_url( $evg_del_ord_url ); ?>" class="evg-btn-order-delete" onclick="return confirm('<?php echo esc_js( __( 'Are you sure you want to permanently delete this marketplace order record?', 'evg' ) ); ?>');" title="<?php esc_attr_e( 'Delete Order', 'evg' ); ?>">
										&times;
									</a>
								</div>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="8" style="text-align: center; color: #8e8e93; padding: 40px;">
							<?php esc_html_e( 'No marketplace slab purchases recorded yet.', 'evg' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<script>
		jQuery(document).ready(function($) {
			if ($.fn.DataTable && $('.evg-orders-datatable tbody tr').length > 0 && $('.evg-orders-datatable tbody tr td').length > 1) {
				$('.evg-orders-datatable').DataTable({
					"pageLength": 10,
					"lengthMenu": [ [10, 25, 50, 100, -1], [10, 25, 50, 100, "All"] ],
					"order": [[ 0, "desc" ]],
					"language": {
						"search": "<?php echo esc_js( __( 'Search Orders:', 'evg' ) ); ?>",
						"lengthMenu": "<?php echo esc_js( __( 'Display _MENU_ orders per page', 'evg' ) ); ?>",
						"info": "<?php echo esc_js( __( 'Showing _START_ to _END_ of _TOTAL_ marketplace orders', 'evg' ) ); ?>",
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