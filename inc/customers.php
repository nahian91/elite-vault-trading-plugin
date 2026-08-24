<?php
/**
 * EVG Module: Customer Accounts (Pro Dashboard Design)
 * High-end collector profile UI, lifetime value analytics, address metadata, and submission ledger.
 * Standard pagination configured to 5 items per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_customers_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to view Customer Accounts.', 'evg-platform' ) . '</p></div>';
        return;
    }

    global $wpdb;
    $table_submissions = $wpdb->prefix . 'evg_submissions';

    $action      = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
    $customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;

    if ( 'view' === $action && $customer_id > 0 ) {
        evg_render_pro_customer_profile( $customer_id, $table_submissions );
    } else {
        evg_render_pro_customers_list( $table_submissions );
    }
}

/**
 * Pro ListView: Registered Collectors
 */
function evg_render_pro_customers_list( $table_submissions ) {
    global $wpdb;

    $customers = $wpdb->get_results( "
        SELECT u.ID, u.user_email, u.display_name, u.user_registered,
               COUNT(s.id) as total_orders,
               COALESCE(SUM(s.total_amount), 0) as total_spent
        FROM {$wpdb->users} u
        LEFT JOIN {$table_submissions} s ON u.ID = s.customer_id
        GROUP BY u.ID
        ORDER BY u.user_registered DESC
    " );
    ?>
    <style>
        :root {
            --evg-gold: #d4af37;
            --evg-gold-light: #f3e5ab;
            --evg-gold-glow: rgba(212, 175, 55, 0.15);
            --evg-bg-card: #0f0f0f;
            --evg-bg-card-alt: #141414;
            --evg-border: #222222;
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
        .evg-pro-table tbody tr:last-child td {
            border-bottom: none;
        }

        .evg-collector-tag {
            display: inline-flex;
            align-items: center;
            gap: 12px;
        }
        .evg-mini-avatar {
            width: 36px;
            height: 36px;
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

    <div class="evg-pro-header">
        <div>
            <h1><?php esc_html_e( 'Customers', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Manage registered collectors, evaluate lifetime valuation, and audit historic submissions.', 'evg-platform' ); ?></p>
        </div>
        <div style="color: var(--evg-text-muted); font-family: monospace; font-size: 12px;">
            <?php printf( esc_html__( '%d Accounts Total', 'evg-platform' ), count( $customers ) ); ?>
        </div>
    </div>

    <div class="evg-pro-panel">
        <table class="evg-pro-table evg-datatable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Collector', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Joined Date', 'evg-platform' ); ?></th>
                    <th style="text-align: center;"><?php esc_html_e( 'Submissions', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Lifetime Value', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Action', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $customers ) ) : ?>
                    <?php foreach ( $customers as $customer ) : 
                        $joined_time = strtotime( $customer->user_registered );
                    ?>
                        <tr>
                            <td>
                                <div class="evg-collector-tag">
                                    <?php echo get_avatar( $customer->ID, 36, '', '', array( 'class' => 'evg-mini-avatar' ) ); ?>
                                    <div>
                                        <span class="evg-collector-name"><?php echo esc_html( $customer->display_name ? $customer->display_name : 'Collector' ); ?></span>
                                        <span class="evg-collector-email"><?php echo esc_html( $customer->user_email ); ?></span>
                                    </div>
                                </div>
                            </td>
                            <td data-order="<?php echo esc_attr( $joined_time ); ?>" style="color: var(--evg-text-muted);">
                                <?php echo esc_html( date_i18n( 'M j, Y', $joined_time ) ); ?>
                            </td>
                            <td style="text-align: center;">
                                <span class="evg-pill-count"><?php echo esc_html( $customer->total_orders ); ?></span>
                            </td>
                            <td>
                                <span class="evg-spent-badge">&pound;<?php echo esc_html( number_format( $customer->total_spent, 2 ) ); ?></span>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_customers&action=view&customer_id=' . $customer->ID ) ); ?>" class="evg-btn-view">
                                    <?php esc_html_e( 'View Profile →', 'evg-platform' ); ?>
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
            if ($.fn.DataTable && $('.evg-datatable tbody tr').length > 0 && $('.evg-datatable tbody tr td').length > 1) {
                $('.evg-datatable').DataTable({
                    "pageLength": 5,
                    "lengthMenu": [ [5, 10, 25, 50, -1], [5, 10, 25, 50, "All"] ],
                    "order": [[ 1, "desc" ]],
                    "language": {
                        "search": "Search Registry:",
                        "lengthMenu": "Display _MENU_ collectors per page",
                        "info": "Showing _START_ to _END_ of _TOTAL_ customer accounts",
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

/**
 * Pro View: Collector Detailed Profile & Ledger with Expanded Telemetry
 */
function evg_render_pro_customer_profile( $customer_id, $table_submissions ) {
    global $wpdb;

    $user_info = get_userdata( $customer_id );
    if ( ! $user_info ) {
        echo '<p style="color:#ff453a;">' . esc_html__( 'Collector profile not found.', 'evg-platform' ) . '</p>';
        return;
    }

    $house_number  = get_user_meta( $customer_id, 'evg_house_number', true );
    $street        = get_user_meta( $customer_id, 'evg_street_address', true );
    $city          = get_user_meta( $customer_id, 'evg_town_city', true );
    $county        = get_user_meta( $customer_id, 'evg_county', true );
    $postcode      = get_user_meta( $customer_id, 'evg_postcode', true );
    $country       = get_user_meta( $customer_id, 'evg_country', true );
    $mobile        = get_user_meta( $customer_id, 'evg_mobile_number', true );
    $opt_in_offers = get_user_meta( $customer_id, 'evg_opt_in_promotions', true );
    $is_over_18    = get_user_meta( $customer_id, 'evg_age_consent', true );
    
    // Extended Meta Data
    $first_name    = get_user_meta( $customer_id, 'first_name', true );
    $last_name     = get_user_meta( $customer_id, 'last_name', true );
    $website       = $user_info->user_url;
    $user_roles    = implode( ', ', array_map( 'ucfirst', $user_info->roles ) );

    $orders = $wpdb->get_results( $wpdb->prepare( "
        SELECT * FROM {$table_submissions} 
        WHERE customer_id = %d 
        ORDER BY submission_date DESC
    ", $customer_id ) );

    $total_spent   = 0;
    $paid_orders   = 0;
    $active_orders = 0;
    $total_cards   = 0;

    foreach ( $orders as $order ) {
        if ( 'Paid' === $order->payment_status ) {
            $total_spent += floatval( $order->total_amount );
            $paid_orders++;
        }
        if ( ! in_array( $order->current_stage, array( 'Completed', 'Returned To Customer' ), true ) ) {
            $active_orders++;
        }
        $total_cards += intval( $order->total_cards );
    }

    $avg_order_value = $paid_orders > 0 ? ( $total_spent / $paid_orders ) : 0;
    ?>
    <style>
        .evg-profile-grid {
            display: grid;
            grid-template-columns: 380px 1fr;
            gap: 28px;
            align-items: start;
        }

        .evg-card {
            background: #0f0f0f;
            border: 1px solid #222222;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
        }

        .evg-card-hero {
            background: linear-gradient(180deg, #18181a 0%, #0f0f0f 100%);
            padding: 32px 24px 20px;
            text-align: center;
            border-bottom: 1px solid #1f1f22;
            position: relative;
        }
        .evg-avatar-pro {
            width: 88px;
            height: 88px;
            border-radius: 50%;
            border: 2px solid #d4af37;
            padding: 3px;
            background: #0a0a0a;
            box-shadow: 0 8px 24px rgba(212, 175, 55, 0.2);
            object-fit: cover;
        }
        .evg-profile-hero-name {
            font-size: 19px;
            font-weight: 800;
            color: #ffffff;
            margin: 14px 0 2px 0;
        }
        .evg-profile-hero-email {
            font-size: 12px;
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
            font-weight: 700;
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
        }
        .evg-info-row .val {
            color: #ffffff;
            font-weight: 600;
            text-align: right;
        }

        .evg-stage-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.25);
            color: #d4af37;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
        }
        .evg-stage-badge.done {
            background: rgba(52, 199, 89, 0.08);
            border-color: rgba(52, 199, 89, 0.25);
            color: #34c759;
        }

        @media (max-width: 1080px) {
            .evg-profile-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="evg-pro-header">
        <div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_customers' ) ); ?>" class="evg-btn-view" style="margin-bottom: 12px; display: inline-flex;">
                ← <?php esc_html_e( 'Back to Customers', 'evg-platform' ); ?>
            </a>
            <h1><?php echo esc_html( $user_info->display_name ); ?></h1>
            <p><?php printf( esc_html__( 'Collector ID #%d — Member since %s', 'evg-platform' ), $user_info->ID, date_i18n( 'F j, Y', strtotime( $user_info->user_registered ) ) ); ?></p>
        </div>
    </div>

    <div class="evg-profile-grid">
        <!-- Sidebar Meta Card -->
        <div class="evg-card">
            <div class="evg-card-hero">
                <?php echo get_avatar( $user_info->ID, 88, '', '', array( 'class' => 'evg-avatar-pro' ) ); ?>
                <h3 class="evg-profile-hero-name"><?php echo esc_html( $user_info->display_name ); ?></h3>
                <p class="evg-profile-hero-email"><?php echo esc_html( $user_info->user_email ); ?></p>
            </div>

            <div class="evg-stats-matrix">
                <div class="evg-stat-cell">
                    <span class="number"><?php echo esc_html( count( $orders ) ); ?></span>
                    <span class="label"><?php esc_html_e( 'Orders', 'evg-platform' ); ?></span>
                </div>
                <div class="evg-stat-cell">
                    <span class="number" style="color: #ff9f0a;"><?php echo esc_html( $active_orders ); ?></span>
                    <span class="label"><?php esc_html_e( 'Active', 'evg-platform' ); ?></span>
                </div>
                <div class="evg-stat-cell">
                    <span class="number" style="color: #34c759;">&pound;<?php echo esc_html( number_format( $total_spent, 0 ) ); ?></span>
                    <span class="label"><?php esc_html_e( 'Spent', 'evg-platform' ); ?></span>
                </div>
            </div>

            <div class="evg-info-pane">
                <div class="evg-pane-title"><?php esc_html_e( 'Financial Telemetry', 'evg-platform' ); ?></div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Average Order Value', 'evg-platform' ); ?></span>
                    <span class="val" style="color: #34c759;">&pound;<?php echo esc_html( number_format( $avg_order_value, 2 ) ); ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Paid Submissions', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo esc_html( $paid_orders ); ?> Orders</span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Total Units Graded', 'evg-platform' ); ?></span>
                    <span class="val" style="color: #d4af37;"><?php echo esc_html( $total_cards ); ?> Cards</span>
                </div>

                <div class="evg-pane-title"><?php esc_html_e( 'Shipping Verification', 'evg-platform' ); ?></div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Contact Phone', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $mobile ) ? esc_html( $mobile ) : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Premises / Line 1', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $house_number ) ? esc_html( trim( $house_number . ' ' . $street ) ) : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Town / City', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $city ) ? esc_html( $city ) : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'County', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $county ) ? esc_html( $county ) : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Postal Code', 'evg-platform' ); ?></span>
                    <span class="val" style="color: #d4af37; font-family: monospace; font-size: 13px;"><?php echo ! empty( $postcode ) ? esc_html( $postcode ) : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Jurisdiction', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $country ) ? esc_html( $country ) : 'United Kingdom'; ?></span>
                </div>

                <div class="evg-pane-title"><?php esc_html_e( 'Account & Consents', 'evg-platform' ); ?></div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Legal Name', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo esc_html( trim( $first_name . ' ' . $last_name ) ? : '—' ); ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Username Handle', 'evg-platform' ); ?></span>
                    <span class="val">@<?php echo esc_html( $user_info->user_login ); ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Assigned Role', 'evg-platform' ); ?></span>
                    <span class="val" style="color: #d4af37;"><?php echo esc_html( $user_roles ); ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Website URL', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ! empty( $website ) ? '<a href="' . esc_url( $website ) . '" target="_blank" style="color:#d4af37;">Visit Link</a>' : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Age & Parent Consent', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ( 'yes' === $is_over_18 || '1' === $is_over_18 ) ? '<span style="color:#34c759;">✓ Confirmed Over 18</span>' : '—'; ?></span>
                </div>
                <div class="evg-info-row">
                    <span class="key"><?php esc_html_e( 'Offers & Promotions', 'evg-platform' ); ?></span>
                    <span class="val"><?php echo ( 'yes' === $opt_in_offers || '1' === $opt_in_offers ) ? '<span style="color:#34c759;">✓ Subscribed</span>' : '<span style="color:#8e8e93;">Opted Out</span>'; ?></span>
                </div>
            </div>
        </div>

        <!-- Main Ledger View -->
        <div class="evg-card">
            <div style="padding: 22px 24px; border-bottom: 1px solid #1f1f22; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0; font-size: 15px; font-weight: 700; color: #ffffff; text-transform: uppercase; letter-spacing: 0.5px;">
                    <?php esc_html_e( 'Submission Ledger', 'evg-platform' ); ?>
                </h3>
                <span class="evg-pill-count"><?php printf( esc_html__( '%d Entries', 'evg-platform' ), count( $orders ) ); ?></span>
            </div>

            <div style="padding: 0; overflow-x: auto;">
                <table class="evg-pro-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order Ref', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Created', 'evg-platform' ); ?></th>
                            <th style="text-align: center;"><?php esc_html_e( 'Cards', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Stage', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Billed', 'evg-platform' ); ?></th>
                            <th style="text-align: right;"><?php esc_html_e( 'Inspection', 'evg-platform' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $orders ) ) : ?>
                            <?php foreach ( $orders as $order ) : 
                                $is_done = in_array( $order->current_stage, array( 'Completed', 'Returned To Customer' ), true );
                            ?>
                                <tr>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 700; color: #d4af37;">
                                            #<?php echo esc_html( $order->order_number ); ?>
                                        </span>
                                    </td>
                                    <td style="color: #8e8e93; font-size: 12px;">
                                        <?php echo esc_html( date_i18n( 'M j, Y', strtotime( $order->submission_date ) ) ); ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span style="font-weight: 700;"><?php echo esc_html( $order->total_cards ); ?></span>
                                    </td>
                                    <td>
                                        <span class="evg-stage-badge <?php echo $is_done ? 'done' : ''; ?>">
                                            <?php echo esc_html( $order->current_stage ); ?>
                                        </span>
                                    </td>
                                    <td style="font-weight: 700;">
                                        &pound;<?php echo esc_html( number_format( $order->total_amount, 2 ) ); ?>
                                    </td>
                                    <td style="text-align: right;">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $order->id ) ); ?>" class="evg-btn-view" style="padding: 5px 12px;">
                                            <?php esc_html_e( 'Open', 'evg-platform' ); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #8e8e93; padding: 40px;">
                                    <?php esc_html_e( 'No historic submissions cataloged for this account.', 'evg-platform' ); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}