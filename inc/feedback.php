<?php
/**
 * EVG Module: Customer Feedback
 * Manages customer reviews, suggestions, marketing permissions, and featured testimonials.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_feedback_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'support_team' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to view Customer Feedback.', 'evg-platform' ) . '</p></div>';
        return;
    }

    global $wpdb;
    $table_feedback = $wpdb->prefix . 'evg_feedback';

    // ---------------------------------------------------------
    // Handle Form Submissions (Update Feedback Status)
    // ---------------------------------------------------------
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['evg_feedback_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['evg_feedback_nonce'], 'evg_update_feedback' ) ) {
            
            $feedback_id = intval( $_POST['feedback_id'] );
            $status      = sanitize_text_field( $_POST['status'] );

            $wpdb->update(
                $table_feedback,
                array( 'status' => $status ),
                array( 'id' => $feedback_id ),
                array( '%s' ),
                array( '%d' )
            );

            Elite_Vault_Grading_System::log_activity( "Updated Feedback ID {$feedback_id} status to: {$status}" );
            
            echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Feedback status updated successfully.', 'evg-platform' ) . '</p></div>';
        }
    }

    // ---------------------------------------------------------
    // Handle Deletion (Remove Feedback)
    // ---------------------------------------------------------
    if ( isset( $_GET['delete_feedback'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( wp_verify_nonce( $_GET['_wpnonce'], 'evg_delete_feedback_' . intval( $_GET['delete_feedback'] ) ) ) {
            $del_id = intval( $_GET['delete_feedback'] );
            $wpdb->delete( $table_feedback, array( 'id' => $del_id ), array( '%d' ) );
            Elite_Vault_Grading_System::log_activity( "Deleted Feedback ID {$del_id}." );
            echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Feedback entry permanently removed.', 'evg-platform' ) . '</p></div>';
        }
    }

    // ---------------------------------------------------------
    // Fetch Aggregate Metrics
    // ---------------------------------------------------------
    $total_feedback = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_feedback}" );
    $pending_count  = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_feedback} WHERE status = 'Pending'" );
    $featured_count = (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table_feedback} WHERE status = 'Featured Testimonial'" );

    // ---------------------------------------------------------
    // Fetch All Feedback Records
    // ---------------------------------------------------------
    $feedbacks = $wpdb->get_results( "SELECT * FROM {$table_feedback} ORDER BY submitted_at DESC" );
    
    // Helper function for rendering stars
    if ( ! function_exists( 'evg_render_pro_stars' ) ) {
        function evg_render_pro_stars( $rating ) {
            $html = '<div style="display:inline-flex; gap:2px; font-size:14px; line-height:1;">';
            for ( $i = 1; $i <= 5; $i++ ) {
                $html .= ( $i <= $rating ) 
                    ? '<span style="color:#d4af37;">★</span>' 
                    : '<span style="color:#2c2c2e;">★</span>';
            }
            $html .= '</div>';
            return $html;
        }
    }
    ?>

    <style>
        .evg-feedback-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #ffffff;
        }

        .evg-feedback-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .evg-feedback-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .evg-feedback-header p {
            color: #8e8e93;
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
            background: rgba(212, 175, 55, 0.08);
            border: 1px solid rgba(212, 175, 55, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .evg-stat-icon-wrap svg {
            width: 22px;
            height: 22px;
            fill: #d4af37;
        }
        .evg-stat-content h3 {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
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

        .evg-card-panel {
            background: #0f0f0f;
            border: 1px solid #222222;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
        }
        .evg-card-panel-header {
            padding: 20px 24px;
            border-bottom: 1px solid #1f1f22;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #141416;
        }
        .evg-card-panel-header h2 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        .evg-fb-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .evg-fb-table thead th {
            background: #111113;
            color: #8e8e93;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            padding: 14px 20px;
            border-bottom: 1px solid #1f1f22;
            text-align: left;
        }
        .evg-fb-table tbody td {
            padding: 18px 20px;
            border-bottom: 1px solid #1a1a1c;
            color: #ffffff;
            font-size: 13px;
            vertical-align: top;
        }
        .evg-fb-table tbody tr:hover {
            background: rgba(212, 175, 55, 0.02);
        }
        .evg-fb-table tbody tr:last-child td {
            border-bottom: none;
        }

        .evg-message-box {
            background: #141416;
            border: 1px solid #222224;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 13px;
            line-height: 1.5;
            color: #e5e5ea;
            max-height: 120px;
            overflow-y: auto;
        }

        .evg-permission-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 8px;
        }

        .evg-status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }
        .evg-status-Pending {
            background: rgba(255, 69, 58, 0.08);
            color: #ff453a;
            border: 1px solid rgba(255, 69, 58, 0.25);
        }
        .evg-status-Reviewed {
            background: rgba(10, 132, 255, 0.08);
            color: #0a84ff;
            border: 1px solid rgba(10, 132, 255, 0.25);
        }
        .evg-status-Responded {
            background: rgba(52, 199, 89, 0.08);
            color: #34c759;
            border: 1px solid rgba(52, 199, 89, 0.25);
        }
        .evg-status-Featured {
            background: rgba(212, 175, 55, 0.08);
            color: #d4af37;
            border: 1px solid rgba(212, 175, 55, 0.25);
        }

        .evg-select-control {
            background: #141416;
            border: 1px solid #28282b;
            color: #ffffff;
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 6px;
            width: 100%;
            margin-bottom: 8px;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .evg-select-control:focus {
            border-color: #d4af37;
        }

        .evg-btn-update {
            background: rgba(212, 175, 55, 0.1);
            border: 1px solid rgba(212, 175, 55, 0.3);
            color: #d4af37;
            padding: 6px 12px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            cursor: pointer;
            flex: 1;
            transition: all 0.2s ease;
        }
        .evg-btn-update:hover {
            background: #d4af37;
            color: #0a0a0a;
        }

        .evg-btn-del {
            background: rgba(255, 69, 58, 0.1);
            border: 1px solid rgba(255, 69, 58, 0.3);
            color: #ff453a;
            padding: 6px 10px;
            font-size: 11px;
            font-weight: 700;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        .evg-btn-del:hover {
            background: #ff453a;
            color: #ffffff;
        }
    </style>

    <div class="evg-feedback-container">
        <div class="evg-feedback-header">
            <div>
                <h1><?php esc_html_e( 'Customer Reviews & Suggestions', 'evg-platform' ); ?></h1>
                <p><?php esc_html_e( 'Review collector experiences, respond to queries, and curate featured public testimonials.', 'evg-platform' ); ?></p>
            </div>
            <div style="font-size: 12px; color: #8e8e93; font-family: monospace;">
                <?php printf( esc_html__( '%d Total Entries', 'evg-platform' ), $total_feedback ); ?>
            </div>
        </div>

        <div class="evg-stat-grid">
            <div class="evg-stat-box">
                <div class="evg-stat-icon-wrap">
                    <svg viewBox="0 0 512 512"><path d="M160 368c26.5 0 48 21.5 48 48v16l72.5-54.4c8.3-6.2 18.4-9.6 28.8-9.6H448c8.8 0 16-7.2 16-16V64c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16V352c0 8.8 7.2 16 16 16h96zm48 124l-.2 .2-5.1 3.8-17.1 12.8c-4.8 3.6-11.3 4.2-16.8 1.5s-8.8-8.2-8.8-14.3V474.7v-4.5V416H160c-53 0-96-43-96-96V64C64 11 107-32 160-32H448c53 0 96 43 96 96V352c0 53-43 96-96 96H309.3L208 504z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3><?php echo esc_html( number_format_i18n( $total_feedback ) ); ?></h3>
                    <p><?php esc_html_e( 'Total Submissions', 'evg-platform' ); ?></p>
                </div>
            </div>

            <div class="evg-stat-box">
                <div class="evg-stat-icon-wrap" style="background: rgba(255, 69, 58, 0.08); border-color: rgba(255, 69, 58, 0.2);">
                    <svg style="fill: #ff453a;" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3 style="color:#ff453a;"><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></h3>
                    <p><?php esc_html_e( 'Unreviewed / Pending', 'evg-platform' ); ?></p>
                </div>
            </div>

            <div class="evg-stat-box">
                <div class="evg-stat-icon-wrap">
                    <svg viewBox="0 0 576 512"><path d="M316.9 18C311.6 7 300.4 0 288.1 0s-23.4 7-28.8 18L195 150.3 51.4 171.5c-12 1.8-22 10.2-25.7 21.7s-.7 24.2 7.9 32.7L137.8 329 113.2 474.7c-2 12 3 24.2 12.9 31.3s23 8 33.8 2.3l128.3-68.5 128.3 68.5c10.8 5.7 23.9 4.9 33.8-2.3s14.9-19.3 12.9-31.3L438.5 329 542.7 225.9c8.6-8.5 11.7-21.2 7.9-32.7s-13.7-19.9-25.7-21.7L381.2 150.3 316.9 18z"/></svg>
                </div>
                <div class="evg-stat-content">
                    <h3 style="color:#d4af37;"><?php echo esc_html( number_format_i18n( $featured_count ) ); ?></h3>
                    <p><?php esc_html_e( 'Featured Testimonials', 'evg-platform' ); ?></p>
                </div>
            </div>
        </div>

        <div class="evg-card-panel">
            <div class="evg-card-panel-header">
                <h2><?php esc_html_e( 'Feedback & Suggestions Ledger', 'evg-platform' ); ?></h2>
            </div>
            <div style="overflow-x: auto;">
                <table class="evg-fb-table evg-datatable">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Submission Date', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Collector Details', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Category & Rating', 'evg-platform' ); ?></th>
                            <th style="min-width: 280px; max-width: 420px;"><?php esc_html_e( 'Message & Recommendation', 'evg-platform' ); ?></th>
                            <th><?php esc_html_e( 'Workflow Status', 'evg-platform' ); ?></th>
                            <th style="min-width: 150px; text-align: right;"><?php esc_html_e( 'Status Control', 'evg-platform' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( ! empty( $feedbacks ) ) : ?>
                            <?php foreach ( $feedbacks as $fb ) : 
                                $badge_class = 'evg-status-' . ( $fb->status === 'Featured Testimonial' ? 'Featured' : $fb->status );
                                $timestamp   = strtotime( $fb->submitted_at );
                            ?>
                                <tr>
                                    <td data-order="<?php echo esc_attr( $timestamp ); ?>" style="color: #8e8e93; font-size: 12px; white-space: nowrap;">
                                        <?php echo esc_html( date_i18n( 'M j, Y', $timestamp ) ); ?><br>
                                        <span style="font-size: 11px; color: #636366;"><?php echo esc_html( date_i18n( 'H:i', $timestamp ) ); ?> UTC</span>
                                    </td>
                                    <td>
                                        <strong style="color: #ffffff; font-size: 14px;"><?php echo esc_html( $fb->customer_name ? $fb->customer_name : 'Anonymous Collector' ); ?></strong><br>
                                        <span style="color: #8e8e93; font-size: 12px;"><?php echo esc_html( $fb->email_address ); ?></span>
                                        <?php if ( ! empty( $fb->order_number ) ) : ?>
                                            <br><span style="font-family: monospace; color: #d4af37; font-size: 11px; font-weight: 700;">Ref: #<?php echo esc_html( $fb->order_number ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div style="margin-bottom: 6px;"><?php echo evg_render_pro_stars( intval( $fb->rating ) ); ?></div>
                                        <span style="font-size: 11px; font-weight: 700; color: #ffffff; text-transform: uppercase; letter-spacing: 0.5px; background: #1c1c1e; padding: 3px 8px; border-radius: 4px; border: 1px solid #2c2c2e;">
                                            <?php echo esc_html( $fb->feedback_type ); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="evg-message-box">
                                            <?php echo nl2br( esc_html( $fb->feedback_text ) ); ?>
                                        </div>
                                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 6px;">
                                            <div class="evg-permission-tag">
                                                <?php if ( $fb->permission_to_use ) : ?>
                                                    <span style="color:#34c759;">✓ <?php esc_html_e( 'Marketing Consent Granted', 'evg-platform' ); ?></span>
                                                <?php else : ?>
                                                    <span style="color:#8e8e93;">✕ <?php esc_html_e( 'Private Submission Only', 'evg-platform' ); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if ( ! empty( $fb->recommend ) ) : ?>
                                                <span style="font-size: 11px; color: #8e8e93;">
                                                    Recommends: <strong style="color: #ffffff;"><?php echo esc_html( $fb->recommend ); ?></strong>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="evg-status-badge <?php echo esc_attr( $badge_class ); ?>">
                                            <?php echo esc_html( $fb->status ); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: right;">
                                        <form method="post" style="margin: 0; display: inline-block; width: 100%;">
                                            <?php wp_nonce_field( 'evg_update_feedback', 'evg_feedback_nonce' ); ?>
                                            <input type="hidden" name="feedback_id" value="<?php echo esc_attr( $fb->id ); ?>">
                                            <select name="status" class="evg-select-control">
                                                <option value="Pending" <?php selected( $fb->status, 'Pending' ); ?>><?php esc_html_e('Pending', 'evg-platform'); ?></option>
                                                <option value="Reviewed" <?php selected( $fb->status, 'Reviewed' ); ?>><?php esc_html_e('Reviewed', 'evg-platform'); ?></option>
                                                <option value="Responded" <?php selected( $fb->status, 'Responded' ); ?>><?php esc_html_e('Responded', 'evg-platform'); ?></option>
                                                <?php if ( $fb->permission_to_use ) : ?>
                                                    <option value="Featured Testimonial" <?php selected( $fb->status, 'Featured Testimonial' ); ?>><?php esc_html_e('Featured Testimonial', 'evg-platform'); ?></option>
                                                <?php endif; ?>
                                            </select>
                                            <div style="display: flex; gap: 6px;">
                                                <button type="submit" class="evg-btn-update">
                                                    <?php esc_html_e('Save', 'evg-platform'); ?>
                                                </button>
                                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=evg_tab_feedback&delete_feedback=' . $fb->id ), 'evg_delete_feedback_' . $fb->id ) ); ?>" class="evg-btn-del" title="<?php esc_attr_e( 'Delete entry', 'evg-platform' ); ?>" onclick="return confirm('<?php esc_attr_e('Permanently purge this feedback record?', 'evg-platform'); ?>');">
                                                    ✕
                                                </a>
                                            </div>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #8e8e93; padding: 40px;">
                                    <?php esc_html_e( 'No customer feedback or suggestions cataloged in database.', 'evg-platform' ); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.evg-datatable').DataTable({
                "pageLength": 15,
                "order": [[ 0, "desc" ]],
                "language": { "search": "Search Feedback:" }
            });
        });
    </script>
    <?php
}