<?php
/**
 * EVG Module: Payments & Billing (Pro Edition)
 * Revenue telemetry, billing reconciliation, transaction tracking, and refund processing.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_transactions_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to view Payments & Billing.', 'evg-platform' ) . '</p></div>';
        return;
    }

    global $wpdb;
    $table_submissions = $wpdb->prefix . 'evg_submissions';

    // ---------------------------------------------------------
    // Handle Form Submissions (Update Transaction Status)
    // ---------------------------------------------------------
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['evg_payment_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['evg_payment_nonce'], 'evg_update_payment' ) ) {
            
            $sub_id         = intval( $_POST['submission_id'] );
            $payment_status = sanitize_text_field( $_POST['payment_status'] );

            $wpdb->update(
                $table_submissions,
                array( 'payment_status' => $payment_status ),
                array( 'id' => $sub_id ),
                array( '%s' ),
                array( '%d' )
            );

            Elite_Vault_Grading_System::log_activity( "Updated Payment Status for Order ID {$sub_id} to: {$payment_status}" );
            echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Transaction record updated successfully.', 'evg-platform' ) . '</p></div>';
        }
    }

    // ---------------------------------------------------------
    // Fetch Aggregate Financial Metrics
    // ---------------------------------------------------------
    $metrics = $wpdb->get_results( "SELECT payment_status, SUM(total_amount) as total FROM {$table_submissions} GROUP BY payment_status" );
    
    $total_revenue  = 0;
    $total_pending  = 0;
    $total_refunded = 0;

    foreach ( $metrics as $m ) {
        if ( $m->payment_status === 'Paid' ) {
            $total_revenue = floatval( $m->total );
        } elseif ( $m->payment_status === 'Pending' ) {
            $total_pending = floatval( $m->total );
        } elseif ( $m->payment_status === 'Refunded' || $m->payment_status === 'Partial Refund' ) {
            $total_refunded += floatval( $m->total );
        }
    }

    // ---------------------------------------------------------
    // Fetch All Transaction Entries
    // ---------------------------------------------------------
    $transactions = $wpdb->get_results( "
        SELECT s.id, s.order_number, s.submission_date, s.total_amount, s.payment_status, s.current_stage, s.total_cards, s.service_type, u.display_name, u.user_email 
        FROM {$table_submissions} s
        LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
        ORDER BY s.submission_date DESC
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

        .evg-tx-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .evg-tx-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .evg-tx-header p {
            color: var(--evg-text-muted);
            font-size: 13px;
            margin: 0;
        }

        .evg-stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .evg-stat-box {
            background: #0f0f0f;
            border: 1px solid #222222;
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
            transition: transform 0.2s ease, border-color 0.2s ease;
        }
        .evg-stat-box:hover {
            transform: translateY(-2px);
            border-color: #d4af37;
        }
        .evg-stat-icon-wrap {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .evg-stat-icon-wrap svg {
            width: 22px;
            height: 22px;
        }
        .evg-stat-content h3 {
            font-size: 24px;
            font-weight: 800;
            margin: 0 0 2px 0;
            line-height: 1;
        }
        .evg-stat-content p {
            font-size: 11px;
            color: #8e8e93;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 600;
        }

        .evg-panel-shell {
            background: var(--evg-bg-card);
            border: 1px solid var(--evg-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }

        .evg-tx-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .evg-tx-table thead th {
            background: #141416;
            color: var(--evg-text-muted);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 16px 20px;
            border-bottom: 1px solid var(--evg-border);
            text-align: left;
        }
        .evg-tx-table tbody td {
            padding: 16px 20px;
            border-bottom: 1px solid #1a1a1d;
            color: #ffffff;
            font-size: 13px;
            vertical-align: middle;
        }
        .evg-tx-table tbody tr {
            transition: background 0.2s ease;
        }
        .evg-tx-table tbody tr:hover {
            background: rgba(212, 175, 55, 0.02);
        }
        .evg-tx-table tbody tr:last-child td {
            border-bottom: none;
        }

        .evg-stage-chip {
            display: inline-block;
            background: #18181a;
            border: 1px solid #2c2c2e;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            white-space: nowrap;
        }

        .evg-pay-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .evg-pay-Paid { color: #34c759; }
        .evg-pay-Pending { color: #ff9f0a; }
        .evg-pay-Refunded { color: #ff453a; }
        .evg-pay-Partial { color: #0a84ff; }
        .evg-pay-Failed { color: #ff453a; }

        .evg-select-control {
            background: #141416;
            border: 1px solid #28282b;
            color: #ffffff;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .evg-select-control:focus {
            border-color: #d4af37;
        }

        .evg-btn-save-sm {
            background: var(--evg-gold);
            color: #0a0a0a;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 800;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.2s ease;
        }
        .evg-btn-save-sm:hover {
            background: #f3e5ab;
        }

        .evg-btn-pdf-link {
            border: 1px solid var(--evg-border);
            color: #8e8e93;
            padding: 5px 8px;
            font-size: 12px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .evg-btn-pdf-link:hover {
            border-color: var(--evg-gold);
            color: var(--evg-gold);
        }
    </style>

    <div class="evg-tx-header">
        <div>
            <h1><?php esc_html_e( 'Payments & Financial Ledger', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Audit transaction statuses, balance reconciliations, customer invoices, and refund workflows.', 'evg-platform' ); ?></p>
        </div>
        <div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
            <?php printf( esc_html__( '%d Billing Transactions Recorded', 'evg-platform' ), count( $transactions ) ); ?>
        </div>
    </div>

    <!-- Financial Matrix -->
    <div class="evg-stat-grid">
        <div class="evg-stat-box">
            <div class="evg-stat-icon-wrap" style="background: rgba(52, 199, 89, 0.08); border: 1px solid rgba(52, 199, 89, 0.25);">
                <svg style="fill: #34c759;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 320 512"><path d="M160 0c17.7 0 32 14.3 32 32V67.7c1.6 .2 3.1 .4 4.7 .7c.4 .1 .9 .1 1.3 .2l10.1 2.8c58.6 16 104.1 70.1 106 130.5c.3 11-8.4 20.3-19.5 20.3H253.2c-10.4 0-19.1-8-19.8-18.4c-1.3-19.4-12.8-35.8-31.5-44.4c-11.8-5.5-25.2-7.5-38.3-5.6C153 155.3 144 163.6 144 174.1c0 9.2 6.1 17.3 14.9 20l25.8 7.7c33.3 10 63.3 27.5 87 50.9c25.4 25.1 40.3 59.9 40.3 97.4c0 62.5-43.7 114.9-104 126.9V512c0 17.7-14.3 32-32 32s-32-14.3-32-32V444.3c-1.6-.2-3.1-.4-4.7-.7c-.4-.1-.9-.1-1.3-.2l-10.1-2.8c-58.6-16-104.1-70.1-106-130.5c-.3-11 8.4-20.3 19.5-20.3H81.2c10.4 0 19.1 8 19.8 18.4c1.3 19.4 12.8 35.8 31.5 44.4c11.8 5.5 25.2 7.5 38.3 5.6C181.4 356.7 190.4 348.4 190.4 338c0-9.2-6.1-17.3-14.9-20l-25.8-7.7C116.3 300.3 86.4 282.8 62.6 259.4C37.2 234.3 22.4 199.5 22.4 162c0-62.5 43.7-114.9 104-126.9V32c0-17.7 14.3-32 32-32z"/></svg>
            </div>
            <div class="evg-stat-content">
                <h3 style="color: #34c759;">&pound;<?php echo esc_html( number_format( $total_revenue, 2 ) ); ?></h3>
                <p><?php esc_html_e( 'Settled Revenue', 'evg-platform' ); ?></p>
            </div>
        </div>

        <div class="evg-stat-box">
            <div class="evg-stat-icon-wrap" style="background: rgba(255, 159, 10, 0.08); border: 1px solid rgba(255, 159, 10, 0.25);">
                <svg style="fill: #ff9f0a;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M256 0a256 256 0 1 1 0 512A256 256 0 1 1 256 0zM232 120V256c0 8 4 15.5 10.7 20l96 64c11 7.4 25.9 4.4 33.3-6.7s4.4-25.9-6.7-33.3L280 243.2V120c0-13.3-10.7-24-24-24s-24 10.7-24 24z"/></svg>
            </div>
            <div class="evg-stat-content">
                <h3 style="color: #ff9f0a;">&pound;<?php echo esc_html( number_format( $total_pending, 2 ) ); ?></h3>
                <p><?php esc_html_e( 'Pending Invoices', 'evg-platform' ); ?></p>
            </div>
        </div>

        <div class="evg-stat-box">
            <div class="evg-stat-icon-wrap" style="background: rgba(255, 69, 58, 0.08); border: 1px solid rgba(255, 69, 58, 0.25);">
                <svg style="fill: #ff453a;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M48.5 224H40c-13.3 0-24-10.7-24-24V72c0-9.7 5.8-18.5 14.8-22.2s19.3-1.7 26.2 5.2L98.6 96.6c87.6-86.5 228.7-86.2 315.8 1c87.5 87.5 87.5 229.3 0 316.8s-229.3 87.5-316.8 0c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0c62.5 62.5 163.8 62.5 226.3 0s62.5-163.8 0-226.3c-62.2-62.2-162.7-62.5-225.3-1L185 183c6.9 6.9 8.9 17.2 5.2 26.2s-12.5 14.8-22.2 14.8H48.5z"/></svg>
            </div>
            <div class="evg-stat-content">
                <h3 style="color: #ff453a;">&pound;<?php echo esc_html( number_format( $total_refunded, 2 ) ); ?></h3>
                <p><?php esc_html_e( 'Total Refunded', 'evg-platform' ); ?></p>
            </div>
        </div>
    </div>

    <!-- Transactions Data Table -->
    <div class="evg-panel-shell">
        <table class="evg-tx-table evg-datatable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order Ref', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Collector Details', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Date Created', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Pipeline Stage', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Total Billed', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Settlement Status', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Billing Controls', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $transactions ) ) : ?>
                    <?php foreach ( $transactions as $tx ) : 
                        $pay_class = 'evg-pay-' . ( strpos( $tx->payment_status, 'Partial' ) !== false ? 'Partial' : $tx->payment_status );
                        $timestamp = strtotime( $tx->submission_date );
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $tx->id ) ); ?>" style="font-family: monospace; font-weight: 700; color: var(--evg-gold); text-decoration: none;">
                                    #<?php echo esc_html( $tx->order_number ); ?>
                                </a>
                            </td>
                            <td>
                                <strong style="color: #ffffff;"><?php echo esc_html( $tx->display_name ? $tx->display_name : 'Guest' ); ?></strong><br>
                                <span style="color: var(--evg-text-muted); font-size: 12px;"><?php echo esc_html( $tx->user_email ); ?></span>
                            </td>
                            <td data-order="<?php echo esc_attr( $timestamp ); ?>" style="color: var(--evg-text-muted); font-size: 12px;">
                                <?php echo esc_html( date_i18n( 'M j, Y', $timestamp ) ); ?>
                            </td>
                            <td>
                                <span class="evg-stage-chip"><?php echo esc_html( $tx->current_stage ); ?></span>
                            </td>
                            <td>
                                <strong style="color: #ffffff; font-size: 14px;">&pound;<?php echo esc_html( number_format( $tx->total_amount, 2 ) ); ?></strong>
                            </td>
                            <td>
                                <span class="evg-pay-status <?php echo esc_attr( $pay_class ); ?>">
                                    ● <?php echo esc_html( $tx->payment_status ); ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <form method="post" style="display: inline-flex; gap: 6px; align-items: center; margin: 0;">
                                    <?php wp_nonce_field( 'evg_update_payment', 'evg_payment_nonce' ); ?>
                                    <input type="hidden" name="submission_id" value="<?php echo esc_attr( $tx->id ); ?>">
                                    <select name="payment_status" class="evg-select-control">
                                        <option value="Pending" <?php selected( $tx->payment_status, 'Pending' ); ?>><?php esc_html_e( 'Pending', 'evg-platform' ); ?></option>
                                        <option value="Paid" <?php selected( $tx->payment_status, 'Paid' ); ?>><?php esc_html_e( 'Paid', 'evg-platform' ); ?></option>
                                        <option value="Partial Refund" <?php selected( $tx->payment_status, 'Partial Refund' ); ?>><?php esc_html_e( 'Partial Refund', 'evg-platform' ); ?></option>
                                        <option value="Refunded" <?php selected( $tx->payment_status, 'Refunded' ); ?>><?php esc_html_e( 'Refunded', 'evg-platform' ); ?></option>
                                        <option value="Failed" <?php selected( $tx->payment_status, 'Failed' ); ?>><?php esc_html_e( 'Failed', 'evg-platform' ); ?></option>
                                    </select>
                                    <button type="submit" class="evg-btn-save-sm">
                                        <?php esc_html_e( 'Save', 'evg-platform' ); ?>
                                    </button>
                                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=evg_download_invoice&submission_id=' . $tx->id ) ); ?>" target="_blank" class="evg-btn-pdf-link" title="<?php esc_attr_e( 'Download Invoice PDF', 'evg-platform' ); ?>">
                                        📄
                                    </a>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
                            <?php esc_html_e( 'No financial transactions logged in system.', 'evg-platform' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.evg-datatable').DataTable({
                "pageLength": 25,
                "order": [[ 2, "desc" ]],
                "language": { "search": "Search Transactions:" }
            });
        });
    </script>
    <?php
}