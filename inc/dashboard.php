<?php
/**
 * EVG Module: Dashboard
 * Displays operational analytics, queue counters, active submissions ledger, and role-based quick actions.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Render function for the main Dashboard view
 */
function evg_dashboard_tab() {
    global $wpdb;

    $current_user = wp_get_current_user();
    $first_name   = ! empty( $current_user->user_firstname ) ? $current_user->user_firstname : $current_user->display_name;

    // Database Tables
    $table_submissions = $wpdb->prefix . 'evg_submissions';
    $table_cards       = $wpdb->prefix . 'evg_cards';
    $table_marketplace = $wpdb->prefix . 'evg_marketplace';
    $table_feedback    = $wpdb->prefix . 'evg_feedback';

    // Live Queue Metrics
    $pending_orders  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_submissions} WHERE current_stage NOT IN ('Completed', 'Returned To Customer')" );
    $pending_grading = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_cards} WHERE grading_status IN ('Pending', 'Under Review', 'Grading In Progress')" );
    $qc_queue_count  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_cards} WHERE grading_status = 'Quality Control'" );
    $active_listings = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_marketplace} WHERE status = 'Available'" );
    $unread_feedback = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_feedback} WHERE status = 'Pending'" );

    // Recent Submissions with Customer Details
    $recent_submissions = $wpdb->get_results( "
        SELECT s.id, s.order_number, s.submission_date, s.total_cards, s.total_amount, s.current_stage, s.label_option, u.display_name, u.user_email
        FROM {$table_submissions} s
        LEFT JOIN {$wpdb->users} u ON s.customer_id = u.ID
        ORDER BY s.submission_date DESC
        LIMIT 6
    " );
    ?>
    <style>
        .evg-dash-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #ffffff; }
        .evg-dash-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 28px; flex-wrap: wrap; gap: 15px; }
        .evg-dash-header h1 { font-size: 26px; font-weight: 800; margin: 0 0 6px 0; color: #ffffff; letter-spacing: -0.5px; }
        .evg-dash-header p { color: #8e8e93; font-size: 13px; margin: 0; }
        .evg-gold-text { color: #d4af37; }
        .evg-dash-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px; margin-bottom: 30px; }
        .evg-stat-box { background: #0f0f0f; border: 1px solid #222222; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.4); transition: transform 0.2s ease, border-color 0.2s ease; text-decoration: none; }
        .evg-stat-box:hover { transform: translateY(-2px); border-color: #d4af37; }
        .evg-stat-icon-wrap { width: 44px; height: 44px; border-radius: 8px; background: rgba(212, 175, 55, 0.08); border: 1px solid rgba(212, 175, 55, 0.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .evg-stat-icon-wrap svg { width: 20px; height: 20px; fill: #d4af37; }
        .evg-stat-content h3 { font-size: 22px; font-weight: 800; color: #ffffff; margin: 0 0 2px 0; line-height: 1; }
        .evg-stat-content p { font-size: 11px; color: #8e8e93; margin: 0; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 700; }
        .evg-split-layout { display: grid; grid-template-columns: 2.2fr 1fr; gap: 24px; align-items: start; }
        .evg-card-panel { background: #0f0f0f; border: 1px solid #222222; border-radius: 14px; overflow: hidden; box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5); }
        .evg-card-panel-header { padding: 20px 24px; border-bottom: 1px solid #1f1f22; display: flex; justify-content: space-between; align-items: center; background: #141416; }
        .evg-card-panel-header h2 { margin: 0; font-size: 14px; font-weight: 700; color: #ffffff; text-transform: uppercase; letter-spacing: 0.8px; }
        .evg-link-action { color: #d4af37; text-decoration: none; font-size: 12px; font-weight: 700; transition: color 0.2s ease; }
        .evg-link-action:hover { color: #ffffff; }
        .evg-dash-table { width: 100%; border-collapse: separate; border-spacing: 0; }
        .evg-dash-table thead th { background: #111113; color: #8e8e93; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.8px; padding: 14px 20px; border-bottom: 1px solid #1f1f22; text-align: left; }
        .evg-dash-table tbody td { padding: 16px 20px; border-bottom: 1px solid #1a1a1c; color: #ffffff; font-size: 13px; vertical-align: middle; }
        .evg-dash-table tbody tr:hover { background: rgba(212, 175, 55, 0.02); }
        .evg-dash-table tbody tr:last-child td { border-bottom: none; }
        .evg-badge-stage { display: inline-block; background: #18181a; border: 1px solid #2c2c2e; color: #d4af37; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
        .evg-actions-panel { display: flex; flex-direction: column; gap: 10px; padding: 20px; }
        .evg-quick-btn { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; background: #141416; border: 1px solid #222224; border-radius: 10px; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); }
        .evg-quick-btn:hover { border-color: #d4af37; color: #d4af37; background: rgba(212, 175, 55, 0.05); transform: translateX(3px); }
        .evg-btn-left { display: flex; align-items: center; gap: 12px; }
        @media (max-width: 1100px) { .evg-split-layout { grid-template-columns: 1fr; } }
    </style>

    <div class="evg-dash-container">
        <div class="evg-dash-header">
            <div>
                <h1><?php esc_html_e( 'Dashboard,', 'evg-platform' ); ?> <span class="evg-gold-text"><?php echo esc_html( $first_name ); ?></span></h1>
                <p><?php esc_html_e( 'Real-time metrics, queue telemetry, and incoming package management.', 'evg-platform' ); ?></p>
            </div>
            <div style="font-size: 12px; color: #8e8e93; font-family: monospace;">
                <?php echo esc_html( current_time( 'D, M j, Y | H:i' ) ); ?> UTC
            </div>
        </div>

        <div class="evg-dash-grid">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions' ) ); ?>" class="evg-stat-box">
                <div class="evg-stat-icon-wrap">
                    <svg viewBox="0 0 576 512"><path d="M0 64C0 28.7 28.7 0 64 0H224V128c0 17.7 14.3 32 32 32H384V288H216c-13.3 0-24 10.7-24 24s10.7 24 24 24H384V448c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V64zM384 336V288H494.1l-39-39c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l80 80c9.4 9.4 9.4 24.6 0 33.9l-80 80c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l39-39H384zm0-208H256V0L384 128z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3><?php echo esc_html( number_format_i18n( $pending_orders ) ); ?></h3>
                    <p><?php esc_html_e( 'Submissions', 'evg-platform' ); ?></p>
                </div>
            </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_grading-desk' ) ); ?>" class="evg-stat-box">
                <div class="evg-stat-icon-wrap">
                    <svg viewBox="0 0 512 512"><path d="M152.1 38.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 115.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 128H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32zM152.1 198.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 275.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 288H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32zM152.1 358.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 435.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 448H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3><?php echo esc_html( number_format_i18n( $pending_grading ) ); ?></h3>
                    <p><?php esc_html_e( 'Grading', 'evg-platform' ); ?></p>
                </div>
            </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>" class="evg-stat-box">
                <div class="evg-stat-icon-wrap" style="background: rgba(52, 199, 89, 0.08); border-color: rgba(52, 199, 89, 0.2);">
                    <svg style="fill: #34c759;" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3 style="color: #34c759;"><?php echo esc_html( number_format_i18n( $qc_queue_count ) ); ?></h3>
                    <p><?php esc_html_e( 'QC', 'evg-platform' ); ?></p>
                </div>
            </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace' ) ); ?>" class="evg-stat-box">
                <div class="evg-stat-icon-wrap">
                    <svg viewBox="0 0 576 512"><path d="M0 24C0 10.7 10.7 0 24 0H69.5c22 0 41.5 12.8 50.6 32h411c26.3 0 45.5 25 38.6 50.4l-41 152.3c-8.5 31.4-37 53.3-69.5 53.3H170.7l5.4 28.5c2.2 11.3 12.1 19.5 23.6 19.5H488c13.3 0 24 10.7 24 24s-10.7 24-24 24H199.7c-34.6 0-64.3-24.6-70.7-58.5L77.4 54.5c-.7-3.8-4-6.5-7.9-6.5H24C10.7 48 0 37.3 0 24zM128 464a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zm336-48a48 48 0 1 1 0 96 48 48 0 1 1 0-96z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3><?php echo esc_html( number_format_i18n( $active_listings ) ); ?></h3>
                    <p><?php esc_html_e( 'Marketplace', 'evg-platform' ); ?></p>
                </div>
            </a>

            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_feedback' ) ); ?>" class="evg-stat-box">
                <div class="evg-stat-icon-wrap" style="<?php echo ( $unread_feedback > 0 ) ? 'background: rgba(255, 69, 58, 0.08); border-color: rgba(255, 69, 58, 0.2);' : ''; ?>">
                    <svg style="<?php echo ( $unread_feedback > 0 ) ? 'fill: #ff453a;' : ''; ?>" viewBox="0 0 512 512"><path d="M160 368c26.5 0 48 21.5 48 48v16l72.5-54.4c8.3-6.2 18.4-9.6 28.8-9.6H448c8.8 0 16-7.2 16-16V64c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16V352c0 8.8 7.2 16 16 16h96zm48 124l-.2 .2-5.1 3.8-17.1 12.8c-4.8 3.6-11.3 4.2-16.8 1.5s-8.8-8.2-8.8-14.3V474.7v-4.5V416H160c-53 0-96-43-96-96V64C64 11 107-32 160-32H448c53 0 96 43 96 96V352c0 53-43 96-96 96H309.3L208 504z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3 style="<?php echo ( $unread_feedback > 0 ) ? 'color: #ff453a;' : ''; ?>"><?php echo esc_html( number_format_i18n( $unread_feedback ) ); ?></h3>
                    <p><?php esc_html_e( 'Feedback', 'evg-platform' ); ?></p>
                </div>
            </a>
        </div>

        <div class="evg-split-layout">
            <div class="evg-card-panel">
                <div class="evg-card-panel-header">
                    <h2><?php esc_html_e( 'Submissions', 'evg-platform' ); ?></h2>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions' ) ); ?>" class="evg-link-action">
                        <?php esc_html_e( 'View All →', 'evg-platform' ); ?>
                    </a>
                </div>
                <div style="overflow-x: auto;">
                    <table class="evg-dash-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Order', 'evg-platform' ); ?></th>
                                <th><?php esc_html_e( 'Customer', 'evg-platform' ); ?></th>
                                <th><?php esc_html_e( 'Units', 'evg-platform' ); ?></th>
                                <th><?php esc_html_e( 'Stage', 'evg-platform' ); ?></th>
                                <th style="text-align: right;"><?php esc_html_e( 'Total', 'evg-platform' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $recent_submissions ) ) : ?>
                                <?php foreach ( $recent_submissions as $sub ) : ?>
                                    <tr>
                                        <td>
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions&action=view&id=' . $sub->id ) ); ?>" style="font-family: monospace; font-weight: 700; color: #d4af37; text-decoration: none;">
                                                #<?php echo esc_html( $sub->order_number ); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span style="font-weight: 600;"><?php echo esc_html( $sub->display_name ? $sub->display_name : 'Guest' ); ?></span><br>
                                            <span style="font-size: 11px; color: #8e8e93;"><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $sub->submission_date ) ) ); ?></span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700;"><?php echo esc_html( $sub->total_cards ); ?></span>
                                        </td>
                                        <td>
                                            <span class="evg-badge-stage">
                                                <?php echo esc_html( $sub->current_stage ); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right; font-weight: 700; color: #ffffff;">
                                            &pound;<?php echo esc_html( number_format( $sub->total_amount, 2 ) ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: #8e8e93; padding: 30px;">
                                        <?php esc_html_e( 'No active card submissions found in database.', 'evg-platform' ); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="evg-card-panel">
                <div class="evg-card-panel-header">
                    <h2><?php esc_html_e( 'Shortcuts', 'evg-platform' ); ?></h2>
                </div>
                <div class="evg-actions-panel">
                    <?php if ( current_user_can( 'manage_options' ) || current_user_can( 'head_grader' ) || current_user_can( 'grader' ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_grading-desk' ) ); ?>" class="evg-quick-btn">
                            <div class="evg-btn-left">
                                <span style="font-size: 16px;">🔬</span>
                                <span><?php esc_html_e( 'Grading', 'evg-platform' ); ?></span>
                            </div>
                            <span style="color: #8e8e93;">→</span>
                        </a>
                    <?php endif; ?>

                    <?php if ( current_user_can( 'manage_options' ) || current_user_can( 'head_grader' ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>" class="evg-quick-btn">
                            <div class="evg-btn-left">
                                <span style="font-size: 16px;">🛡️</span>
                                <span><?php esc_html_e( 'QC', 'evg-platform' ); ?></span>
                            </div>
                            <span style="color: #8e8e93;">→</span>
                        </a>
                    <?php endif; ?>

                    <?php if ( current_user_can( 'manage_options' ) || current_user_can( 'support_team' ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_submissions' ) ); ?>" class="evg-quick-btn">
                            <div class="evg-btn-left">
                                <span style="font-size: 16px;">📦</span>
                                <span><?php esc_html_e( 'Submissions', 'evg-platform' ); ?></span>
                            </div>
                            <span style="color: #8e8e93;">→</span>
                        </a>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_marketplace&action=add' ) ); ?>" class="evg-quick-btn">
                            <div class="evg-btn-left">
                                <span style="font-size: 16px;">🏷️</span>
                                <span><?php esc_html_e( 'Marketplace', 'evg-platform' ); ?></span>
                            </div>
                            <span style="color: #8e8e93;">→</span>
                        </a>
                    <?php endif; ?>

                    <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_settings' ) ); ?>" class="evg-quick-btn">
                            <div class="evg-btn-left">
                                <span style="font-size: 16px;">⚙️</span>
                                <span><?php esc_html_e( 'Settings', 'evg-platform' ); ?></span>
                            </div>
                            <span style="color: #8e8e93;">→</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}