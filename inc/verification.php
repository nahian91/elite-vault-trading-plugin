<?php
/**
 * EVG Module: Public QR Code & Slab Verification Endpoint
 * Intercepts requests to /verify/?cert=EVG-XXXXX or query var ?cert=EVG-XXXXX 
 * and renders an authenticated public certificate verification portal.
 * Features whole-number grades (1-10), 4 sub-scores, up to 3 free preview photos,
 * and the £0.99 damage portfolio unlock paywall.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hook into template redirection to intercept certificate lookups
 */
add_action( 'template_redirect', 'evg_public_verification_endpoint_listener' );

function evg_public_verification_endpoint_listener() {
    $cert_param = get_query_var( 'cert' );
    if ( empty( $cert_param ) && isset( $_GET['cert'] ) ) {
        $cert_param = sanitize_text_field( wp_unslash( $_GET['cert'] ) );
    }

    if ( ! empty( $cert_param ) ) {
        evg_render_public_slab_certificate( $cert_param );
        exit;
    }
}

/**
 * Fetch authenticated slab metadata from database
 */
function evg_handle_public_slab_lookup( $cert_id = '' ) {
    global $wpdb;

    $table_cards       = $wpdb->prefix . 'evg_cards';
    $table_assessments = $wpdb->prefix . 'evg_assessments';
    $table_submissions = $wpdb->prefix . 'evg_submissions';

    $cleaned_id = preg_replace( '/[^0-9]/', '', $cert_id );
    if ( empty( $cleaned_id ) ) {
        return null;
    }

    $card_record = $wpdb->get_row( $wpdb->prepare( "
        SELECT c.*, 
               a.centreing_score, a.corner_score, a.edge_score, a.surface_score, a.assessed_date, a.grader_comments,
               s.order_number, s.label_option, s.service_type
        FROM {$table_cards} c
        LEFT JOIN {$table_assessments} a ON c.id = a.card_id
        LEFT JOIN {$table_submissions} s ON c.submission_id = s.id
        WHERE c.id = %d AND c.grading_status IN ('Encapsulation', 'Completed', 'Quality Control')
    ", intval( $cleaned_id ) ) );

    return $card_record;
}

/**
 * Render White-Label Public Slab Certificate Page
 */
function evg_render_public_slab_certificate( $cert_param ) {
    global $wpdb;

    $card = evg_handle_public_slab_lookup( $cert_param );
    $cert_number = 'EVG-' . strtoupper( ltrim( preg_replace( '/[^0-9]/', '', $cert_param ), '0' ) );
    if ( ! $card ) {
        $clean_num = preg_replace( '/[^0-9]/', '', $cert_param );
        $cert_number = $clean_num ? 'EVG-' . str_pad( $clean_num, 6, '0', STR_PAD_LEFT ) : esc_html( $cert_param );
    } else {
        $cert_number = 'EVG-' . str_pad( (string) $card->id, 6, '0', STR_PAD_LEFT );
    }

    $current_user_id = get_current_user_id();
    $has_unlocked    = false;
    $unlock_fee      = floatval( get_option( 'evg_portfolio_unlock_fee', 0.99 ) );

    $fault_images = array();
    if ( $card ) {
        $table_faults  = $wpdb->prefix . 'evg_fault_images';
        $table_unlocks = $wpdb->prefix . 'evg_portfolio_unlocks';

        $fault_images = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table_faults} WHERE card_id = %d ORDER BY is_preview DESC, id ASC",
            $card->id
        ) );

        if ( $current_user_id > 0 ) {
            $unlock_record = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table_unlocks} WHERE user_id = %d AND card_id = %d AND payment_status = 'Completed'",
                $current_user_id,
                $card->id
            ) );
            $has_unlocked = ! empty( $unlock_record );
        }
    }

    status_header( 200 );
    nocache_headers();
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo esc_html( $cert_number ); ?> | <?php esc_html_e( 'Official Slab Verification', 'evg-platform' ); ?></title>
        <style>
            :root {
                --evg-gold: #d4af37;
                --evg-gold-light: #f3e5ab;
                --evg-bg: #0a0a0a;
                --evg-surface: #111113;
                --evg-border: #222226;
                --evg-muted: #8e8e93;
            }
            body {
                margin: 0;
                padding: 0;
                background-color: var(--evg-bg);
                color: #ffffff;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                -webkit-font-smoothing: antialiased;
                line-height: 1.5;
            }
            .evg-verify-container {
                max-width: 860px;
                margin: 40px auto;
                padding: 0 20px;
            }
            .evg-verify-brand {
                text-align: center;
                margin-bottom: 30px;
            }
            .evg-verify-brand h1 {
                font-size: 16px;
                font-weight: 800;
                letter-spacing: 2px;
                text-transform: uppercase;
                color: var(--evg-gold);
                margin: 10px 0 0;
            }
            .evg-card-shell {
                background: var(--evg-surface);
                border: 1px solid var(--evg-border);
                border-radius: 14px;
                box-shadow: 0 20px 40px rgba(0, 0, 0, 0.6);
                overflow: hidden;
            }
            .evg-card-header {
                padding: 24px;
                background: #151518;
                border-bottom: 1px solid var(--evg-border);
                display: flex;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 15px;
            }
            .evg-cert-id {
                font-family: monospace;
                font-size: 20px;
                font-weight: 800;
                color: var(--evg-gold);
            }
            .evg-status-valid {
                background: rgba(52, 199, 89, 0.1);
                border: 1px solid rgba(52, 199, 89, 0.3);
                color: #34c759;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 5px 12px;
                border-radius: 20px;
                display: inline-flex;
                align-items: center;
                gap: 6px;
            }
            .evg-status-invalid {
                background: rgba(255, 69, 58, 0.1);
                border: 1px solid rgba(255, 69, 58, 0.3);
                color: #ff453a;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                padding: 5px 12px;
                border-radius: 20px;
            }
            .evg-card-body {
                padding: 28px;
            }
            .evg-spec-grid {
                display: grid;
                grid-template-columns: 140px 1fr;
                gap: 24px;
                align-items: center;
                margin-bottom: 30px;
            }
            .evg-grade-token {
                background: linear-gradient(145deg, #e3c457, #b8962e);
                color: #0a0a0a;
                border-radius: 12px;
                height: 120px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                box-shadow: 0 8px 24px rgba(212, 175, 55, 0.25);
            }
            .evg-grade-token .grade-num {
                font-size: 48px;
                font-weight: 900;
                line-height: 1;
            }
            .evg-grade-token .grade-title {
                font-size: 11px;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-top: 4px;
            }
            .evg-details-table {
                width: 100%;
                border-collapse: collapse;
            }
            .evg-details-table td {
                padding: 8px 0;
                border-bottom: 1px solid #1a1a1d;
                font-size: 13px;
            }
            .evg-details-table td:first-child {
                color: var(--evg-muted);
                width: 35%;
            }
            .evg-details-table td:last-child {
                color: #ffffff;
                font-weight: 600;
            }
            .evg-subgrades-box {
                background: #151518;
                border: 1px solid var(--evg-border);
                border-radius: 10px;
                padding: 16px;
                margin-bottom: 24px;
            }
            .evg-subgrades-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                margin-top: 10px;
                text-align: center;
            }
            .evg-sub-pill {
                background: #0d0d0f;
                border: 1px solid #28282b;
                border-radius: 8px;
                padding: 10px 4px;
            }
            .evg-sub-pill span {
                display: block;
                font-size: 10px;
                color: var(--evg-muted);
                text-transform: uppercase;
                font-weight: 700;
            }
            .evg-sub-pill strong {
                font-size: 18px;
                color: var(--evg-gold);
                font-weight: 800;
            }
            .evg-gallery-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 14px;
                margin-top: 14px;
            }
            .evg-gallery-card {
                background: #0d0d0f;
                border: 1px solid #28282b;
                border-radius: 8px;
                overflow: hidden;
                position: relative;
            }
            .evg-gallery-card img {
                width: 100%;
                height: 110px;
                object-fit: cover;
                display: block;
            }
            .evg-gallery-card.is-locked img {
                filter: blur(12px) brightness(0.4);
                transform: scale(1.1);
            }
            .evg-gallery-card .lock-overlay {
                position: absolute;
                inset: 0;
                background: rgba(5, 5, 5, 0.45);
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                font-weight: 700;
                color: #ffffff;
                text-align: center;
                padding: 10px;
            }
            .evg-free-tag {
                position: absolute;
                top: 6px;
                left: 6px;
                background: var(--evg-gold);
                color: #0a0a0a;
                font-size: 8px;
                font-weight: 800;
                text-transform: uppercase;
                padding: 2px 5px;
                border-radius: 3px;
                font-family: monospace;
                z-index: 2;
            }
            .evg-paywall-box {
                margin-top: 20px;
                background: linear-gradient(180deg, #18181c 0%, #111113 100%);
                border: 1px solid rgba(212, 175, 55, 0.35);
                border-radius: 10px;
                padding: 20px;
                text-align: center;
            }
            .evg-btn-unlock {
                display: inline-block;
                background: var(--evg-gold);
                color: #0a0a0a;
                font-weight: 800;
                font-size: 13px;
                text-transform: uppercase;
                padding: 12px 24px;
                border-radius: 8px;
                text-decoration: none;
                margin-top: 10px;
                transition: all 0.2s ease;
            }
            .evg-btn-unlock:hover {
                background: var(--evg-gold-light);
                transform: translateY(-1px);
            }
            @media (max-width: 600px) {
                .evg-spec-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
    </head>
    <body>
        <div class="evg-verify-container">
            <div class="evg-verify-brand">
                <h1>Elite Vault Grading</h1>
                <p style="color: var(--evg-muted); font-size: 13px; margin: 4px 0 0;"><?php esc_html_e( 'Official Certificate & Slab Integrity Registry', 'evg-platform' ); ?></p>
            </div>

            <div class="evg-card-shell">
                <div class="evg-card-header">
                    <div>
                        <span style="display:block; font-size: 10px; color: var(--evg-muted); text-transform: uppercase; font-weight: 700;"><?php esc_html_e( 'Certification Identification', 'evg-platform' ); ?></span>
                        <span class="evg-cert-id"><?php echo esc_html( $cert_number ); ?></span>
                    </div>
                    <?php if ( $card ) : ?>
                        <div class="evg-status-valid">✓ <?php esc_html_e( 'Certified Authentic Slab', 'evg-platform' ); ?></div>
                    <?php else : ?>
                        <div class="evg-status-invalid">✕ <?php esc_html_e( 'Verification Failed', 'evg-platform' ); ?></div>
                    <?php endif; ?>
                </div>

                <div class="evg-card-body">
                    <?php if ( $card ) : ?>
                        <div class="evg-spec-grid">
                            <div class="evg-grade-token">
                                <span class="grade-num"><?php echo esc_html( $card->final_grade ); ?></span>
                                <span class="grade-title"><?php esc_html_e( 'EVG Grade', 'evg-platform' ); ?></span>
                            </div>
                            <table class="evg-details-table">
                                <tr>
                                    <td><?php esc_html_e( 'Card Name', 'evg-platform' ); ?></td>
                                    <td><?php echo esc_html( $card->card_name ); ?></td>
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
                                    <td><?php esc_html_e( 'Encapsulation', 'evg-platform' ); ?></td>
                                    <td><?php echo esc_html( $card->label_option ); ?></td>
                                </tr>
                            </table>
                        </div>

                        <?php if ( ! empty( $card->centreing_score ) ) : ?>
                            <div class="evg-subgrades-box">
                                <span style="font-size:11px; font-weight:700; text-transform:uppercase; color:var(--evg-gold);">
                                    <?php esc_html_e( 'Official Assessment Sub-Scores (1-10 Scale)', 'evg-platform' ); ?>
                                </span>
                                <div class="evg-subgrades-row">
                                    <div class="evg-sub-pill">
                                        <span><?php esc_html_e( 'Centring', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( intval( $card->centreing_score ) ); ?></strong>
                                    </div>
                                    <div class="evg-sub-pill">
                                        <span><?php esc_html_e( 'Corners', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( intval( $card->corner_score ) ); ?></strong>
                                    </div>
                                    <div class="evg-sub-pill">
                                        <span><?php esc_html_e( 'Edges', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( intval( $card->edge_score ) ); ?></strong>
                                    </div>
                                    <div class="evg-sub-pill">
                                        <span><?php esc_html_e( 'Surface', 'evg-platform' ); ?></span>
                                        <strong><?php echo esc_html( intval( $card->surface_score ) ); ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $fault_images ) ) : ?>
                            <h3 style="font-size: 14px; text-transform: uppercase; color: #fff; margin: 24px 0 10px;">
                                <?php esc_html_e( 'Defect & Fault Transparency Gallery', 'evg-platform' ); ?>
                            </h3>
                            <div class="evg-gallery-grid">
                                <?php 
                                $has_locked_images = false;
                                foreach ( $fault_images as $f ) :
                                    $is_accessible = ( (int) $f->is_free_preview === 1 || $has_unlocked );
                                    if ( ! $is_accessible ) {
                                        $has_locked_images = true;
                                    }
                                ?>
                                    <div class="evg-gallery-card <?php echo $is_accessible ? '' : 'is-locked'; ?>">
                                        <img src="<?php echo esc_url( $f->image_url ); ?>" alt="<?php echo esc_attr( $f->fault_type ); ?>" loading="lazy">
                                        
                                        <?php if ( (int) $f->is_free_preview === 1 && ! $has_unlocked ) : ?>
                                            <span class="evg-free-tag"><?php esc_html_e( 'Free Preview', 'evg-platform' ); ?></span>
                                        <?php endif; ?>

                                        <?php if ( ! $is_accessible ) : ?>
                                            <div class="lock-overlay">
                                                <span style="font-size: 14px; margin-bottom: 2px;">🔒</span>
                                                <span><?php esc_html_e( 'LOCKED SCAN', 'evg-platform' ); ?></span>
                                            </div>
                                        <?php endif; ?>

                                        <span style="display:block; font-size:11px; text-align:center; padding:6px; color:var(--evg-gold);">
                                            <?php echo esc_html( $f->fault_type ); ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php if ( $has_locked_images && ! $has_unlocked ) : ?>
                                <div class="evg-paywall-box">
                                    <h4 style="margin:0 0 6px 0; color:#fff; font-size: 15px;"><?php esc_html_e( 'Unlock Complete High-Resolution Defect Portfolio', 'evg-platform' ); ?></h4>
                                    <p style="margin:0 0 12px 0; font-size: 12px; color: var(--evg-muted);"><?php esc_html_e( 'Gain permanent access to unblurred micro-defect scans and high-resolution audit photos for £0.99.', 'evg-platform' ); ?></p>
                                    <a href="<?php echo esc_url( home_url( '/checkout?action=unlock_portfolio&card_id=' . $card->id ) ); ?>" class="evg-btn-unlock">
                                        <?php printf( esc_html__( 'Unlock Full Portfolio (£%s)', 'evg-platform' ), number_format( (float) $unlock_fee, 2 ) ); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                    <?php else : ?>
                        <div style="text-align: center; padding: 40px 20px;">
                            <p style="color: #ff453a; font-size: 16px; font-weight: 700; margin-bottom: 8px;">
                                <?php esc_html_e( 'No authenticated card found matching this certificate number.', 'evg-platform' ); ?>
                            </p>
                            <p style="color: var(--evg-muted); font-size: 13px; max-width: 440px; margin: 0 auto;">
                                <?php esc_html_e( 'Please check the reference number stamped on your Elite Vault Grading tamper-evident label and try again.', 'evg-platform' ); ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}