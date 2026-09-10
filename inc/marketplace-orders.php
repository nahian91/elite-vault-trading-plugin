<?php
/**
 * EVG Module: Marketplace Purchases & Orders Management
 * Dedicated admin ledger for tracking sales of shop cards, payments, and outbound parcel tracking.
 * Pagination configured to 5 items per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function evg_marketplace_orders_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not possess the required privilege level for Marketplace Orders.', 'evg-platform' ) . '</p></div>';
        return;
    }

    global $wpdb;

    $table_orders      = $wpdb->prefix . 'evg_orders';
    $table_marketplace = $wpdb->prefix . 'evg_marketplace';
    $table_cards       = $wpdb->prefix . 'evg_cards';

    // ---------------------------------------------------------
    // Handle Order Updates
    // ---------------------------------------------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_update_order_nonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_POST['evg_update_order_nonce'] ), 'evg_update_marketplace_order' ) ) {
            $order_id        = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
            $raw_ship_status = isset( $_POST['shipping_status'] ) ? sanitize_text_field( wp_unslash( $_POST['shipping_status'] ) ) : 'Processing';
            $tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( wp_unslash( $_POST['tracking_number'] ) ) : '';
            $raw_pay_status  = isset( $_POST['payment_status'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_status'] ) ) : 'Paid';

            $allowed_shipping = array( 'Processing', 'Dispatched', 'Delivered' );
            $allowed_payment  = array( 'Paid', 'Pending', 'Refunded', 'Partial Refund' );

            $shipping_status = in_array( $raw_ship_status, $allowed_shipping, true ) ? $raw_ship_status : 'Processing';
            $payment_status  = in_array( $raw_pay_status, $allowed_payment, true ) ? $raw_pay_status : 'Paid';

            if ( $order_id > 0 ) {
                $wpdb->update(
                    $table_orders,
                    array(
                        'shipping_status' => $shipping_status,
                        'tracking_number' => $tracking_number,
                        'payment_status'  => $payment_status,
                    ),
                    array( 'id' => $order_id ),
                    array( '%s', '%s', '%s' ),
                    array( '%d' )
                );

                if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                    Elite_Vault_Grading_System::log_activity( "Updated Marketplace Order #{$order_id} (Shipping: {$shipping_status}, Payment: {$payment_status})" );
                }
            }

            $redirect_url = admin_url( 'admin.php?page=evg_tab_marketplace-orders&order_updated=1' );
            if ( ! headers_sent() ) {
                wp_safe_redirect( $redirect_url );
                exit;
            } else {
                echo '<script>window.location.href = "' . esc_url( $redirect_url ) . '";</script>';
                exit;
            }
        }
    }

    if ( isset( $_GET['order_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Marketplace order parameters successfully updated.', 'evg-platform' ) . '</p></div>';
    }

    // Comprehensive retrieval supporting both declared card links and catalogued stock items
    $orders = $wpdb->get_results( "
        SELECT o.*, 
               u.display_name, u.user_email, 
               COALESCE(c.card_name, m.card_title, 'Graded Slab') AS item_title,
               COALESCE(c.set_name, m.set_name, 'EVG Vault') AS item_set,
               COALESCE(c.card_number, m.card_number, '') AS item_number,
               COALESCE(c.final_grade, m.assigned_grade, NULL) AS item_grade,
               COALESCE(c.front_image_url, m.image_url, '') AS item_image
        FROM {$table_orders} o
        LEFT JOIN {$wpdb->users} u ON o.customer_id = u.ID
        LEFT JOIN {$table_cards} c ON o.card_id = c.id
        LEFT JOIN {$table_marketplace} m ON o.marketplace_item_id = m.id
        ORDER BY o.purchased_at DESC
    " );
    ?>
    <style>
        :root {
            --evg-gold: #d4af37;
            --evg-gold-light: #f3e5ab;
            --evg-border: #222224;
            --evg-bg-card: #0f0f11;
            --evg-text-muted: #8e8e93;
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

        /* DataTables Custom UI Styling */
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

    <div class="evg-order-header">
        <div>
            <h1><?php esc_html_e( 'Marketplace Orders', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Audit sales of authenticated slabs, manage dispatches, and upload courier tracking references.', 'evg-platform' ); ?></p>
        </div>
        <div style="color: var(--evg-text-muted); font-family: monospace; font-size: 12px;">
            <?php printf( esc_html__( '%d Orders Total', 'evg-platform' ), count( $orders ) ); ?>
        </div>
    </div>

    <div class="evg-order-table-shell">
        <table class="evg-order-table evg-datatable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order Ref', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Purchased Slab', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Buyer Account', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Amount', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Payment', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Shipping Status', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Dispatch Tracking', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Actions', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $orders ) ) : ?>
                    <?php foreach ( $orders as $ord ) : 
                        $timestamp = strtotime( $ord->purchased_at );
                    ?>
                        <tr>
                            <td data-order="<?php echo esc_attr( $timestamp ); ?>">
                                <strong style="color: #d4af37; font-family: monospace;">#<?php echo esc_html( $ord->order_number ); ?></strong><br>
                                <span style="font-size: 11px; color: var(--evg-text-muted);"><?php echo esc_html( date_i18n( 'M j, Y', $timestamp ) ); ?></span>
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
                                <strong><?php echo esc_html( $ord->display_name ? $ord->display_name : 'Guest' ); ?></strong><br>
                                <small style="color: #8e8e93;"><?php echo esc_html( $ord->user_email ); ?></small>
                            </td>
                            <td><strong style="color: #fff; font-family: monospace;">&pound;<?php echo esc_html( number_format( (float) $ord->amount_paid, 2 ) ); ?></strong></td>
                            <td><span style="color: #34c759; font-weight: 700; font-size: 11px;">● <?php echo esc_html( $ord->payment_status ); ?></span></td>
                            <td>
                                <span class="evg-order-badge evg-ship-<?php echo esc_attr( $ord->shipping_status ); ?>">
                                    <?php echo esc_html( $ord->shipping_status ); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-family: monospace; color: #f3e5ab; font-size: 11px;">
                                    <?php echo esc_html( $ord->tracking_number ? $ord->tracking_number : '—' ); ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace-orders' ) ); ?>" style="display: inline-flex; gap: 6px; align-items: center;">
                                    <?php wp_nonce_field( 'evg_update_marketplace_order', 'evg_update_order_nonce' ); ?>
                                    <input type="hidden" name="order_id" value="<?php echo esc_attr( $ord->id ); ?>">
                                    <input type="hidden" name="payment_status" value="<?php echo esc_attr( $ord->payment_status ); ?>">
                                    
                                    <select name="shipping_status" style="background:#141416; border:1px solid #28282b; color:#fff; font-size:11px; padding:5px 8px; border-radius:4px;">
                                        <option value="Processing" <?php selected( $ord->shipping_status, 'Processing' ); ?>>Processing</option>
                                        <option value="Dispatched" <?php selected( $ord->shipping_status, 'Dispatched' ); ?>>Dispatched</option>
                                        <option value="Delivered" <?php selected( $ord->shipping_status, 'Delivered' ); ?>>Delivered</option>
                                    </select>

                                    <input type="text" name="tracking_number" placeholder="Tracking #" value="<?php echo esc_attr( $ord->tracking_number ); ?>" style="background:#141416; border:1px solid #28282b; color:#fff; font-size:11px; padding:5px 8px; border-radius:4px; width: 110px;">
                                    
                                    <button type="submit" style="background:#d4af37; color:#000; border:none; padding:5px 12px; border-radius:4px; font-weight:700; font-size:11px; cursor:pointer;">
                                        Save
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #8e8e93; padding: 40px;">
                            <?php esc_html_e( 'No marketplace slab purchases recorded yet.', 'evg-platform' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        jQuery(document).ready(function($) {
            if ($.fn.DataTable && $('.evg-datatable tbody tr').length > 0 && $('.evg-datatable tbody tr td').length > 1) {
                $('.evg-datatable').DataTable({
                    "pageLength": 5,
                    "lengthMenu": [ [5, 10, 25, 50, -1], [5, 10, 25, 50, "All"] ],
                    "order": [[ 0, "desc" ]],
                    "language": {
                        "search": "Search Orders:",
                        "lengthMenu": "Display _MENU_ orders per page",
                        "info": "Showing _START_ to _END_ of _TOTAL_ marketplace orders",
                        "paginate": {
                            "previous": "← Prev",
                            "next": "Next →"
                        }
                    }
                });
            }
        });
    </script>
    <?php
}