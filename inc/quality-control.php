<?php
/**
 * EVG Module: Quality Control (Pro Edition)
 * Step 5 of the Grading Process: Final verification, sub-score cross-check, defect gallery audit, and encapsulation approval.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_quality_control_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator', 'head_grader' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Quality Control.', 'evg-platform' ) . '</p></div>';
        return;
    }

    global $wpdb;

    $table_cards       = $wpdb->prefix . 'evg_cards';
    $table_submissions = $wpdb->prefix . 'evg_submissions';
    $table_assessments = $wpdb->prefix . 'evg_assessments';
    $table_faults      = $wpdb->prefix . 'evg_fault_images';

    // ---------------------------------------------------------
    // Handle Form Submissions (QC Approval & Reject Routing)
    // ---------------------------------------------------------
    if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['evg_qc_action_nonce'] ) ) {
        if ( wp_verify_nonce( $_POST['evg_qc_action_nonce'], 'evg_qc_action' ) ) {
            
            $card_id        = intval( $_POST['card_id'] );
            $submission_id  = intval( $_POST['submission_id'] );
            $qc_action      = sanitize_key( $_POST['qc_action'] );
            $publish_report = isset( $_POST['transparency_published'] ) ? 1 : 0;

            if ( 'approve' === $qc_action ) {
                $wpdb->update(
                    $table_cards,
                    array( 
                        'grading_status'         => 'Encapsulated',
                        'transparency_published' => $publish_report
                    ),
                    array( 'id' => $card_id ),
                    array( '%s', '%d' ),
                    array( '%d' )
                );

                // Auto-advance submission stage if all cards in batch are sealed
                $pending_cards = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) FROM {$table_cards} WHERE submission_id = %d AND grading_status != 'Encapsulated'", $submission_id ) );
                if ( 0 === $pending_cards ) {
                    $wpdb->update(
                        $table_submissions,
                        array( 'current_stage' => 'Encapsulation' ),
                        array( 'id' => $submission_id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }

                Elite_Vault_Grading_System::log_activity( "QC Approved for Card ID {$card_id}. Dispatched to Encapsulation." );
                echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Quality Control approved. Card routed to Encapsulation.', 'evg-platform' ) . '</p></div>';
            } elseif ( 'reject' === $qc_action ) {
                $wpdb->update(
                    $table_cards,
                    array( 'grading_status' => 'Grading In Progress' ),
                    array( 'id' => $card_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                $wpdb->update(
                    $table_submissions,
                    array( 'current_stage' => 'Grading In Progress' ),
                    array( 'id' => $submission_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                Elite_Vault_Grading_System::log_activity( "QC Rejected for Card ID {$card_id}. Returned to Grading Desk." );
                echo '<div class="notice notice-error is-dismissible" style="background:#141416; border-left:4px solid #ff453a; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'QC Rejected. Card returned to Grading Desk for re-assessment.', 'evg-platform' ) . '</p></div>';
            }
        }
    }

    $action  = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
    $card_id = isset( $_GET['card_id'] ) ? intval( $_GET['card_id'] ) : 0;

    if ( 'review' === $action && $card_id > 0 ) {
        evg_render_pro_qc_review_interface( $card_id, $table_cards, $table_submissions, $table_assessments, $table_faults );
    } else {
        evg_render_pro_qc_list( $table_cards, $table_submissions );
    }
}

/**
 * Pro View: Quality Control Queue
 */
function evg_render_pro_qc_list( $table_cards, $table_submissions ) {
    global $wpdb;

    $cards = $wpdb->get_results( "
        SELECT c.*, s.order_number, s.label_option, s.submission_date 
        FROM {$table_cards} c
        JOIN {$table_submissions} s ON c.submission_id = s.id
        WHERE c.grading_status = 'Quality Control'
        ORDER BY s.submission_date ASC
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

        .evg-qc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }
        .evg-qc-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .evg-qc-header p {
            color: var(--evg-text-muted);
            font-size: 13px;
            margin: 0;
        }

        .evg-panel-shell {
            background: var(--evg-bg-card);
            border: 1px solid var(--evg-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
        }

        .evg-qc-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        .evg-qc-table thead th {
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
        .evg-qc-table tbody td {
            padding: 18px 20px;
            border-bottom: 1px solid #1a1a1d;
            color: #ffffff;
            font-size: 13px;
            vertical-align: middle;
        }
        .evg-qc-table tbody tr {
            transition: background 0.2s ease;
        }
        .evg-qc-table tbody tr:hover {
            background: rgba(212, 175, 55, 0.02);
        }
        .evg-qc-table tbody tr:last-child td {
            border-bottom: none;
        }

        .evg-grade-pill {
            background: #d4af37;
            color: #0a0a0a;
            font-weight: 900;
            font-size: 14px;
            padding: 4px 12px;
            border-radius: 6px;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(212, 175, 55, 0.25);
        }

        .evg-btn-qc {
            background: var(--evg-gold);
            color: #0a0a0a;
            padding: 8px 18px;
            font-size: 12px;
            font-weight: 800;
            border-radius: 6px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .evg-btn-qc:hover {
            background: #f3e5ab;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25);
            color: #000000;
        }
    </style>

    <div class="evg-qc-header">
        <div>
            <h1><?php esc_html_e( 'Quality Control Queue', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Step 5 of the Grading Process. Verify card information, grade labels, and slab presentation before sealing.', 'evg-platform' ); ?></p>
        </div>
        <div style="font-size: 12px; color: var(--evg-text-muted); font-family: monospace;">
            <?php printf( esc_html__( '%d Units in QC Review', 'evg-platform' ), count( $cards ) ); ?>
        </div>
    </div>

    <div class="evg-panel-shell">
        <table class="evg-qc-table evg-datatable">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order Ref', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Card Identification', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Set / Number', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Assigned Grade', 'evg-platform' ); ?></th>
                    <th><?php esc_html_e( 'Slab Design', 'evg-platform' ); ?></th>
                    <th style="text-align: right;"><?php esc_html_e( 'Verification', 'evg-platform' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! empty( $cards ) ) : ?>
                    <?php foreach ( $cards as $card ) : ?>
                        <tr>
                            <td>
                                <span style="font-family: monospace; font-weight: 700; color: var(--evg-gold);">
                                    #<?php echo esc_html( $card->order_number ); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="font-size: 14px; color: #ffffff;"><?php echo esc_html( $card->card_name ); ?></strong><br>
                                <span style="color: var(--evg-text-muted); font-size: 12px;"><?php echo esc_html( $card->language ); ?></span>
                            </td>
                            <td>
                                <span style="color: #ffffff;"><?php echo esc_html( $card->set_name ); ?></span><br>
                                <span style="color: var(--evg-text-muted); font-size: 12px;">#<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?></span>
                            </td>
                            <td>
                                <span class="evg-grade-pill">EVG <?php echo esc_html( $card->final_grade ); ?></span>
                            </td>
                            <td>
                                <span style="background: #1c1c1f; border: 1px solid #2c2c30; color: var(--evg-gold); font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 4px;">
                                    <?php echo esc_html( $card->label_option ); ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control&action=review&card_id=' . $card->id ) ); ?>" class="evg-btn-qc">
                                    🛡️ <?php esc_html_e( 'Run QC Check', 'evg-platform' ); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--evg-text-muted); padding: 50px;">
                            <?php esc_html_e( 'No cards currently pending quality control review.', 'evg-platform' ); ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        jQuery(document).ready(function($) {
            $('.evg-datatable').DataTable({
                "pageLength": 15,
                "language": { "search": "Filter QC Queue:" }
            });
        });
    </script>
    <?php
}

/**
 * Pro View: Step 5 QC Verification Interface
 */
function evg_render_pro_qc_review_interface( $card_id, $table_cards, $table_submissions, $table_assessments, $table_faults ) {
    global $wpdb;

    $card = $wpdb->get_row( $wpdb->prepare( "SELECT c.*, s.order_number, s.label_option FROM {$table_cards} c JOIN {$table_submissions} s ON c.submission_id = s.id WHERE c.id = %d", $card_id ) );
    if ( ! $card ) {
        echo '<p style="color:#ff453a;">' . esc_html__( 'Card not found in database.', 'evg-platform' ) . '</p>';
        return;
    }

    $assessment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table_assessments} WHERE card_id = %d", $card_id ) );
    $fault_images = array();
    if ( $assessment ) {
        $fault_images = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table_faults} WHERE assessment_id = %d", $assessment->id ) );
    }

    $grader_name = 'Authorized Grader';
    if ( $assessment && $assessment->grader_id ) {
        $grader_info = get_userdata( $assessment->grader_id );
        if ( $grader_info ) {
            $grader_name = $grader_info->display_name;
        }
    }
    ?>
    <style>
        .evg-qc-split-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        .evg-qc-box {
            background: #0f0f11;
            border: 1px solid #222224;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
            margin-bottom: 24px;
        }
        .evg-qc-box-header {
            padding: 18px 20px;
            background: #141416;
            border-bottom: 1px solid #1f1f22;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .evg-qc-box-header h2 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .evg-qc-box-body {
            padding: 20px;
        }

        .evg-verify-table {
            width: 100%;
            border-collapse: collapse;
        }
        .evg-verify-table td {
            padding: 12px 0;
            border-bottom: 1px solid #1a1a1c;
            color: #ffffff;
            font-size: 13px;
        }
        .evg-verify-table td:first-child {
            color: #8e8e93;
            width: 40%;
        }

        .evg-qc-grade-badge {
            background: #d4af37;
            color: #0a0a0a;
            font-weight: 900;
            font-size: 22px;
            padding: 6px 18px;
            border-radius: 6px;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(212, 175, 55, 0.3);
        }

        .evg-subgrades-strip {
            background: #141416;
            border: 1px solid #222224;
            border-radius: 8px;
            padding: 14px;
            margin-top: 18px;
        }
        .evg-subgrades-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            text-align: center;
            margin-top: 8px;
        }
        .evg-subgrade-cell {
            background: #0a0a0a;
            border: 1px solid #2a2a2e;
            border-radius: 6px;
            padding: 8px 4px;
        }
        .evg-subgrade-cell span {
            display: block;
            font-size: 10px;
            color: #8e8e93;
            text-transform: uppercase;
            font-weight: 700;
        }
        .evg-subgrade-cell strong {
            font-size: 16px;
            color: #d4af37;
            font-weight: 800;
        }

        .evg-fault-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .evg-fault-card {
            background: #0a0a0a;
            border: 1px solid #2a2a2e;
            border-radius: 6px;
            overflow: hidden;
            text-align: center;
            padding: 6px;
        }
        .evg-fault-card img {
            width: 100%;
            height: 90px;
            object-fit: cover;
            border-radius: 4px;
        }
        .evg-fault-card span {
            display: block;
            font-size: 11px;
            color: #d4af37;
            font-weight: 700;
            margin-top: 4px;
            text-transform: capitalize;
        }

        .evg-checklist-group {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        .evg-check-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            background: #141416;
            border: 1px solid #222224;
            padding: 14px;
            border-radius: 8px;
            cursor: pointer;
            transition: border-color 0.2s ease;
        }
        .evg-check-item:hover {
            border-color: #333336;
        }
        .evg-check-item input[type="checkbox"] {
            margin-top: 3px;
            width: 18px;
            height: 18px;
            accent-color: #d4af37;
            cursor: pointer;
        }
        .evg-check-item span {
            font-size: 13px;
            color: #e5e5ea;
            line-height: 1.4;
        }

        .evg-btn-approve {
            width: 100%;
            background: #d4af37;
            color: #0a0a0a;
            border: none;
            padding: 15px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .evg-btn-approve:hover {
            background: #f3e5ab;
            box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
        }

        .evg-btn-reject {
            width: 100%;
            background: transparent;
            border: 1px solid #ff453a;
            color: #ff453a;
            padding: 12px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 10px;
            transition: all 0.2s ease;
        }
        .evg-btn-reject:hover {
            background: #ff453a;
            color: #ffffff;
        }

        @media (max-width: 992px) {
            .evg-qc-split-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="evg-qc-header">
        <div>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>" style="color: #d4af37; text-decoration: none; font-size: 13px; font-weight: 700; margin-bottom: 8px; display: inline-block;">
                ← <?php esc_html_e( 'Back to QC Queue', 'evg-platform' ); ?>
            </a>
            <h1><?php printf( esc_html__( 'Quality Control Verification: %s', 'evg-platform' ), esc_html( $card->card_name ) ); ?></h1>
            <p><?php printf( esc_html__( 'Order Ref #%s — Assessed by %s', 'evg-platform' ), esc_html( $card->order_number ), esc_html( $grader_name ) ); ?></p>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_quality-control' ) ); ?>">
        <?php wp_nonce_field( 'evg_qc_action', 'evg_qc_action_nonce' ); ?>
        <input type="hidden" name="card_id" value="<?php echo esc_attr( $card->id ); ?>">
        <input type="hidden" name="submission_id" value="<?php echo esc_attr( $card->submission_id ); ?>">

        <div class="evg-qc-split-grid">
            <!-- Left Panel: Label Data Verification & Defect Evidence -->
            <div>
                <div class="evg-qc-box">
                    <div class="evg-qc-box-header">
                        <h2><?php esc_html_e( 'Label Specification Verification', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-qc-box-body">
                        <table class="evg-verify-table">
                            <tr>
                                <td><?php esc_html_e( 'Certified Grade', 'evg-platform' ); ?></td>
                                <td><span class="evg-qc-grade-badge">EVG <?php echo esc_html( $card->final_grade ); ?></span></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Card Name', 'evg-platform' ); ?></td>
                                <td><strong style="color:#ffffff;"><?php echo esc_html( $card->card_name ); ?></strong></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Set Name', 'evg-platform' ); ?></td>
                                <td><?php echo esc_html( $card->set_name ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Card Number', 'evg-platform' ); ?></td>
                                <td>#<?php echo esc_html( $card->card_number ? $card->card_number : 'N/A' ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Language', 'evg-platform' ); ?></td>
                                <td><?php echo esc_html( $card->language ); ?></td>
                            </tr>
                            <tr>
                                <td><?php esc_html_e( 'Selected Slab Design', 'evg-platform' ); ?></td>
                                <td><span style="color:#d4af37; font-weight:700;"><?php echo esc_html( $card->label_option ); ?></span></td>
                            </tr>
                        </table>

                        <?php if ( $assessment ) : ?>
                            <div class="evg-subgrades-strip">
                                <span style="font-size:11px; text-transform:uppercase; color:#8e8e93; font-weight:700; letter-spacing:0.5px;">
                                    <?php esc_html_e( 'Grader Assessment Sub-Scores:', 'evg-platform' ); ?>
                                </span>
                                <div class="evg-subgrades-grid">
                                    <div class="evg-subgrade-cell">
                                        <span><?php esc_html_e( 'Centring', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( $assessment->centreing_score ); ?></strong>
                                    </div>
                                    <div class="evg-subgrade-cell">
                                        <span><?php esc_html_e( 'Corners', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( $assessment->corner_score ); ?></strong>
                                    </div>
                                    <div class="evg-subgrade-cell">
                                        <span><?php esc_html_e( 'Edges', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( $assessment->edge_score ); ?></strong>
                                    </div>
                                    <div class="evg-subgrade-cell">
                                        <span><?php esc_html_e( 'Surface', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( $assessment->surface_score ); ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Photographic Fault Evidence Inspector -->
                <div class="evg-qc-box">
                    <div class="evg-qc-box-header">
                        <h2><?php esc_html_e( 'Photographic Fault Evidence', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-qc-box-body">
                        <?php if ( ! empty( $fault_images ) ) : ?>
                            <div class="evg-fault-gallery">
                                <?php foreach ( $fault_images as $f ) : ?>
                                    <div class="evg-fault-card">
                                        <a href="<?php echo esc_url( $f->image_url ); ?>" target="_blank">
                                            <img src="<?php echo esc_url( $f->image_url ); ?>" alt="<?php echo esc_attr( $f->fault_type ); ?>">
                                        </a>
                                        <span><?php echo esc_html( $f->fault_type ); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else : ?>
                            <p style="color: #8e8e93; font-size: 12px; margin: 0;">
                                <?php esc_html_e( 'No specific defect photos logged for this card assessment.', 'evg-platform' ); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Step 5 QC Protocol Checklist -->
            <div class="evg-qc-box">
                <div class="evg-qc-box-header">
                    <h2><?php esc_html_e( 'Final QC Protocol Checklist', 'evg-platform' ); ?></h2>
                </div>
                <div class="evg-qc-box-body">
                    <div class="evg-checklist-group">
                        <label class="evg-check-item">
                            <input type="checkbox" required>
                            <span><?php esc_html_e( 'Verified correct card information matches the label exactly.', 'evg-platform' ); ?></span>
                        </label>
                        <label class="evg-check-item">
                            <input type="checkbox" required>
                            <span><?php esc_html_e( 'Verified the final grade printed on the label is correct (whole numbers 1-10 only).', 'evg-platform' ); ?></span>
                        </label>
                        <label class="evg-check-item">
                            <input type="checkbox" required>
                            <span><?php esc_html_e( 'Verified slab quality (no scratches, dust-free, tamper-evident seals intact).', 'evg-platform' ); ?></span>
                        </label>
                        <label class="evg-check-item">
                            <input type="checkbox" required>
                            <span><?php esc_html_e( 'Verified overall presentation meets EVG certification standards.', 'evg-platform' ); ?></span>
                        </label>

                        <!-- Transparency Report Publishing Control -->
                        <label class="evg-check-item" style="border-color: rgba(212, 175, 55, 0.3); background: rgba(212, 175, 55, 0.05);">
                            <input type="checkbox" name="transparency_published" value="1" <?php checked( $card->transparency_published, 1 ); ?>>
                            <span style="color: #d4af37;">
                                <strong><?php esc_html_e( 'Publish Transparency Report:', 'evg-platform' ); ?></strong>
                                <?php esc_html_e( 'Authorise customer to view sub-scores & photographic fault evidence in their dashboard.', 'evg-platform' ); ?>
                            </span>
                        </label>
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" name="qc_action" value="approve" class="evg-btn-approve">
                            🛡️ <?php esc_html_e( 'Pass QC & Authorize Encapsulation', 'evg-platform' ); ?>
                        </button>
                        <button type="submit" name="qc_action" value="reject" class="evg-btn-reject" formnovalidate onclick="return confirm('<?php esc_attr_e( 'Reject QC check and return card to the Grading Desk?', 'evg-platform' ); ?>');">
                            ✕ <?php esc_html_e( 'Reject & Return to Grading Desk', 'evg-platform' ); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
    <?php
}