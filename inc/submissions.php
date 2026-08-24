<?php
/**
 * EVG Module: Submissions & Orders (Pro Edition)
 * Comprehensive order lifecycle manager, declared card ledger, 10-stage pipeline routing,
 * inline card entry, customer address verification, and tax invoice generation.
 * Pagination configured to 5 items per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_submissions_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team', 'head_grader' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Submissions & Orders.', 'evg-platform' ) . '</p></div>';
        return;
    }

    // Ensure WordPress Media Uploader Scripts are Loaded
    if ( function_exists( 'wp_enqueue_media' ) ) {
        wp_enqueue_media();
    }

    global $wpdb;

    $table_submissions = $wpdb->prefix . 'evg_submissions';
    $table_cards       = $wpdb->prefix . 'evg_cards';

    // ---------------------------------------------------------
    // 1. Handle Form Submissions (Updating Order Lifecycle & Metadata)
    // ---------------------------------------------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_update_submission_nonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_POST['evg_update_submission_nonce'] ), 'evg_update_submission' ) ) {
            
            $sub_id         = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
            $new_stage      = isset( $_POST['current_stage'] ) ? sanitize_text_field( wp_unslash( $_POST['current_stage'] ) ) : '';
            $payment_status = isset( $_POST['payment_status'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_status'] ) ) : '';
            $tracking       = isset( $_POST['return_tracking'] ) ? sanitize_text_field( wp_unslash( $_POST['return_tracking'] ) ) : '';

            $wpdb->update(
                $table_submissions,
                array(
                    'current_stage'   => $new_stage,
                    'payment_status'  => $payment_status,
                    'return_tracking' => $tracking,
                ),
                array( 'id' => $sub_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            // Sync all underlying card statuses if order marked completed or returned
            if ( in_array( $new_stage, array( 'Completed', 'Returned To Customer' ), true ) ) {
                $wpdb->update(
                    $table_cards,
                    array( 'grading_status' => 'Completed' ),
                    array( 'submission_id' => $sub_id ),
                    array( '%s' ),
                    array( '%d' )
                );
            }

            if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                Elite_Vault_Grading_System::log_activity( "Updated Order ID {$sub_id} to stage: {$new_stage} (Payment: {$payment_status})" );
            }

            $redirect_url = admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub_id . '&updated=1' );
            if ( ! headers_sent() ) {
                wp_safe_redirect( $redirect_url );
                exit;
            } else {
                echo '<script>window.location.href = "' . esc_url( $redirect_url ) . '";</script>';
                exit;
            }
        }
    }

    // ---------------------------------------------------------
    // 2. Handle Adding New Card to Existing Submission
    // ---------------------------------------------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_add_card_nonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_POST['evg_add_card_nonce'] ), 'evg_add_card_to_submission' ) ) {
            $sub_id          = isset( $_POST['submission_id'] ) ? absint( $_POST['submission_id'] ) : 0;
            $card_name       = isset( $_POST['card_name'] ) ? sanitize_text_field( wp_unslash( $_POST['card_name'] ) ) : '';
            $set_name        = isset( $_POST['set_name'] ) ? sanitize_text_field( wp_unslash( $_POST['set_name'] ) ) : '';
            $card_number     = isset( $_POST['card_number'] ) ? sanitize_text_field( wp_unslash( $_POST['card_number'] ) ) : '';
            $language        = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : 'English';
            $final_grade     = ( isset( $_POST['final_grade'] ) && '' !== $_POST['final_grade'] ) ? absint( $_POST['final_grade'] ) : null;
            $front_image_url = isset( $_POST['front_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['front_image_url'] ) ) : '';
            $back_image_url  = isset( $_POST['back_image_url'] ) ? esc_url_raw( wp_unslash( $_POST['back_image_url'] ) ) : '';
            $grading_status  = isset( $_POST['grading_status'] ) ? sanitize_text_field( wp_unslash( $_POST['grading_status'] ) ) : 'Pending';

            if ( ! empty( $card_name ) && ! empty( $set_name ) && $sub_id > 0 ) {
                $wpdb->insert(
                    $table_cards,
                    array(
                        'submission_id'   => $sub_id,
                        'card_name'       => $card_name,
                        'set_name'        => $set_name,
                        'card_number'     => $card_number,
                        'language'        => $language,
                        'final_grade'     => $final_grade,
                        'front_image_url' => $front_image_url,
                        'back_image_url'  => $back_image_url,
                        'grading_status'  => $grading_status,
                    )
                );

                // Update total cards count on parent submission
                $card_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$table_cards} WHERE submission_id = %d", $sub_id ) );
                $wpdb->update(
                    $table_submissions,
                    array( 'total_cards' => $card_count ),
                    array( 'id' => $sub_id ),
                    array( '%d' ),
                    array( '%d' )
                );

                if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                    Elite_Vault_Grading_System::log_activity( "Added new card unit ({$card_name}) to Order ID {$sub_id}." );
                }

                $redirect_url = admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub_id . '&card_added=1' );
                if ( ! headers_sent() ) {
                    wp_safe_redirect( $redirect_url );
                    exit;
                } else {
                    echo '<script>window.location.href = "' . esc_url( $redirect_url ) . '";</script>';
                    exit;
                }
            }
        }
    }

    // ---------------------------------------------------------
    // 3. Notifications Display
    // ---------------------------------------------------------
    if ( isset( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Submission order parameters successfully updated.', 'evg-platform' ) . '</p></div>';
    }
    if ( isset( $_GET['card_added'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'New card unit added to submission successfully.', 'evg-platform' ) . '</p></div>';
    }

    $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
    $sub_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

    if ( 'view' === $action && $sub_id > 0 ) {
        evg_render_pro_single_submission( $sub_id, $table_submissions, $table_cards );
    } else {
        evg_render_pro_submissions_list( $table_submissions );
    }
}

/**
 * Pro View: Submissions Queue & Orders Directory
 */
function evg_render_pro_submissions_list( $table_submissions ) {
    global $wpdb;

    $submissions = $wpdb->get_results( "
        SELECT s.*, u.user_email, u.display_name 
        FROM {$table_submissions} s
        LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
        ORDER BY s.id DESC
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

        .evg-sub-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
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

        .evg-btn-manage {
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
        .evg-btn-manage:hover {
            border-color: var(--evg-gold);
            color: var(--evg-gold);
            background: rgba(212, 175, 55, 0.1);
            transform: translateY(-1px);
        }

        /* DataTables Custom UI */
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

    <div class="evg-sub-header">
        <div>
            <h1><?php esc_html_e( 'Submissions', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Audit collector submission records, update pipeline progress, and review declared card metadata.', 'evg-platform' ); ?></p>
        </div>
        <div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
            <?php printf( esc_html__( '%d Submissions Logged', 'evg-platform' ), count( $submissions ) ); ?>
        </div>
    </div>

    <div class="evg-panel-shell">
        <table class="evg-sub-table evg-datatable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order Ref', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Collector Account', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Submission Date', 'evg-platform' ); ?></th>
                    <th style="text-align: center;"><?php esc_html_e( 'Units', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Service / Tier', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Pipeline Stage', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Payment', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Actions', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $submissions ) ) : ?>
                    <?php foreach ( $submissions as $sub ) : 
                        $is_done   = in_array( $sub->current_stage, array( 'Completed', 'Returned To Customer' ), true );
                        $pay_class = 'evg-pay-' . ( strpos( $sub->payment_status, 'Partial' ) !== false ? 'Partial' : $sub->payment_status );
                        $timestamp = strtotime( $sub->submission_date );
                    ?>
                        <tr>
                            <td>
                                <span style="font-family: monospace; font-weight: 700; color: var(--evg-gold);">
                                    #<?php echo esc_html( $sub->order_number ); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: #ffffff;"><?php echo esc_html( $sub->display_name ? $sub->display_name : 'Guest Collector' ); ?></strong><br>
                                <span style="color: var(--evg-text-muted); font-size: 12px;"><?php echo esc_html( $sub->user_email ); ?></span>
                            </td>
                            <td data-order="<?php echo esc_attr( $timestamp ); ?>" style="color: var(--evg-text-muted); font-size: 12px;">
                                <?php echo esc_html( date_i18n( 'M j, Y', $timestamp ) ); ?>
                            </td>
                            <td style="text-align: center;">
                                <span style="background: #18181a; border: 1px solid #2c2c2e; color: #ffffff; padding: 4px 10px; border-radius: 20px; font-weight: 700; font-size: 12px;">
                                    <?php echo esc_html( $sub->total_cards ); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: #ffffff;"><?php echo esc_html( $sub->service_type ); ?></span><br>
                                <span class="evg-tag-label"><?php echo esc_html( $sub->label_option ); ?></span>
                            </td>
                            <td>
                                <span class="evg-stage-pill <?php echo $is_done ? 'done' : 'active'; ?>">
                                    <?php echo esc_html( $sub->current_stage ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="evg-pay-badge <?php echo esc_attr( $pay_class ); ?>">
                                    ● <?php echo esc_html( $sub->payment_status ); ?>
                                </span><br>
                                <span style="font-weight: 700; color: #ffffff; font-size: 12px;">&pound;<?php echo esc_html( number_format( $sub->total_amount, 2 ) ); ?></span>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub->id ) ); ?>" class="evg-btn-manage">
                                    <?php esc_html_e( 'Manage →', 'evg-platform' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
                            <?php esc_html_e( 'No customer card submissions recorded in database.', 'evg-platform' ); ?>
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
                    "order": [[ 2, "desc" ]],
                    "language": {
                        "search": "Search Submissions:",
                        "lengthMenu": "Display _MENU_ submissions per page",
                        "info": "Showing _START_ to _END_ of _TOTAL_ submissions",
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
 * Pro View: Single Order Lifecycle & Card Ledger
 */
function evg_render_pro_single_submission( $sub_id, $table_submissions, $table_cards ) {
    global $wpdb;

    $submission = $wpdb->get_row( $wpdb->prepare( "
        SELECT s.*, u.user_email, u.display_name 
        FROM {$table_submissions} s
        LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
        WHERE s.id = %d
    ", $sub_id ) );

    if ( ! $submission ) {
        echo '<p style="color:#ff453a;">' . esc_html__( 'Submission record not found.', 'evg-platform' ) . '</p>';
        return;
    }

    $cards = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_cards} WHERE submission_id = %d ORDER BY id ASC", $sub_id ) );

    // Fetch customer UK address meta
    $customer_id    = $submission->customer_id;
    $house_number   = $customer_id ? get_user_meta( $customer_id, 'evg_house_number', true ) : '';
    $street_address = $customer_id ? get_user_meta( $customer_id, 'evg_street_address', true ) : '';
    $town_city      = $customer_id ? get_user_meta( $customer_id, 'evg_town_city', true ) : '';
    $county         = $customer_id ? get_user_meta( $customer_id, 'evg_county', true ) : '';
    $postcode       = $customer_id ? get_user_meta( $customer_id, 'evg_postcode', true ) : '';
    $mobile_number  = $customer_id ? get_user_meta( $customer_id, 'evg_mobile_number', true ) : '';

    $stages = Elite_Vault_Grading_System::get_order_stages();

    $current_stage_idx = array_search( $submission->current_stage, array_keys( $stages ), true );
    if ( false === $current_stage_idx ) {
        $current_stage_idx = 0;
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

        /* 10-Stage Pipeline Progression Bar */
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

        /* Card Item Roster */
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

        .evg-thumb-wrap {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }
        .evg-thumb-card {
            width: 52px;
            height: 72px;
            border-radius: 6px;
            border: 1px solid #2a2a2e;
            overflow: hidden;
            background: #0a0a0a;
            position: relative;
        }
        .evg-thumb-card img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.2s ease;
        }
        .evg-thumb-card:hover img {
            transform: scale(1.08);
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
            margin-top: 6px;
        }

        /* Order Parameters Form */
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
        .evg-field-control {
            width: 100%;
            background: #141416;
            border: 1px solid #28282b;
            color: #ffffff;
            padding: 10px 12px;
            font-size: 13px;
            border-radius: 8px;
            outline: none;
            box-sizing: border-box;
        }
        .evg-field-control:focus {
            border-color: #d4af37;
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

        /* In-Line Card Creation */
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
                ← <?php esc_html_e( 'Back to Submissions', 'evg-platform' ); ?>
            </a>
            <h1><?php printf( esc_html__( 'Submission Ref #%s', 'evg-platform' ), esc_html( $submission->order_number ) ); ?></h1>
            <p><?php printf( esc_html__( 'Declared by %s on %s', 'evg-platform' ), esc_html( $submission->display_name ? $submission->display_name : 'Guest' ), esc_html( date_i18n( 'F j, Y', strtotime( $submission->submission_date ) ) ) ); ?></p>
        </div>
    </div>

    <!-- 10-Stage Pipeline Progression Bar -->
    <div class="evg-pipeline-strip">
        <span style="font-size: 11px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.5px;">
            <?php esc_html_e( 'Pipeline Status:', 'evg-platform' ); ?> <strong style="color: #d4af37;"><?php echo esc_html( $submission->current_stage ); ?></strong>
        </span>
        <div class="evg-steps-bar">
            <?php 
            $i = 0;
            foreach ( $stages as $stg_key => $stg_label ) :
                $chip_class = 'evg-step-pending';
                if ( $i < $current_stage_idx ) {
                    $chip_class = 'evg-step-done';
                } elseif ( $i === $current_stage_idx ) {
                    $chip_class = 'evg-step-active';
                }
            ?>
                <span class="evg-step-chip <?php echo esc_attr( $chip_class ); ?>">
                    <?php echo esc_html( sprintf( '%02d. %s', $i + 1, $stg_label ) ); ?> <?php echo ( $i < $current_stage_idx ) ? '✓' : ''; ?>
                </span>
            <?php 
                $i++;
            endforeach; 
            ?>
        </div>
    </div>

    <div class="evg-sub-grid">
        <!-- Main Column: Declared Cards & Inline Add Form -->
        <div>
            <div class="evg-box-panel">
                <div class="evg-box-panel-header">
                    <h2><?php esc_html_e( 'Declared Cards Manifest', 'evg-platform' ); ?></h2>
                    <span style="font-size: 12px; color: #8e8e93; font-weight: 700; font-family: monospace;">
                        <?php printf( esc_html__( '%d Units', 'evg-platform' ), count( $cards ) ); ?>
                    </span>
                </div>

                <div class="evg-roster-list">
                    <?php if ( ! empty( $cards ) ) : ?>
                        <?php foreach ( $cards as $index => $card ) : ?>
                            <div class="evg-roster-item">
                                <div class="evg-roster-meta">
                                    <h4><?php echo esc_html( ( $index + 1 ) . '. ' . $card->card_name ); ?></h4>
                                    <p>
                                        <strong><?php esc_html_e( 'Set:', 'evg-platform' ); ?></strong> <?php echo esc_html( $card->set_name ); ?> &nbsp;|&nbsp; 
                                        <strong><?php esc_html_e( 'No:', 'evg-platform' ); ?></strong> #<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?> &nbsp;|&nbsp; 
                                        <strong><?php esc_html_e( 'Lang:', 'evg-platform' ); ?></strong> <?php echo esc_html( $card->language ); ?> &nbsp;|&nbsp;
                                        <strong><?php esc_html_e( 'Condition:', 'evg-platform' ); ?></strong> <?php echo esc_html( $card->estimated_condition ? $card->estimated_condition : 'Raw' ); ?>
                                    </p>

                                    <?php if ( ! empty( $card->customer_notes ) ) : ?>
                                        <p style="color: #d4af37; margin-top: 6px; font-style: italic;">
                                            "<?php echo esc_html( $card->customer_notes ); ?>"
                                        </p>
                                    <?php endif; ?>

                                    <!-- Uploaded Front/Back Thumbnails -->
                                    <?php if ( ! empty( $card->front_image_url ) || ! empty( $card->back_image_url ) ) : ?>
                                        <div class="evg-thumb-wrap">
                                            <?php if ( ! empty( $card->front_image_url ) ) : ?>
                                                <a href="<?php echo esc_url( $card->front_image_url ); ?>" target="_blank" class="evg-thumb-card" title="<?php esc_attr_e( 'View Declared Front', 'evg-platform' ); ?>">
                                                    <img src="<?php echo esc_url( $card->front_image_url ); ?>" alt="Front Photo">
                                                </a>
                                            <?php endif; ?>
                                            <?php if ( ! empty( $card->back_image_url ) ) : ?>
                                                <a href="<?php echo esc_url( $card->back_image_url ); ?>" target="_blank" class="evg-thumb-card" title="<?php esc_attr_e( 'View Declared Back', 'evg-platform' ); ?>">
                                                    <img src="<?php echo esc_url( $card->back_image_url ); ?>" alt="Back Photo">
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div style="text-align: right; display: flex; flex-direction: column; align-items: flex-end;">
                                    <span style="background: #1c1c1f; border: 1px solid #2c2c30; color: #8e8e93; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
                                        <?php echo esc_html( $card->grading_status ); ?>
                                    </span>
                                    <?php if ( ! empty( $card->final_grade ) ) : ?>
                                        <div class="evg-grade-token" title="<?php esc_attr_e( 'Certified EVG Grade', 'evg-platform' ); ?>">
                                            <?php echo esc_html( $card->final_grade ); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <p style="text-align: center; color: #8e8e93; padding: 30px 0;">
                            <?php esc_html_e( 'No declared cards found for this submission.', 'evg-platform' ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <!-- In-Line Add Card Form -->
                <div class="evg-add-card-shell">
                    <h3 style="color: #ffffff; font-size: 13px; font-weight: 700; text-transform: uppercase; margin: 0 0 14px 0;">
                        + <?php esc_html_e( 'Direct Add Card', 'evg-platform' ); ?>
                    </h3>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $submission->id ) ); ?>">
                        <?php wp_nonce_field( 'evg_add_card_to_submission', 'evg_add_card_nonce' ); ?>
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->id ); ?>">

                        <div class="evg-inline-grid">
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Card Name *', 'evg-platform' ); ?></label>
                                <input type="text" name="card_name" class="evg-field-control" placeholder="e.g. Charizard VMAX" required>
                            </div>
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Set Name *', 'evg-platform' ); ?></label>
                                <input type="text" name="set_name" class="evg-field-control" placeholder="e.g. Shining Fates" required>
                            </div>
                        </div>

                        <div class="evg-inline-grid">
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Card Number', 'evg-platform' ); ?></label>
                                <input type="text" name="card_number" class="evg-field-control" placeholder="e.g. 074/072">
                            </div>
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Language', 'evg-platform' ); ?></label>
                                <select name="language" class="evg-field-control">
                                    <option value="English"><?php esc_html_e( 'English (ENG)', 'evg-platform' ); ?></option>
                                    <option value="Japanese"><?php esc_html_e( 'Japanese (JPN)', 'evg-platform' ); ?></option>
                                    <option value="Other"><?php esc_html_e( 'Other', 'evg-platform' ); ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="evg-inline-grid">
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Grade (Optional)', 'evg-platform' ); ?></label>
                                <select name="final_grade" class="evg-field-control">
                                    <option value="" selected><?php esc_html_e( 'Pending Assessment', 'evg-platform' ); ?></option>
                                    <?php for ( $g = 10; $g >= 1; $g-- ) : ?>
                                        <option value="<?php echo esc_attr( $g ); ?>">EVG <?php echo esc_html( $g ); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; color: #d4af37; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-bottom: 4px;"><?php esc_html_e( 'Front Artwork URL', 'evg-platform' ); ?></label>
                                <div style="display: flex; gap: 6px;">
                                    <input type="text" name="front_image_url" id="inline_front_url" class="evg-field-control" placeholder="https://...">
                                    <button type="button" class="button" style="background:#222; border-color:#444; color:#fff;" onclick="evg_select_inline_media()">📷</button>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="evg-btn-save" style="padding: 10px; font-size: 12px;">
                            + <?php esc_html_e( 'Add Card', 'evg-platform' ); ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sidebar: Order Parameters Control Panel & Logistics -->
        <div>
            <div class="evg-box-panel">
                <div class="evg-box-panel-header">
                    <h2><?php esc_html_e( 'Parameters', 'evg-platform' ); ?></h2>
                </div>
                <div class="evg-form-box">
                    
                    <!-- Collector Details -->
                    <div style="background: #141416; border: 1px solid #222224; border-radius: 8px; padding: 14px; margin-bottom: 16px;">
                        <span style="font-size: 10px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.8px; display: block; margin-bottom: 4px;">
                            <?php esc_html_e( 'Collector', 'evg-platform' ); ?>
                        </span>
                        <strong style="color: #ffffff; font-size: 14px; display: block;"><?php echo esc_html( $submission->display_name ? $submission->display_name : 'Guest' ); ?></strong>
                        <span style="color: #8e8e93; font-size: 12px;"><?php echo esc_html( $submission->user_email ); ?></span>
                        <?php if ( ! empty( $mobile_number ) ) : ?>
                            <br><span style="color: var(--evg-gold); font-size: 11px; font-family: monospace;"><?php echo esc_html( $mobile_number ); ?></span>
                        <?php endif; ?>
                    </div>

                    <!-- UK Shipping Coordinates -->
                    <?php if ( ! empty( $street_address ) || ! empty( $postcode ) ) : ?>
                        <div style="background: #141416; border: 1px solid #222224; border-radius: 8px; padding: 14px; margin-bottom: 20px; font-size: 12px; line-height: 1.4;">
                            <span style="font-size: 10px; text-transform: uppercase; color: #8e8e93; font-weight: 700; letter-spacing: 0.8px; display: block; margin-bottom: 4px;">
                                <?php esc_html_e( 'Destination Address', 'evg-platform' ); ?>
                            </span>
                            <span style="color: #ffffff;"><?php echo esc_html( trim( $house_number . ' ' . $street_address ) ); ?></span><br>
                            <span style="color: #8e8e93;"><?php echo esc_html( $town_city . ', ' . $county ); ?></span><br>
                            <strong style="color: var(--evg-gold); font-family: monospace;"><?php echo esc_html( $postcode ); ?>, UK</strong>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $submission->id ) ); ?>">
                        <?php wp_nonce_field( 'evg_update_submission', 'evg_update_submission_nonce' ); ?>
                        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $submission->id ); ?>">

                        <div class="evg-field-group">
                            <label><?php esc_html_e( 'Stage', 'evg-platform' ); ?></label>
                            <select name="current_stage" class="evg-field-control">
                                <?php foreach ( $stages as $stg_key => $stg_label ) : ?>
                                    <option value="<?php echo esc_attr( $stg_key ); ?>" <?php selected( $submission->current_stage, $stg_key ); ?>>
                                        <?php echo esc_html( $stg_label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="evg-field-group">
                            <label><?php esc_html_e( 'Payment', 'evg-platform' ); ?></label>
                            <select name="payment_status" class="evg-field-control">
                                <option value="Pending" <?php selected( $submission->payment_status, 'Pending' ); ?>><?php esc_html_e( 'Pending', 'evg-platform' ); ?></option>
                                <option value="Paid" <?php selected( $submission->payment_status, 'Paid' ); ?>><?php esc_html_e( 'Paid', 'evg-platform' ); ?></option>
                                <option value="Partial Refund" <?php selected( $submission->payment_status, 'Partial Refund' ); ?>><?php esc_html_e( 'Partial Refund', 'evg-platform' ); ?></option>
                                <option value="Refunded" <?php selected( $submission->payment_status, 'Refunded' ); ?>><?php esc_html_e( 'Refunded', 'evg-platform' ); ?></option>
                            </select>
                        </div>

                        <div class="evg-field-group">
                            <label><?php esc_html_e( 'Tracking', 'evg-platform' ); ?></label>
                            <input type="text" name="return_tracking" class="evg-field-control" placeholder="<?php esc_attr_e( 'e.g. Royal Mail Special Delivery #', 'evg-platform' ); ?>" value="<?php echo esc_attr( $submission->return_tracking ); ?>">
                        </div>

                        <button type="submit" class="evg-btn-save">
                            <?php esc_html_e( 'Update', 'evg-platform' ); ?>
                        </button>
                    </form>

                    <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=evg_download_invoice&submission_id=' . $submission->id ) ); ?>" target="_blank" class="evg-btn-invoice">
                        📄 <?php esc_html_e( 'Invoice', 'evg-platform' ); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function evg_select_inline_media() {
            if (typeof wp !== 'undefined' && wp.media) {
                var custom_uploader = wp.media({
                    title: 'Select Front Card Artwork',
                    button: { text: 'Use this photo' },
                    multiple: false
                }).on('select', function() {
                    var attachment = custom_uploader.state().get('selection').first().toJSON();
                    jQuery('#inline_front_url').val(attachment.url);
                }).open();
            } else {
                alert('WordPress Media Library is not available. Please paste image URL directly.');
            }
        }
    </script>
    <?php
}