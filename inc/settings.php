<?php
/**
 * EVG Module: Settings (Pro Edition)
 * Global platform configuration, pricing tiers, turnaround times, transparency controls,
 * submission status toggles, promotional coupon code configuration, and fee management.
 *
 * @package EliteVaultGradingCore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handle early POST settings save actions (Redirect-safe on admin_init).
 */
add_action( 'admin_init', 'evg_handle_settings_actions' );

function evg_handle_settings_actions() {
	if ( ! is_admin() || ! isset( $_GET['page'] ) || 'evg_tab_settings' !== $_GET['page'] ) {
		return;
	}

	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator' ) ) ) {
		return;
	}

	// ---------------------------------------------------------
	// Handle Form Submissions (Save Settings)
	// ---------------------------------------------------------
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['evg_settings_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_key( wp_unslash( $_POST['evg_settings_nonce'] ) ), 'evg_save_settings' ) ) {

			// Submissions Toggle (Sold Out Status).
			$evg_accept_submissions = isset( $_POST['evg_accept_submissions'] ) ? 'yes' : 'no';
			update_option( 'evg_accept_submissions', $evg_accept_submissions );

			// Transparency System Toggle.
			$evg_enable_transparency = isset( $_POST['evg_transparency_enabled'] ) ? 'yes' : 'no';
			update_option( 'evg_transparency_enabled', $evg_enable_transparency );

			// General Information & Support.
			$evg_support_email = isset( $_POST['evg_support_email'] ) ? sanitize_email( wp_unslash( $_POST['evg_support_email'] ) ) : 'info@elitevaultgrading.com';
			update_option( 'evg_support_email', $evg_support_email );

			$evg_turnaround_time = isset( $_POST['evg_turnaround_time'] ) ? sanitize_text_field( wp_unslash( $_POST['evg_turnaround_time'] ) ) : '5-10 Business Days';
			update_option( 'evg_turnaround_time', $evg_turnaround_time );

			// Pricing Configuration Matrix (Clamped & Normalized).
			$evg_price_standard = isset( $_POST['evg_price_standard'] ) ? max( 0.00, (float) wp_unslash( $_POST['evg_price_standard'] ) ) : 9.99;
			update_option( 'evg_price_standard', number_format( $evg_price_standard, 2, '.', '' ) );

			$evg_price_premium = isset( $_POST['evg_price_premium_upgrade'] ) ? max( 0.00, (float) wp_unslash( $_POST['evg_price_premium_upgrade'] ) ) : 2.99;
			update_option( 'evg_price_premium_upgrade', number_format( $evg_price_premium, 2, '.', '' ) );

			$evg_unlock_fee = isset( $_POST['evg_portfolio_unlock_fee'] ) ? max( 0.00, (float) wp_unslash( $_POST['evg_portfolio_unlock_fee'] ) ) : 0.99;
			update_option( 'evg_portfolio_unlock_fee', number_format( $evg_unlock_fee, 2, '.', '' ) );

			// Promotional Coupon Code Settings.
			$evg_coupon_enabled = isset( $_POST['evg_coupon_enabled'] ) ? 'yes' : 'no';
			update_option( 'evg_coupon_enabled', $evg_coupon_enabled );

			$evg_coupon_code = isset( $_POST['evg_coupon_code'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['evg_coupon_code'] ) ) ) : '';
			update_option( 'evg_coupon_code', $evg_coupon_code );

			$evg_coupon_type = isset( $_POST['evg_coupon_type'] ) && in_array( $_POST['evg_coupon_type'], array( 'percentage', 'fixed' ), true ) ? sanitize_text_field( wp_unslash( $_POST['evg_coupon_type'] ) ) : 'percentage';
			update_option( 'evg_coupon_type', $evg_coupon_type );

			$evg_coupon_amount = isset( $_POST['evg_coupon_amount'] ) ? max( 0.00, (float) wp_unslash( $_POST['evg_coupon_amount'] ) ) : 0.00;
			update_option( 'evg_coupon_amount', number_format( $evg_coupon_amount, 2, '.', '' ) );

			// Additional Configuration Tiers.
			$evg_max_cards = isset( $_POST['evg_max_cards_per_submission'] ) ? max( 1, absint( $_POST['evg_max_cards_per_submission'] ) ) : 50;
			update_option( 'evg_max_cards_per_submission', $evg_max_cards );

			$evg_shipping_fee = isset( $_POST['evg_return_shipping_fee'] ) ? max( 0.00, (float) wp_unslash( $_POST['evg_return_shipping_fee'] ) ) : 9.99;
			update_option( 'evg_return_shipping_fee', number_format( $evg_shipping_fee, 2, '.', '' ) );

			$evg_announcement = isset( $_POST['evg_announcement_banner'] ) ? sanitize_text_field( wp_unslash( $_POST['evg_announcement_banner'] ) ) : '';
			update_option( 'evg_announcement_banner', $evg_announcement );

			if ( class_exists( 'Elite_Vault_Grading_System' ) && method_exists( 'Elite_Vault_Grading_System', 'log_activity' ) ) {
				Elite_Vault_Grading_System::log_activity( 'Updated Global Platform, Coupon & Pricing Settings.' );
			}

			evg_settings_safe_redirect( admin_url( 'admin.php?page=evg_tab_settings&settings_updated=1' ) );
		}
	}
}

/**
 * Universal redirect helper.
 *
 * @param string $location Destination URL.
 */
function evg_settings_safe_redirect( $location ) {
	if ( ! headers_sent() ) {
		wp_safe_redirect( $location );
		exit;
	} else {
		echo '<script type="text/javascript">window.location.href="' . esc_url_raw( $location ) . '";</script>';
		echo '<noscript><meta http-equiv="refresh" content="0;url=' . esc_url( $location ) . '"></noscript>';
		exit;
	}
}

/**
 * Render function for the Settings tab.
 */
function evg_settings_tab() {
	if ( ! Elite_Vault_Grading_System::has_access( array( 'administrator' ) ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not have permission to access Settings.', 'evg' ) . '</p></div>';
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['settings_updated'] ) ) {
		echo '<div class="notice notice-success is-dismissible" style="background:#141416; border-left:4px solid #d4af37; color:#fff; padding:12px 16px; margin-bottom:20px; border-radius:6px;"><p style="margin:0; font-weight:600;">' . esc_html__( 'Platform settings successfully saved and applied.', 'evg' ) . '</p></div>';
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	// ---------------------------------------------------------
	// Fetch Current Settings
	// ---------------------------------------------------------
	$evg_accept_submissions  = get_option( 'evg_accept_submissions', 'yes' );
	$evg_enable_transparency = get_option( 'evg_transparency_enabled', 'yes' );
	$evg_support_email       = get_option( 'evg_support_email', 'info@elitevaultgrading.com' );
	$evg_turnaround_time     = get_option( 'evg_turnaround_time', '5-10 Business Days' );
	$evg_price_standard      = get_option( 'evg_price_standard', '9.99' );
	$evg_price_premium       = get_option( 'evg_price_premium_upgrade', '2.99' );
	$evg_unlock_fee          = get_option( 'evg_portfolio_unlock_fee', '0.99' );

	// Coupon Settings.
	$evg_coupon_enabled = get_option( 'evg_coupon_enabled', 'yes' );
	$evg_coupon_code    = get_option( 'evg_coupon_code', 'EVG10' );
	$evg_coupon_type    = get_option( 'evg_coupon_type', 'percentage' );
	$evg_coupon_amount  = get_option( 'evg_coupon_amount', '10.00' );

	$evg_max_cards    = get_option( 'evg_max_cards_per_submission', '50' );
	$evg_shipping_fee = get_option( 'evg_return_shipping_fee', '9.99' );
	$evg_announcement = get_option( 'evg_announcement_banner', '' );
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
			<h1><?php esc_html_e( 'Settings', 'evg' ); ?></h1>
			<p><?php esc_html_e( 'Manage global pricing tiers, submission intake controls, support routing, turnaround estimates, and promotional coupon codes.', 'evg' ); ?></p>
		</div>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=evg_tab_settings' ) ); ?>">
		<?php wp_nonce_field( 'evg_save_settings', 'evg_settings_nonce' ); ?>

		<div class="evg-settings-grid">
			
			<!-- Left Column: Operations & Status Controls -->
			<div>
				<div class="evg-set-box">
					<div class="evg-set-box-header">
						<h2><?php esc_html_e( 'Submission Intake Controls', 'evg' ); ?></h2>
					</div>
					<div class="evg-set-box-body">
						<div class="evg-toggle-card">
							<div style="padding-right: 15px;">
								<h4 style="margin:0 0 4px 0; color:#ffffff; font-size:14px; font-weight:700;"><?php esc_html_e( 'Accept New Submissions', 'evg' ); ?></h4>
								<p style="margin:0; font-size:12px; color:var(--evg-text-muted); line-height:1.4;">
									<?php esc_html_e( 'Toggle off to display the "GRADING CURRENTLY SOLD OUT" status banner across all frontend intake channels.', 'evg' ); ?>
								</p>
							</div>
							<div>
								<label class="evg-switch">
									<input type="checkbox" name="evg_accept_submissions" value="yes" <?php checked( $evg_accept_submissions, 'yes' ); ?>>
									<span class="evg-slider"></span>
								</label>
							</div>
						</div>

						<div class="evg-toggle-card" style="margin-top: 16px;">
							<div style="padding-right: 15px;">
								<h4 style="margin:0 0 4px 0; color:#ffffff; font-size:14px; font-weight:700;"><?php esc_html_e( 'Grading Transparency System', 'evg' ); ?></h4>
								<p style="margin:0; font-size:12px; color:var(--evg-text-muted); line-height:1.4;">
									<?php esc_html_e( 'Allow customers to view published photographic fault evidence & category sub-score breakdowns in their portal.', 'evg' ); ?>
								</p>
							</div>
							<div>
								<label class="evg-switch">
									<input type="checkbox" name="evg_transparency_enabled" value="yes" <?php checked( $evg_enable_transparency, 'yes' ); ?>>
									<span class="evg-slider"></span>
								</label>
							</div>
						</div>
					</div>
				</div>

				<!-- Promotional Coupon Settings Box -->
				<div class="evg-set-box">
					<div class="evg-set-box-header">
						<h2><?php esc_html_e( 'Promotional Coupon Configuration', 'evg' ); ?></h2>
					</div>
					<div class="evg-set-box-body">
						<div class="evg-toggle-card" style="margin-bottom: 20px;">
							<div style="padding-right: 15px;">
								<h4 style="margin:0 0 4px 0; color:#ffffff; font-size:14px; font-weight:700;"><?php esc_html_e( 'Enable Coupon Redemption', 'evg' ); ?></h4>
								<p style="margin:0; font-size:12px; color:var(--evg-text-muted); line-height:1.4;">
									<?php esc_html_e( 'Allows collectors to enter and redeem promotional codes on the grading submission checkout summary.', 'evg' ); ?>
								</p>
							</div>
							<div>
								<label class="evg-switch">
									<input type="checkbox" name="evg_coupon_enabled" value="yes" <?php checked( $evg_coupon_enabled, 'yes' ); ?>>
									<span class="evg-slider"></span>
								</label>
							</div>
						</div>

						<div class="evg-input-group">
							<label><?php esc_html_e( 'Active Coupon Code', 'evg' ); ?></label>
							<input type="text" name="evg_coupon_code" class="evg-field-control" value="<?php echo esc_attr( $evg_coupon_code ); ?>" placeholder="<?php esc_attr_e( 'e.g. EVG10', 'evg' ); ?>" style="font-family: monospace; font-weight: 700; text-transform: uppercase;">
							<p class="evg-help-text"><?php esc_html_e( 'The exact code customers must type to claim discount (case-insensitive).', 'evg' ); ?></p>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
							<div class="evg-input-group">
								<label><?php esc_html_e( 'Discount Type', 'evg' ); ?></label>
								<select name="evg_coupon_type" class="evg-field-control">
									<option value="percentage" <?php selected( $evg_coupon_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage (%)', 'evg' ); ?></option>
									<option value="fixed" <?php selected( $evg_coupon_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount (£)', 'evg' ); ?></option>
								</select>
							</div>

							<div class="evg-input-group">
								<label><?php esc_html_e( 'Discount Value', 'evg' ); ?></label>
								<input type="number" name="evg_coupon_amount" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( $evg_coupon_amount ); ?>" required>
								<p class="evg-help-text"><?php esc_html_e( 'e.g. 10 for 10% or 5.00 for £5 off.', 'evg' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<div class="evg-set-box">
					<div class="evg-set-box-header">
						<h2><?php esc_html_e( 'Communication & Operations', 'evg' ); ?></h2>
					</div>
					<div class="evg-set-box-body">
						<div class="evg-input-group">
							<label><?php esc_html_e( 'Support Email Address', 'evg' ); ?></label>
							<input type="email" name="evg_support_email" class="evg-field-control" value="<?php echo esc_attr( $evg_support_email ); ?>" required>
							<p class="evg-help-text"><?php esc_html_e( 'Customer notifications, invoice copies, and feedback alerts are routed to this inbox.', 'evg' ); ?></p>
						</div>
						
						<div class="evg-input-group">
							<label><?php esc_html_e( 'Estimated Turnaround Time', 'evg' ); ?></label>
							<input type="text" name="evg_turnaround_time" class="evg-field-control" value="<?php echo esc_attr( $evg_turnaround_time ); ?>" required>
							<p class="evg-help-text"><?php esc_html_e( 'Displayed dynamically on customer intake forms and portal pages (Standard: 5-10 Business Days).', 'evg' ); ?></p>
						</div>
					</div>
				</div>
			</div>

			<!-- Right Column: Pricing & Fee Structures -->
			<div>
				<div class="evg-set-box">
					<div class="evg-set-box-header">
						<h2><?php esc_html_e( 'Grading Tier & Fee Matrix', 'evg' ); ?></h2>
					</div>
					<div class="evg-set-box-body">
						<p style="color:var(--evg-text-muted); font-size:12px; margin:0 0 20px 0; line-height:1.5;">
							<?php esc_html_e( 'Base rates are automatically computed at customer checkout. Submissions start from £9.99.', 'evg' ); ?>
						</p>

						<div class="evg-input-group">
							<label><?php esc_html_e( 'Standard Grading Base Fee (£)', 'evg' ); ?></label>
							<div style="position: relative;">
								<span style="position: absolute; left: 14px; top: 12px; color: var(--evg-text-muted); font-weight: 700; font-size: 13px;">&pound;</span>
								<input type="number" name="evg_price_standard" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $evg_price_standard, 2, '.', '' ) ); ?>" style="padding-left: 28px;" required>
							</div>
							<p class="evg-help-text"><?php esc_html_e( 'Standard cost per card unit including the default Elite Vault Grading label (Standard base rate from £9.99).', 'evg' ); ?></p>
						</div>

						<div class="evg-input-group">
							<label><?php esc_html_e( 'Premium Label Upgrade Fee (£)', 'evg' ); ?></label>
							<div style="position: relative;">
								<span style="position: absolute; left: 14px; top: 12px; color: var(--evg-gold); font-weight: 700; font-size: 13px;">+&pound;</span>
								<input type="number" name="evg_price_premium_upgrade" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $evg_price_premium, 2, '.', '' ) ); ?>" style="padding-left: 36px; border-color: rgba(212, 175, 55, 0.3);" required>
							</div>
							<p class="evg-help-text" style="color: var(--evg-gold);">
								<?php esc_html_e( 'Additional fee per card unit when opting for custom labels (Colour Match, Lightening, or Extended Artwork designs).', 'evg' ); ?>
							</p>
						</div>

						<div class="evg-input-group">
							<label><?php esc_html_e( 'Full Damage Portfolio Unlock Fee (£)', 'evg' ); ?></label>
							<div style="position: relative;">
								<span style="position: absolute; left: 14px; top: 12px; color: var(--evg-gold); font-weight: 700; font-size: 13px;">&pound;</span>
								<input type="number" name="evg_portfolio_unlock_fee" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $evg_unlock_fee, 2, '.', '' ) ); ?>" style="padding-left: 28px; border-color: rgba(212, 175, 55, 0.3);" required>
							</div>
							<p class="evg-help-text" style="color: var(--evg-gold);">
								<?php esc_html_e( 'Micro-fee charged to unlock high-resolution damage portfolio scans beyond the 3 free preview photos (Standard: £0.99).', 'evg' ); ?>
							</p>
						</div>
					</div>
				</div>

				<div class="evg-set-box">
					<div class="evg-set-box-header">
						<h2><?php esc_html_e( 'Logistics & Checkout Limits', 'evg' ); ?></h2>
					</div>
					<div class="evg-set-box-body">
						<div class="evg-input-group">
							<label><?php esc_html_e( 'Max Cards Per Order Batch', 'evg' ); ?></label>
							<input type="number" name="evg_max_cards_per_submission" class="evg-field-control" min="1" value="<?php echo esc_attr( $evg_max_cards ); ?>" required>
						</div>
						
						<div class="evg-input-group">
							<label><?php esc_html_e( 'Tracked Return Shipping Fee (£)', 'evg' ); ?></label>
							<input type="number" name="evg_return_shipping_fee" class="evg-field-control" min="0.00" step="0.01" value="<?php echo esc_attr( number_format( (float) $evg_shipping_fee, 2, '.', '' ) ); ?>" required>
						</div>

						<div class="evg-input-group">
							<label><?php esc_html_e( 'Top Banner Announcement (Optional)', 'evg' ); ?></label>
							<input type="text" name="evg_announcement_banner" class="evg-field-control" value="<?php echo esc_attr( $evg_announcement ); ?>" placeholder="<?php esc_attr_e( 'e.g. Free return shipping on orders over £150!', 'evg' ); ?>">
						</div>
					</div>
				</div>
			</div>

		</div>

		<div style="margin-top: 24px; display: flex; justify-content: flex-end;">
			<button type="submit" class="evg-btn-save-settings">
				<?php esc_html_e( 'Save Settings', 'evg' ); ?>
			</button>
		</div>
	</form>
	<?php
}