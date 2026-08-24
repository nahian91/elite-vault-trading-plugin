<?php
/**
 * EVG Module: Public Marketplace (Pro Edition)
 * Manages inventory, retail pricing, stock quantity, slab info, and card listings for the public directory.
 * Standard pagination configured to 5 items per page.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_marketplace_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to manage the Marketplace.', 'evg-platform' ) . '</p></div>';
        return;
    }

    // Ensure WordPress Media Uploader Scripts are Loaded
    if ( function_exists( 'wp_enqueue_media' ) ) {
        wp_enqueue_media();
    }

    global $wpdb;

    $table_marketplace = $wpdb->prefix . 'evg_marketplace';
    $table_cards       = $wpdb->prefix . 'evg_cards';

    // ---------------------------------------------------------
    // 1. Handle Form Submissions (Create / Edit Listing)
    // ---------------------------------------------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_save_listing_nonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_POST['evg_save_listing_nonce'] ), 'evg_save_listing' ) ) {
            
            $listing_id       = isset( $_POST['listing_id'] ) ? absint( $_POST['listing_id'] ) : 0;
            $card_id          = ( ! empty( $_POST['card_id'] ) && absint( $_POST['card_id'] ) > 0 ) ? absint( $_POST['card_id'] ) : null;
            $card_title       = sanitize_text_field( wp_unslash( $_POST['card_title'] ?? '' ) );
            $set_name         = sanitize_text_field( wp_unslash( $_POST['set_name'] ?? '' ) );
            $card_number      = sanitize_text_field( wp_unslash( $_POST['card_number'] ?? '' ) );
            $language         = sanitize_text_field( wp_unslash( $_POST['language'] ?? 'English' ) );
            $grading_company  = sanitize_text_field( wp_unslash( $_POST['grading_company'] ?? 'Elite Vault Grading' ) );
            $slab_information = sanitize_text_field( wp_unslash( $_POST['slab_information'] ?? '' ) );
            $assigned_grade   = ( isset( $_POST['assigned_grade'] ) && '' !== $_POST['assigned_grade'] ) ? absint( $_POST['assigned_grade'] ) : null;
            $image_url        = esc_url_raw( wp_unslash( $_POST['image_url'] ?? '' ) );
            $price            = floatval( $_POST['price'] ?? 0 );
            $stock_quantity   = max( 0, intval( $_POST['stock_quantity'] ?? 0 ) );
            $category         = sanitize_text_field( wp_unslash( $_POST['category'] ?? 'Elite Vault Graded Cards' ) );
            $status           = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'Available' ) );

            // Automatically set Sold status if stock reaches 0
            if ( 0 === $stock_quantity && 'Available' === $status ) {
                $status = 'Sold';
            }

            // Check if card_id is already listed elsewhere to avoid duplicate entry collisions
            if ( 0 === $listing_id && ! empty( $card_id ) ) {
                $existing_listing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table_marketplace} WHERE card_id = %d LIMIT 1", $card_id ) );
                if ( $existing_listing ) {
                    $listing_id = absint( $existing_listing );
                }
            }

            $data = array(
                'card_id'          => $card_id,
                'card_title'       => $card_title,
                'set_name'         => $set_name,
                'card_number'      => $card_number,
                'language'         => $language,
                'grading_company'  => $grading_company,
                'slab_information' => $slab_information,
                'assigned_grade'   => $assigned_grade,
                'image_url'        => $image_url,
                'price'            => $price,
                'stock_quantity'   => $stock_quantity,
                'category'         => $category,
                'status'           => $status,
            );

            if ( $listing_id > 0 ) {
                $wpdb->update(
                    $table_marketplace,
                    $data,
                    array( 'id' => $listing_id )
                );
                if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                    Elite_Vault_Grading_System::log_activity( "Updated Marketplace Listing #{$listing_id} ({$card_title})." );
                }
                
                $redirect_url = admin_url( 'admin.php?page=evg_tab_marketplace&updated=1' );
                if ( ! headers_sent() ) {
                    wp_safe_redirect( $redirect_url );
                    exit;
                } else {
                    echo '<script>window.location.href = "' . esc_url( $redirect_url ) . '";</script>';
                    exit;
                }
            } else {
                $data['listed_date'] = current_time( 'mysql' );
                $wpdb->insert( $table_marketplace, $data );
                $new_id = $wpdb->insert_id;
                if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                    Elite_Vault_Grading_System::log_activity( "Created Marketplace Listing #{$new_id} ({$card_title})." );
                }
                
                $redirect_url = admin_url( 'admin.php?page=evg_tab_marketplace&created=1' );
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
    // 2. Handle Quick Status Toggles (Sold / Available)
    // ---------------------------------------------------------
    if ( isset( $_GET['toggle_status'] ) && isset( $_GET['listing_id'] ) && isset( $_GET['_wpnonce'] ) ) {
        $listing_id = absint( $_GET['listing_id'] );
        if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'evg_toggle_status_' . $listing_id ) ) {
            $new_status = sanitize_text_field( wp_unslash( $_GET['toggle_status'] ) );
            $wpdb->update(
                $table_marketplace,
                array( 'status' => $new_status ),
                array( 'id' => $listing_id ),
                array( '%s' ),
                array( '%d' )
            );
            if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                Elite_Vault_Grading_System::log_activity( "Toggled Marketplace Listing #{$listing_id} status to {$new_status}." );
            }
            
            $redirect_url = admin_url( 'admin.php?page=evg_tab_marketplace&status_updated=1' );
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
    // 3. Handle Deletion (Purge Listing)
    // ---------------------------------------------------------
    if ( isset( $_GET['delete_listing'] ) && isset( $_GET['_wpnonce'] ) ) {
        $del_id = absint( $_GET['delete_listing'] );
        if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'evg_delete_listing_' . $del_id ) ) {
            $wpdb->delete( $table_marketplace, array( 'id' => $del_id ), array( '%d' ) );
            if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                Elite_Vault_Grading_System::log_activity( "Removed Marketplace Listing ID {$del_id}." );
            }
            
            $redirect_url = admin_url( 'admin.php?page=evg_tab_marketplace&deleted=1' );
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
    // 4. Notifications Display
    // ---------------------------------------------------------
    if ( isset( $_GET['created'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Card published successfully.', 'evg-platform' ) . '</p></div>';
    }
    if ( isset( $_GET['updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Listing updated successfully.', 'evg-platform' ) . '</p></div>';
    }
    if ( isset( $_GET['status_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Status updated.', 'evg-platform' ) . '</p></div>';
    }
    if ( isset( $_GET['deleted'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Listing removed.', 'evg-platform' ) . '</p></div>';
    }

    $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

    if ( 'add' === $action || 'edit' === $action ) {
        $listing_id = isset( $_GET['listing_id'] ) ? absint( $_GET['listing_id'] ) : 0;
        evg_render_pro_marketplace_form( $listing_id, $table_marketplace, $table_cards );
    } else {
        evg_render_pro_marketplace_list( $table_marketplace );
    }
}

/**
 * Pro View: Public Marketplace Directory Table
 */
function evg_render_pro_marketplace_list( $table_marketplace ) {
    global $wpdb;

    $listings = $wpdb->get_results( "
        SELECT * FROM {$table_marketplace} 
        ORDER BY id DESC
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

        /* Custom DataTables Pagination Styling (Theme Obsidian/Gold) */
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
            <h1><?php esc_html_e( 'Marketplace', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Manage certified inventory, valuations, stock levels, and store listings.', 'evg-platform' ); ?></p>
        </div>
        <div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace&action=add' ) ); ?>" class="evg-btn-add">
                + <?php esc_html_e( 'Add Listing', 'evg-platform' ); ?>
            </a>
        </div>
    </div>

    <div class="evg-panel-shell">
        <table class="evg-mkt-table evg-datatable">
            <thead>
                <tr>
                    <th style="width: 50px;"><?php esc_html_e( 'Visual', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Card & Slab', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Category', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Stock', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Price (£)', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Controls', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $listings ) ) : ?>
                    <?php foreach ( $listings as $item ) : 
                        $status_class  = 'evg-status-' . esc_attr( $item->status );
                        $display_title = $item->card_title ? $item->card_title : 'Graded Card';
                    ?>
                        <tr>
                            <td>
                                <div class="evg-card-thumb-cell">
                                    <?php if ( ! empty( $item->image_url ) ) : ?>
                                        <img src="<?php echo esc_url( $item->image_url ); ?>" alt="Card">
                                    <?php else : ?>
                                        <span style="font-size: 10px; color: var(--evg-text-muted);">RAW</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <strong style="color: #ffffff; font-size: 14px;"><?php echo esc_html( $display_title ); ?></strong>
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
                                <strong style="color: <?php echo intval( $item->stock_quantity ) > 0 ? '#34c759' : '#ff453a'; ?>;">
                                    <?php echo esc_html( $item->stock_quantity ); ?>
                                </strong>
                            </td>
                            <td>
                                <span style="font-weight: 800; font-size: 15px; color: var(--evg-gold);">
                                    &pound;<?php echo esc_html( number_format( $item->price, 2 ) ); ?>
                                </span>
                            </td>
                            <td>
                                <span class="evg-status-dot <?php echo esc_attr( $status_class ); ?>">
                                    ● <?php echo esc_html( $item->status ); ?>
                                </span>
                            </td>
                            <td style="text-align: right; white-space: nowrap;">
                                <?php if ( 'Available' === $item->status ) : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&toggle_status=Sold&listing_id=' . $item->id ), 'evg_toggle_status_' . $item->id ) ); ?>" class="evg-btn-quick-toggle" title="<?php esc_attr_e( 'Mark as Sold', 'evg-platform' ); ?>">
                                        <?php esc_html_e( 'Sold', 'evg-platform' ); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&toggle_status=Available&listing_id=' . $item->id ), 'evg_toggle_status_' . $item->id ) ); ?>" class="evg-btn-quick-toggle" title="<?php esc_attr_e( 'Mark Available', 'evg-platform' ); ?>">
                                        <?php esc_html_e( 'Available', 'evg-platform' ); ?>
                                    </a>
                                <?php endif; ?>

                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace&action=edit&listing_id=' . $item->id ) ); ?>" class="evg-btn-action-edit">
                                    <?php esc_html_e( 'Edit', 'evg-platform' ); ?>
                                </a>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_marketplace&delete_listing=' . $item->id ), 'evg_delete_listing_' . $item->id ) ); ?>" class="evg-btn-action-del" onclick="return confirm('<?php esc_attr_e('Permanently remove this card from the marketplace?', 'evg-platform'); ?>');">
                                    ✕
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
                            <?php esc_html_e( 'No marketplace listings found.', 'evg-platform' ); ?>
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
                    "order": [[ 4, "desc" ]],
                    "language": {
                        "search": "Search:",
                        "lengthMenu": "Display _MENU_ per page",
                        "info": "Showing _START_ to _END_ of _TOTAL_ listings",
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
 * Pro View: Publish / Edit Marketplace Listing Form
 */
function evg_render_pro_marketplace_form( $listing_id, $table_marketplace, $table_cards ) {
    global $wpdb;

    $listing = null;
    $available_graded_cards = $wpdb->get_results( "
        SELECT c.*, s.order_number 
        FROM {$table_cards} c
        JOIN {$wpdb->prefix}evg_submissions s ON c.submission_id = s.id
        WHERE c.grading_status IN ('Completed', 'Quality Control')
        ORDER BY c.id DESC LIMIT 150
    " );

    $categories = array(
        'Elite Vault Graded Cards',
        'Ungraded Cards',
        'Featured Cards',
        'New Arrivals',
        'High Value Cards',
        'Collections/Bundles'
    );

    if ( $listing_id > 0 ) {
        $listing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_marketplace} WHERE id = %d", $listing_id ) );
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
            background: #141416;
            border: 1px solid #28282b;
            color: #ffffff;
            padding: 12px 14px;
            font-size: 13px;
            border-radius: 8px;
            outline: none;
            box-sizing: border-box;
        }
        .evg-control-field:focus {
            border-color: #d4af37;
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
                ← <?php esc_html_e( 'Back', 'evg-platform' ); ?>
            </a>
            <h1><?php echo $listing ? esc_html__( 'Edit Listing', 'evg-platform' ) : esc_html__( 'Add Listing', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Configure inventory parameters, pricing, and stock status.', 'evg-platform' ); ?></p>
        </div>
    </div>

    <div class="evg-form-container">
        <div class="evg-form-header">
            <h2><?php esc_html_e( 'Parameters', 'evg-platform' ); ?></h2>
        </div>
        <div class="evg-form-body">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace' ) ); ?>">
                <?php wp_nonce_field( 'evg_save_listing', 'evg_save_listing_nonce' ); ?>
                
                <?php if ( $listing ) : ?>
                    <input type="hidden" name="listing_id" value="<?php echo esc_attr( $listing->id ); ?>">
                <?php endif; ?>

                <div class="evg-input-group">
                    <label><?php esc_html_e( 'Import Graded Card (Optional)', 'evg-platform' ); ?></label>
                    <select id="evg_graded_card_picker" name="card_id" class="evg-control-field">
                        <option value=""><?php esc_html_e( '— Custom / Standalone —', 'evg-platform' ); ?></option>
                        <?php foreach ( $available_graded_cards as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->id ); ?>" 
                                    data-title="<?php echo esc_attr( $c->card_name ); ?>"
                                    data-set="<?php echo esc_attr( $c->set_name ); ?>"
                                    data-number="<?php echo esc_attr( $c->card_number ); ?>"
                                    data-lang="<?php echo esc_attr( $c->language ); ?>"
                                    data-grade="<?php echo esc_attr( $c->final_grade ); ?>"
                                    data-image="<?php echo esc_attr( $c->front_image_url ); ?>"
                                    <?php if ( $listing ) selected( $listing->card_id, $c->id ); ?>>
                                <?php echo esc_html( $c->card_name ); ?> — <?php echo esc_html( $c->set_name ); ?> #<?php echo esc_html( $c->card_number ); ?> (Grade: <?php echo esc_html( $c->final_grade ? $c->final_grade : 'Ungraded' ); ?>) [Order #<?php echo esc_html( $c->order_number ); ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="evg-input-group">
                    <label><?php esc_html_e( 'Title', 'evg-platform' ); ?></label>
                    <input type="text" id="mkt_card_title" name="card_title" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->card_title ) : ''; ?>" required placeholder="e.g. Charizard VMAX">
                </div>

                <div class="evg-input-grid-2">
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Set', 'evg-platform' ); ?></label>
                        <input type="text" id="mkt_set_name" name="set_name" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->set_name ) : ''; ?>" required placeholder="e.g. Shining Fates">
                    </div>
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Number', 'evg-platform' ); ?></label>
                        <input type="text" id="mkt_card_number" name="card_number" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->card_number ) : ''; ?>" placeholder="e.g. 074/072">
                    </div>
                </div>

                <div class="evg-input-grid-2">
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Language', 'evg-platform' ); ?></label>
                        <select id="mkt_language" name="language" class="evg-control-field">
                            <option value="English" <?php if ( $listing ) selected( $listing->language, 'English' ); ?>>English</option>
                            <option value="Japanese" <?php if ( $listing ) selected( $listing->language, 'Japanese' ); ?>>Japanese</option>
                            <option value="Other" <?php if ( $listing ) selected( $listing->language, 'Other' ); ?>>Other</option>
                        </select>
                    </div>
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Company', 'evg-platform' ); ?></label>
                        <input type="text" name="grading_company" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->grading_company ) : 'Elite Vault Grading'; ?>" required>
                    </div>
                </div>

                <div class="evg-input-grid-2">
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Grade (1-10)', 'evg-platform' ); ?></label>
                        <select id="mkt_assigned_grade" name="assigned_grade" class="evg-control-field">
                            <option value=""><?php esc_html_e( 'Raw / Ungraded', 'evg-platform' ); ?></option>
                            <?php for ( $g = 10; $g >= 1; $g-- ) : ?>
                                <option value="<?php echo esc_attr( $g ); ?>" <?php if ( $listing ) selected( $listing->assigned_grade, $g ); ?>>
                                    <?php echo esc_html( $g ); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Slab Style', 'evg-platform' ); ?></label>
                        <input type="text" name="slab_information" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->slab_information ) : 'Elite Vault Sealed Protective Slab'; ?>" placeholder="e.g. Standard Slab">
                    </div>
                </div>

                <div class="evg-input-group">
                    <label><?php esc_html_e( 'Image URL', 'evg-platform' ); ?></label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" id="mkt_image_url" name="image_url" class="evg-control-field" value="<?php echo $listing ? esc_attr( $listing->image_url ) : ''; ?>" placeholder="https://...">
                        <button type="button" id="evg_upload_mkt_btn" class="button" style="background:#222; border-color:#444; color:#fff; padding:0 16px;">
                            <?php esc_html_e( 'Upload', 'evg-platform' ); ?>
                        </button>
                    </div>
                </div>

                <div class="evg-input-grid-2">
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Price (£)', 'evg-platform' ); ?></label>
                        <input type="number" name="price" class="evg-control-field" min="0.00" step="0.01" value="<?php echo $listing ? esc_attr( $listing->price ) : ''; ?>" required placeholder="0.00">
                    </div>
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Stock', 'evg-platform' ); ?></label>
                        <input type="number" name="stock_quantity" class="evg-control-field" min="0" value="<?php echo $listing ? esc_attr( $listing->stock_quantity ) : '1'; ?>" required>
                    </div>
                </div>

                <div class="evg-input-grid-2">
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Category', 'evg-platform' ); ?></label>
                        <select name="category" class="evg-control-field" required>
                            <?php foreach ( $categories as $cat ) : ?>
                                <option value="<?php echo esc_attr( $cat ); ?>" <?php if ( $listing ) selected( $listing->category, $cat ); ?>>
                                    <?php echo esc_html( $cat ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="evg-input-group">
                        <label><?php esc_html_e( 'Status', 'evg-platform' ); ?></label>
                        <select name="status" class="evg-control-field" required>
                            <option value="Available" <?php if ( $listing ) selected( $listing->status, 'Available' ); ?>><?php esc_html_e( 'Available', 'evg-platform' ); ?></option>
                            <option value="Sold" <?php if ( $listing ) selected( $listing->status, 'Sold' ); ?>><?php esc_html_e( 'Sold', 'evg-platform' ); ?></option>
                            <option value="Hidden" <?php if ( $listing ) selected( $listing->status, 'Hidden' ); ?>><?php esc_html_e( 'Hidden', 'evg-platform' ); ?></option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="evg-btn-publish">
                    <?php echo $listing ? esc_html__( 'Update Listing', 'evg-platform' ) : esc_html__( 'Publish Listing', 'evg-platform' ); ?>
                </button>
            </form>
        </div>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Auto-fill fields when selecting a graded card from internal database
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

            // WP Media Uploader for Card Visual
            $('#evg_upload_mkt_btn').on('click', function(e) {
                e.preventDefault();
                if (typeof wp !== 'undefined' && wp.media) {
                    var custom_uploader = wp.media({
                        title: 'Select Card Image',
                        button: { text: 'Use Image' },
                        multiple: false
                    }).on('select', function() {
                        var attachment = custom_uploader.state().get('selection').first().toJSON();
                        $('#mkt_image_url').val(attachment.url);
                    }).open();
                } else {
                    alert('WordPress Media Library is not available. Please paste an image URL directly.');
                }
            });
        });
    </script>
    <?php
}