<?php
/**
 * EVG Module: Settings (Pro Edition)
 * Global platform configuration, pricing tiers, turnaround times, transparency controls, and submission status toggles.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

function evg_settings_tab() {
    if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator' ) ) ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Settings.', 'evg-platform' ) . '</p></div>';
        return;
    }

    // ---------------------------------------------------------
    // Handle Form Submissions (Save Settings)
    // ---------------------------------------------------------
    if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_settings_nonce'] ) ) {
        if ( wp_verify_nonce( sanitize_key( $_POST['evg_settings_nonce'] ), 'evg_save_settings' ) ) {
            
            // Submissions Toggle (Sold Out Status)
            $accept_submissions = isset( $_POST['evg_accept_submissions'] ) ? 'yes' : 'no';
            update_option( 'evg_accept_submissions', $accept_submissions );

            // Transparency System Toggle
            $enable_transparency = isset( $_POST['evg_transparency_enabled'] ) ? 'yes' : 'no';
            update_option( 'evg_transparency_enabled', $enable_transparency );

            // General Information & Support
            update_option( 'evg_support_email', sanitize_email( wp_unslash( $_POST['evg_support_email'] ?? '' ) ) );
            update_option( 'evg_turnaround_time', sanitize_text_field( wp_unslash( $_POST['evg_turnaround_time'] ?? '' ) ) );

            // Pricing Configuration Matrix
            update_option( 'evg_price_standard', floatval( $_POST['evg_price_standard'] ?? 0 ) );
            update_option( 'evg_price_premium_upgrade', floatval( $_POST['evg_price_premium_upgrade'] ?? 0 ) );

            // Additional Configuration Tiers
            update_option( 'evg_max_cards_per_submission', absint( $_POST['evg_max_cards_per_submission'] ?? 50 ) );
            update_option( 'evg_return_shipping_fee', floatval( $_POST['evg_return_shipping_fee'] ?? 9.99 ) );
            update_option( 'evg_announcement_banner', sanitize_text_field( wp_unslash( $_POST['evg_announcement_banner'] ?? '' ) ) );

            if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
                Elite_Vault_Grading_System::log_activity( 'Updated Global Platform & Pricing Settings.' );
            }
            
            $redirect_url = admin_url( 'admin.php?page=evg_tab_settings&settings_updated=1' );
            if ( ! headers_sent() ) {
                wp_safe_redirect( $redirect_url );
                exit;
            } else {
                echo '<script>window.location.href = "' . esc_url( $redirect_url ) . '";</script>';
                exit;
            }
        }
    }

    if ( isset( $_GET['settings_updated'] ) ) {
        echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Platform settings successfully saved and applied.', 'evg-platform' ) . '</p></div>';
    }

    // ---------------------------------------------------------
    // Fetch Current Settings
    // ---------------------------------------------------------
    $accept_submissions  = get_option( 'evg_accept_submissions', 'yes' );
    $enable_transparency = get_option( 'evg_transparency_enabled', 'yes' );
    $support_email       = get_option( 'evg_support_email', 'elitevaultgrading@gmail.com' );
    $turnaround_time     = get_option( 'evg_turnaround_time', '30-45 Business Days' );
    $price_standard      = get_option( 'evg_price_standard', '15.00' );
    $price_premium       = get_option( 'evg_price_premium_upgrade', '5.00' );
    $max_cards           = get_option( 'evg_max_cards_per_submission', '50' );
    $shipping_fee        = get_option( 'evg_return_shipping_fee', '9.99' );
    $announcement        = get_option( 'evg_announcement_banner', '' );
    ?>

    <style>
        :root {
            --evg-gold: #d4af37;
            --evg-gold-light: #f3e5ab;
            --evg-border: #222224;
            --evg-bg-card: #0f0f11;
            --evg-text-muted: #8e8e93;
        }

        .evg-set-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .evg-set-header h1 {
            font-size: 26px;
            font-weight: 800;
            margin: 0 0 6px 0;
            color: #ffffff;
            letter-spacing: -0.5px;
        }
        .evg-set-header p {
            color: var(--evg-text-muted);
            font-size: 13px;
            margin: 0;
        }

        .evg-settings-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }

        .evg-set-box {
            background: var(--evg-bg-card);
            border: 1px solid var(--evg-border);
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.5);
            margin-bottom: 24px;
        }
        .evg-set-box:last-child {
            margin-bottom: 0;
        }
        .evg-set-box-header {
            padding: 18px 20px;
            background: #141416;
            border-bottom: 1px solid #1f1f22;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .evg-set-box-header h2 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #ffffff;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .evg-set-box-body {
            padding: 24px;
        }

        .evg-toggle-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #141416;
            padding: 20px;
            border: 1px solid #222224;
            border-radius: 10px;
        }

        .evg-input-group {
            margin-bottom: 20px;
        }
        .evg-input-group:last-child {
            margin-bottom: 0;
        }
        .evg-input-group label {
            display: block;
            color: var(--evg-gold);
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin-bottom: 8px;
        }
        .evg-field-control {
            width: 100%;
            background: #141416;
            border: 1px solid #28282b;
            color: #ffffff;
            padding: 12px 14px;
            font-size: 13px;
            border-radius: 8px;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s ease;
        }
        .evg-field-control:focus {
            border-color: var(--evg-gold);
        }
        .evg-help-text {
            margin: 6px 0 0 0;
            font-size: 11px;
            color: var(--evg-text-muted);
            line-height: 1.4;
        }

        /* Custom Toggle Switch */
        .evg-switch {
            position: relative;
            display: inline-block;
            width: 52px;
            height: 30px;
            flex-shrink: 0;
        }
        .evg-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .evg-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #2c2c2e;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 30px;
            border: 1px solid #3a3a3c;
        }
        .evg-slider:before {
            position: absolute;
            content: "";
            height: 22px;
            width: 22px;
            left: 3px;
            bottom: 3px;
            background-color: #ffffff;
            transition: .3s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
        }
        input:checked + .evg-slider {
            background-color: var(--evg-gold);
            border-color: var(--evg-gold);
        }
        input:checked + .evg-slider:before {
            transform: translateX(22px);
            background-color: #0a0a0a;
        }

        .evg-btn-save-settings {
            background: var(--evg-gold);
            color: #0a0a0a;
            border: none;
            padding: 14px 32px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .evg-btn-save-settings:hover {
            background: #f3e5ab;
            box-shadow: 0 4px 14px rgba(212, 175, 55, 0.25);
        }

        @media (max-width: 980px) {
            .evg-settings-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <div class="evg-set-header">
        <div>
            <h1><?php esc_html_e( 'Settings', 'evg-platform' ); ?></h1>
            <p><?php esc_html_e( 'Manage global pricing tiers, submission intake controls, support routing, and turnaround estimates.', 'evg-platform' ); ?></p>
        </div>
    </div>

    <form method="post" action="">
        <?php wp_nonce_field( 'evg_save_settings', 'evg_settings_nonce' ); ?>

        <div class="evg-settings-grid">
            
            <!-- Left Column: Operations & Status Controls -->
            <div>
                <div class="evg-set-box">
                    <div class="evg-set-box-header">
                        <h2><?php esc_html_e( 'Submission Intake Controls', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-set-box-body">
                        <div class="evg-toggle-card">
                            <div style="padding-right: 15px;">
                                <h4 style="margin:0 0 4px 0; color:#ffffff; font-size:14px; font-weight:700;"><?php esc_html_e( 'Accept New Submissions', 'evg-platform' ); ?></h4>
                                <p style="margin:0; font-size:12px; color:var(--evg-text-muted); line-height:1.4;">
                                    <?php esc_html_e( 'Toggle off to display the "GRADING CURRENTLY SOLD OUT" status banner across all frontend intake channels.', 'evg-platform' ); ?>
                                </p>
                            </div>
                            <div>
                                <label class="evg-switch">
                                    <input type="checkbox" name="evg_accept_submissions" value="yes" <?php checked( $accept_submissions, 'yes' ); ?>>
                                    <span class="evg-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div class="evg-toggle-card" style="margin-top: 16px;">
                            <div style="padding-right: 15px;">
                                <h4 style="margin:0 0 4px 0; color:#ffffff; font-size:14px; font-weight:700;"><?php esc_html_e( 'Grading Transparency System', 'evg-platform' ); ?></h4>
                                <p style="margin:0; font-size:12px; color:var(--evg-text-muted); line-height:1.4;">
                                    <?php esc_html_e( 'Allow customers to view published photographic fault evidence & category sub-score breakdowns in their portal.', 'evg-platform' ); ?>
                                </p>
                            </div>
                            <div>
                                <label class="evg-switch">
                                    <input type="checkbox" name="evg_transparency_enabled" value="yes" <?php checked( $enable_transparency, 'yes' ); ?>>
                                    <span class="evg-slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="evg-set-box">
                    <div class="evg-set-box-header">
                        <h2><?php esc_html_e( 'Communication & Operations', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-set-box-body">
                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Support Email Address', 'evg-platform' ); ?></label>
                            <input type="email" name="evg_support_email" class="evg-field-control" value="<?php echo esc_attr( $support_email ); ?>" required>
                            <p class="evg-help-text"><?php esc_html_e( 'Customer notifications, invoice copies, and feedback alerts are routed to this inbox.', 'evg-platform' ); ?></p>
                        </div>
                        
                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Estimated Turnaround Time', 'evg-platform' ); ?></label>
                            <input type="text" name="evg_turnaround_time" class="evg-field-control" value="<?php echo esc_attr( $turnaround_time ); ?>" required>
                            <p class="evg-help-text"><?php esc_html_e( 'Displayed dynamically on customer pre-order forms and portal pages.', 'evg-platform' ); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column: Pricing & Fee Structures -->
            <div>
                <div class="evg-set-box">
                    <div class="evg-set-box-header">
                        <h2><?php esc_html_e( 'Grading Tier & Fee Matrix', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-set-box-body">
                        <p style="color:var(--evg-text-muted); font-size:12px; margin:0 0 20px 0; line-height:1.5;">
                            <?php esc_html_e( 'Base rates are automatically computed at customer checkout. Shipping is inclusive of the calculated base unit rates.', 'evg-platform' ); ?>
                        </p>

                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Standard Grading Base Fee (£)', 'evg-platform' ); ?></label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 14px; top: 12px; color: var(--evg-text-muted); font-weight: 700; font-size: 13px;">&pound;</span>
                                <input type="number" name="evg_price_standard" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $price_standard, 2, '.', '' ) ); ?>" style="padding-left: 28px;" required>
                            </div>
                            <p class="evg-help-text"><?php esc_html_e( 'Standard cost per card unit including the default Elite Vault Grading label.', 'evg-platform' ); ?></p>
                        </div>

                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Premium Label Upgrade Fee (£)', 'evg-platform' ); ?></label>
                            <div style="position: relative;">
                                <span style="position: absolute; left: 14px; top: 12px; color: var(--evg-gold); font-weight: 700; font-size: 13px;">+&pound;</span>
                                <input type="number" name="evg_price_premium_upgrade" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $price_premium, 2, '.', '' ) ); ?>" style="padding-left: 36px; border-color: rgba(212, 175, 55, 0.3);" required>
                            </div>
                            <p class="evg-help-text" style="color: var(--evg-gold);">
                                <?php esc_html_e( 'Additional fee per card unit when opting for custom labels (Shield, Circle, or Vault door designs).', 'evg-platform' ); ?>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="evg-set-box">
                    <div class="evg-set-box-header">
                        <h2><?php esc_html_e( 'Logistics & Checkout Limits', 'evg-platform' ); ?></h2>
                    </div>
                    <div class="evg-set-box-body">
                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Max Cards Per Order Batch', 'evg-platform' ); ?></label>
                            <input type="number" name="evg_max_cards_per_submission" class="evg-field-control" min="1" value="<?php echo esc_attr( $max_cards ); ?>" required>
                        </div>
                        
                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Tracked Return Shipping Fee (£)', 'evg-platform' ); ?></label>
                            <input type="number" name="evg_return_shipping_fee" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $shipping_fee, 2, '.', '' ) ); ?>" required>
                        </div>

                        <div class="evg-input-group">
                            <label><?php esc_html_e( 'Top Banner Announcement (Optional)', 'evg-platform' ); ?></label>
                            <input type="text" name="evg_announcement_banner" class="evg-field-control" value="<?php echo esc_attr( $announcement ); ?>" placeholder="e.g. Free return shipping on orders over £150!">
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <div style="margin-top: 24px; display: flex; justify-content: flex-end;">
            <button type="submit" class="evg-btn-save-settings">
                <?php esc_html_e( 'Save Settings', 'evg-platform' ); ?>
            </button>
        </div>
    </form>
    <?php
}