<?php
/**
 * Plugin Name:       Elite Vault Grading - Platform Core
 * Plugin URI:        https://elitevaultgrading.com
 * Description:       Standalone, high-performance ERP core & internal grading engine for EVG featuring pre-order/submission tracking, strict 1-10 integer grading assessments, fault evidence uploads, quality control desk, marketplace stock management, and staff RBAC.
 * Version:           1.3.0
 * Author:            EVG Dev
 * Author URI:        https://elitevaultgrading.com
 * Text Domain:       evg-platform
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * 1. Constants & Path Definitions
 */
define( 'EVG_CORE_VERSION', '1.3.0' );
define( 'EVG_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'EVG_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'EVG_CORE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main Core Plugin Class Engine (OOP Singleton Architecture)
 */
final class Elite_Vault_Grading_System {

    private static $instance = null;

    /**
     * Singleton Pattern Implementation
     */
    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor Engine
     */
    private function __construct() {
        $this->load_modular_dependencies();
        $this->init_hooks();
    }

    /**
     * Include Modular Backend Sub-Files Securely
     */
    private function load_modular_dependencies() {
        $files = array(
            'dashboard',
            'submissions',
            'grading-desk',
            'quality-control',
            'marketplace',
            'customers',
            'transactions',
            'feedback',
            'settings'
        );

        foreach ( $files as $file ) {
            $path = EVG_CORE_PATH . 'inc/' . $file . '.php';
            if ( file_exists( $path ) ) {
                require_once $path;
            }
        }
    }

    /**
     * Initialize Core Hooks & Filters
     */
    private function init_hooks() {
        // Activation Routine
        register_activation_hook( __FILE__, array( $this, 'execute_database_migration' ) );

        // Enqueue Assets
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_head', array( $this, 'inject_dashboard_white_label_layout' ) );

        // Menu Structure
        add_action( 'admin_menu', array( $this, 'mount_core_erp_menu' ) );

        // Login & Routing Adjustments
        add_action( 'wp_logout', array( $this, 'handle_secure_logout_redirection' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'apply_white_label_login_styles' ) );
        add_filter( 'login_redirect', array( $this, 'handle_login_redirect' ), 10, 3 );
        
        // Security & Authentications
        add_filter( 'login_headerurl', array( $this, 'get_login_logo_url' ) );
        add_filter( 'login_headertext', array( $this, 'get_login_logo_title' ) );
        add_action( 'login_form', array( $this, 'display_mathematical_captcha' ) );
        add_filter( 'authenticate', array( $this, 'validate_mathematical_captcha' ), 25, 3 );

        // Secure Backend Invoice Generation Action
        add_action( 'admin_post_evg_download_invoice', array( $this, 'handle_invoice_download' ) );
    }

    /**
     * Standardized EVG Lifecycle Order Stages
     */
    public static function get_order_stages() {
        return array(
            'Pre-Order Received'    => __( 'Pre-Order Received', 'evg-platform' ),
            'Cards Awaiting Arrival'=> __( 'Cards Awaiting Arrival', 'evg-platform' ),
            'Cards Received'        => __( 'Cards Received', 'evg-platform' ),
            'Authentication Check'  => __( 'Authentication Check', 'evg-platform' ),
            'Under Review'          => __( 'Under Review', 'evg-platform' ),
            'Grading In Progress'   => __( 'Grading In Progress', 'evg-platform' ),
            'Quality Control'       => __( 'Quality Control', 'evg-platform' ),
            'Encapsulation'         => __( 'Encapsulation', 'evg-platform' ),
            'Completed'             => __( 'Completed', 'evg-platform' ),
            'Returned To Customer'  => __( 'Returned To Customer', 'evg-platform' ),
        );
    }

    /**
     * Standardized EVG Whole-Number Grading Scale (Strict 1-10)
     */
    public static function get_grading_scale() {
        return array(
            10 => __( '10 - Elite Gem', 'evg-platform' ),
            9  => __( '9 - Mint', 'evg-platform' ),
            8  => __( '8 - Near Mint / Mint', 'evg-platform' ),
            7  => __( '7 - Near Mint', 'evg-platform' ),
            6  => __( '6 - Excellent', 'evg-platform' ),
            5  => __( '5 - Very Good', 'evg-platform' ),
            4  => __( '4 - Good', 'evg-platform' ),
            3  => __( '3 - Fair', 'evg-platform' ),
            2  => __( '2 - Poor', 'evg-platform' ),
            1  => __( '1 - Damaged', 'evg-platform' ),
        );
    }

    /**
     * Handle Secure Invoice Download Generation (Admin/Support Only)
     */
    public function handle_invoice_download() {
        if ( ! self::has_access( array( 'administrator', 'support_team' ) ) ) {
            wp_die( esc_html__( 'Access Denied: You do not possess the required privilege level for this module.', 'evg-platform' ), 403 );
        }

        $submission_id = isset( $_GET['submission_id'] ) ? intval( $_GET['submission_id'] ) : 0;
        if ( $submission_id <= 0 ) {
            wp_die( esc_html__( 'Invalid Submission ID.', 'evg-platform' ), 400 );
        }

        global $wpdb;
        $order = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}evg_submissions WHERE id = %d", $submission_id ) );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order record not found.', 'evg-platform' ), 404 );
        }

        $cards = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}evg_cards WHERE submission_id = %d", $submission_id ) );
        $customer = get_userdata( $order->customer_id );
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <title>EVG Invoice - #<?php echo esc_html( $order->order_number ); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; color: #111; line-height: 1.5; }
                .invoice-header { display: flex; justify-content: space-between; border-bottom: 2px solid #d4af37; padding-bottom: 20px; }
                .gold-title { color: #856914; margin: 0; font-size: 24px; text-transform: uppercase; }
                table { width: 100%; border-collapse: collapse; margin-top: 30px; }
                th, td { border: 1px solid #ddd; padding: 10px 14px; text-align: left; }
                th { background-color: #f8f8f8; font-weight: bold; }
                .total { text-align: right; margin-top: 20px; font-size: 18px; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class="invoice-header">
                <div>
                    <h1 class="gold-title">ELITE VAULT GRADING</h1>
                    <p>support@elitevaultgrading.com | www.elitevaultgrading.com</p>
                </div>
                <div style="text-align: right;">
                    <h2>INVOICE</h2>
                    <p><strong>Order:</strong> #<?php echo esc_html( $order->order_number ); ?><br>
                    <strong>Date:</strong> <?php echo esc_html( $order->submission_date ); ?><br>
                    <strong>Service:</strong> <?php echo esc_html( $order->service_type ); ?></p>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <strong>Billed To:</strong><br>
                <?php echo esc_html( $customer ? $customer->display_name : 'Customer' ); ?><br>
                <?php echo esc_html( $customer ? $customer->user_email : '' ); ?>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>Card Name</th>
                        <th>Set</th>
                        <th>Card #</th>
                        <th>Language</th>
                        <th>Label Option</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( ! empty( $cards ) ) : ?>
                        <?php foreach ( $cards as $card ) : ?>
                            <tr>
                                <td><?php echo esc_html( $card->card_name ); ?></td>
                                <td><?php echo esc_html( $card->set_name ); ?></td>
                                <td><?php echo esc_html( $card->card_number ); ?></td>
                                <td><?php echo esc_html( $card->language ); ?></td>
                                <td><?php echo esc_html( $order->label_option ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <tr><td colspan="5"><?php esc_html_e( 'No cards associated with this submission.', 'evg-platform' ); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <p class="total">Total: &pound;<?php echo number_format( $order->total_amount, 2 ); ?> (<?php echo esc_html( $order->payment_status ); ?>)</p>
            <script>window.print();</script>
        </body>
        </html>
        <?php
        exit;
    }

    /**
     * Role-Based Access Control Core (RBAC Engine)
     */
    public static function has_access( $allowed_roles = array() ) {
        if ( empty( $allowed_roles ) ) {
            return true;
        }
        
        $current_user = wp_get_current_user();
        if ( ! $current_user || ! $current_user->exists() ) {
            return false;
        }

        if ( in_array( 'administrator', (array) $current_user->roles, true ) || current_user_can( 'manage_options' ) ) {
            return true;
        }

        foreach ( $allowed_roles as $role ) {
            if ( in_array( $role, (array) $current_user->roles, true ) ) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Styles & Dynamic Assets Loading Processor
     */
    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'evg_management_system' ) === false && strpos( $hook, 'evg_tab_' ) === false ) {
            return;
        }

        wp_enqueue_media();
        wp_enqueue_style( 'bootstrap', EVG_CORE_URL . 'assets/css/bootstrap.min.css', array(), EVG_CORE_VERSION );
        wp_enqueue_style( 'datatables', EVG_CORE_URL . 'assets/css/jquery.dataTables.min.css', array(), EVG_CORE_VERSION );
        wp_enqueue_style( 'evg-admin-style', EVG_CORE_URL . 'assets/css/admin-style.css', array(), EVG_CORE_VERSION );

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script( 'bootstrap', EVG_CORE_URL . 'assets/js/bootstrap.bundle.min.js', array( 'jquery' ), EVG_CORE_VERSION, true );
        wp_enqueue_script( 'datatables', EVG_CORE_URL . 'assets/js/jquery.dataTables.min.js', array( 'jquery' ), EVG_CORE_VERSION, true );
        wp_enqueue_script( 'evg-main', EVG_CORE_URL . 'assets/js/main.js', array( 'jquery' ), EVG_CORE_VERSION, true );
    }

    /**
     * Global Database Migration & Schema Engine (dbDelta Compliant)
     */
    public function execute_database_migration() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Schema Model 1: Submissions/Orders (Pre-orders and Live Grading Submissions)
        $table_submissions = $wpdb->prefix . 'evg_submissions';
        $sql_submissions = "CREATE TABLE $table_submissions (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_number varchar(50) NOT NULL,
            customer_id bigint(20) NOT NULL,
            submission_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            service_type varchar(50) DEFAULT 'Standard' NOT NULL,
            submission_slot_tier varchar(50) DEFAULT '1 Card Submission' NOT NULL,
            total_cards int(11) NOT NULL,
            label_option varchar(50) DEFAULT 'Standard Label' NOT NULL,
            payment_status varchar(30) DEFAULT 'Pending' NOT NULL,
            total_amount decimal(10,2) DEFAULT '0.00' NOT NULL,
            current_stage varchar(50) DEFAULT 'Pre-Order Received' NOT NULL,
            return_tracking varchar(100) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number (order_number),
            KEY customer_idx (customer_id),
            KEY stage_idx (current_stage)
        ) $charset_collate;";
        dbDelta( $sql_submissions );

        // Schema Model 2: Individual Card Records (Strict whole number integer grade 1-10)
        $table_cards = $wpdb->prefix . 'evg_cards';
        $sql_cards = "CREATE TABLE $table_cards (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            submission_id bigint(20) NOT NULL,
            card_name varchar(255) NOT NULL,
            set_name varchar(255) NOT NULL,
            card_number varchar(50) DEFAULT '' NOT NULL,
            language varchar(50) DEFAULT 'English' NOT NULL,
            estimated_condition varchar(50) DEFAULT '' NOT NULL,
            customer_notes text NOT NULL,
            front_image_url varchar(255) DEFAULT '' NOT NULL,
            back_image_url varchar(255) DEFAULT '' NOT NULL,
            final_grade int(11) DEFAULT NULL,
            grading_status varchar(50) DEFAULT 'Pending' NOT NULL,
            transparency_published boolean DEFAULT 0 NOT NULL,
            PRIMARY KEY  (id),
            KEY submission_idx (submission_id),
            KEY status_idx (grading_status)
        ) $charset_collate;";
        dbDelta( $sql_cards );

        // Schema Model 3: Grading Assessment Desk (Centreing, Corners, Edges, Surface)
        $table_assessments = $wpdb->prefix . 'evg_assessments';
        $sql_assessments = "CREATE TABLE $table_assessments (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            card_id bigint(20) NOT NULL,
            grader_id bigint(20) NOT NULL,
            centreing_score decimal(4,2) DEFAULT NULL,
            corner_score decimal(4,2) DEFAULT NULL,
            edge_score decimal(4,2) DEFAULT NULL,
            surface_score decimal(4,2) DEFAULT NULL,
            internal_notes text NOT NULL,
            grader_comments text NOT NULL,
            assessed_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY card_idx (card_id),
            KEY grader_idx (grader_id)
        ) $charset_collate;";
        dbDelta( $sql_assessments );

        // Schema Model 4: Assessment Fault Images (Whitening, Scratches, Print lines, Surface, Corners, Edges)
        $table_faults = $wpdb->prefix . 'evg_fault_images';
        $sql_faults = "CREATE TABLE $table_faults (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            assessment_id bigint(20) NOT NULL,
            fault_type varchar(50) NOT NULL,
            image_url varchar(255) NOT NULL,
            notes text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY assessment_idx (assessment_id)
        ) $charset_collate;";
        dbDelta( $sql_faults );

        // Schema Model 5: Public Marketplace Inventory (Full Card Meta + Slabs)
$table_marketplace = $wpdb->prefix . 'evg_marketplace';
$sql_marketplace = "CREATE TABLE $table_marketplace (
    id bigint(20) NOT NULL AUTO_INCREMENT,
    card_id bigint(20) DEFAULT NULL,
    card_title varchar(255) NOT NULL,
    set_name varchar(255) NOT NULL,
    card_number varchar(50) DEFAULT '' NOT NULL,
    language varchar(50) DEFAULT 'English' NOT NULL,
    grading_company varchar(100) DEFAULT 'Elite Vault Grading' NOT NULL,
    slab_information varchar(255) DEFAULT 'Elite Vault Sealed Protective Slab' NOT NULL,
    assigned_grade int(11) DEFAULT NULL,
    image_url varchar(255) NOT NULL,
    price decimal(10,2) NOT NULL,
    stock_quantity int(11) DEFAULT 1 NOT NULL,
    category varchar(100) DEFAULT 'Elite Vault Graded Cards' NOT NULL,
    status varchar(30) DEFAULT 'Available' NOT NULL,
    listed_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
    PRIMARY KEY  (id),
    KEY card_idx (card_id),
    KEY status_idx (status),
    KEY category_idx (category)
) $charset_collate;";
dbDelta( $sql_marketplace );

        // Schema Model 6: Customer Feedback Form Submissions
        $table_feedback = $wpdb->prefix . 'evg_feedback';
        $sql_feedback = "CREATE TABLE $table_feedback (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            customer_name varchar(255) DEFAULT '' NOT NULL,
            email_address varchar(100) NOT NULL,
            order_number varchar(50) DEFAULT '' NOT NULL,
            feedback_type varchar(50) NOT NULL,
            rating int(1) NOT NULL,
            feedback_text text NOT NULL,
            recommend varchar(10) DEFAULT 'Not sure' NOT NULL,
            permission_to_use boolean DEFAULT 0 NOT NULL,
            status varchar(30) DEFAULT 'Pending' NOT NULL,
            admin_notes text NOT NULL,
            submitted_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY status_idx (status)
        ) $charset_collate;";
        dbDelta( $sql_feedback );

        // Schema Model 7: Security Audit Core Ledger
        $table_audit = $wpdb->prefix . 'evg_audit_logs';
        $sql_audit = "CREATE TABLE $table_audit (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) NOT NULL,
            user_role varchar(50) NOT NULL,
            action_performed text NOT NULL,
            ip_address varchar(45) NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_idx (user_id),
            KEY timestamp_idx (timestamp)
        ) $charset_collate;";
        dbDelta( $sql_audit );

        // --------------------------------------------------------
        // ROLE REGISTRATION: Create EVG Staff Roles on Activation
        // --------------------------------------------------------
        if ( ! get_role( 'support_team' ) ) {
            add_role( 'support_team', __( 'Support Team', 'evg-platform' ), array(
                'read' => true,
            ));
        }

        if ( ! get_role( 'grader' ) ) {
            add_role( 'grader', __( 'Grader', 'evg-platform' ), array(
                'read' => true,
            ));
        }

        if ( ! get_role( 'head_grader' ) ) {
            add_role( 'head_grader', __( 'Head Grader', 'evg-platform' ), array(
                'read' => true,
            ));
        }

        // Initialize Global Settings Defaults
        if ( false === get_option( 'evg_grading_sold_out' ) ) {
            add_option( 'evg_grading_sold_out', 'no' );
        }
        if ( false === get_option( 'evg_transparency_enabled' ) ) {
            add_option( 'evg_transparency_enabled', 'yes' );
        }

        update_option( 'evg_db_version', EVG_CORE_VERSION );
    }

    /**
     * Security Action & Event Logging Engine
     */
    public static function log_activity( $action_description ) {
        global $wpdb;
        $current_user = wp_get_current_user();
        $user_id   = $current_user->exists() ? $current_user->ID : 0;
        $user_role = $current_user->exists() ? implode( ', ', $current_user->roles ) : 'guest';
        
        $ip_address = '0.0.0.0';
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $raw_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
            if ( filter_var( $raw_ip, FILTER_VALIDATE_IP ) ) {
                $ip_address = $raw_ip;
            }
        }

        $wpdb->insert(
            $wpdb->prefix . 'evg_audit_logs',
            array(
                'user_id'          => $user_id,
                'user_role'        => $user_role,
                'action_performed' => sanitize_text_field( $action_description ),
                'ip_address'       => $ip_address,
                'timestamp'        => current_time( 'mysql' )
            ),
            array( '%d', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Data Map Engine for Backend Navigation Modules
     */
    public function get_tabs_config() {
        return array(
            'dashboard' => array(
                'label' => __( 'Dashboard', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 544 512"><path d="M528 0H16C7.2 0 0 7.2 0 16v480c0 8.8 7.2 16 16 16h512c8.8 0 16-7.2 16-16V16c0-8.8-7.2-16-16-16zM272 248v-88c0-4.4 3.6-8 8-8h184c4.4 0 8 3.6 8 8v88c0 4.4-3.6 8-8 8H280c-4.4 0-8-3.6-8-8zm0 176v-88c0-4.4 3.6-8 8-8h184c4.4 0 8 3.6 8 8v88c0 4.4-3.6 8-8 8H280c-4.4 0-8-3.6-8-8zM72 152c0-4.4 3.6-8 8-8h112c4.4 0 8 3.6 8 8v208c0 4.4-3.6 8-8 8H80c-4.4 0-8-3.6-8-8V152z"/></svg>',
                'roles' => array()
            ),
            'submissions' => array(
                'label' => __( 'Submissions & Orders', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M0 64C0 28.7 28.7 0 64 0H224V128c0 17.7 14.3 32 32 32H384V288H216c-13.3 0-24 10.7-24 24s10.7 24 24 24H384V448c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V64zM384 336V288H494.1l-39-39c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l80 80c9.4 9.4 9.4 24.6 0 33.9l-80 80c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l39-39H384zm0-208H256V0L384 128z"/></svg>',
                'roles' => array( 'administrator', 'support_team', 'head_grader' )
            ),
            'grading-desk' => array(
                'label' => __( 'Grading Desk', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M152.1 38.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 115.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 128H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32zM152.1 198.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 275.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 288H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32zM152.1 358.2c9.9 8.9 10.7 24 1.8 33.9l-72 80c-4.8 5.3-11.2 8.1-18.1 7.8s-13.1-3.6-17.5-9L14.4 435.1c-8.2-10-6.8-24.8 3.2-33s24.8-6.8 33 3.2l16 19.5 51.5-57.3c8.9-9.9 24-10.7 33.9-1.8zM416 448H256c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32z"/></svg>',
                'roles' => array( 'administrator', 'head_grader', 'grader' )
            ),
            'quality-control' => array(
                'label' => __( 'Quality Control', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209L241 337c-9.4 9.4-24.6 9.4-33.9 0l-64-64c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l47 47L335 175c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9z"/></svg>',
                'roles' => array( 'administrator', 'head_grader' )
            ),
            'marketplace' => array(
                'label' => __( 'Public Marketplace', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M0 24C0 10.7 10.7 0 24 0H69.5c22 0 41.5 12.8 50.6 32h411c26.3 0 45.5 25 38.6 50.4l-41 152.3c-8.5 31.4-37 53.3-69.5 53.3H170.7l5.4 28.5c2.2 11.3 12.1 19.5 23.6 19.5H488c13.3 0 24 10.7 24 24s-10.7 24-24 24H199.7c-34.6 0-64.3-24.6-70.7-58.5L77.4 54.5c-.7-3.8-4-6.5-7.9-6.5H24C10.7 48 0 37.3 0 24zM128 464a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zm336-48a48 48 0 1 1 0 96 48 48 0 1 1 0-96z"/></svg>',
                'roles' => array( 'administrator', 'support_team' )
            ),
            'customers' => array(
                'label' => __( 'Customer Accounts', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path d="M224 256A128 128 0 1 0 224 0a128 128 0 1 0 0 256zm-45.7 48C79.8 304 0 383.8 0 482.3C0 498.7 13.3 512 29.7 512H322.8c-3.1-8.8-3.7-18.4-1.4-27.8l15-60.1c2.8-11.3 8.6-21.5 16.8-29.7l40.3-40.3c-32.1-31-75.7-50.1-123.9-50.1H178.3z"/></svg>',
                'roles' => array( 'administrator', 'support_team' )
            ),
            'transactions' => array(
                'label' => __( 'Payments & Billing', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path d="M64 64C28.7 64 0 92.7 0 128V384c0 35.3 28.7 64 64 64H512c35.3 0 64-28.7 64-64V128c0-35.3-28.7-64-64-64H64zm64 320H64V320c35.3 0 64 28.7 64 64zM64 192V128h64c0 35.3-28.7 64-64 64zM448 384c0-35.3 28.7-64 64-64v64H448zm64-192c-35.3 0-64-28.7-64-64h64v64zM288 160a96 96 0 1 1 0 192 96 96 0 1 1 0-192z"/></svg>',
                'roles' => array( 'administrator', 'support_team' )
            ),
            'feedback' => array(
                'label' => __( 'Customer Feedback', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M160 368c26.5 0 48 21.5 48 48v16l72.5-54.4c8.3-6.2 18.4-9.6 28.8-9.6H448c8.8 0 16-7.2 16-16V64c0-8.8-7.2-16-16-16H64c-8.8 0-16 7.2-16 16V352c0 8.8 7.2 16 16 16h96zm48 124l-.2 .2-5.1 3.8-17.1 12.8c-4.8 3.6-11.3 4.2-16.8 1.5s-8.8-8.2-8.8-14.3V474.7v-4.5V416H160c-53 0-96-43-96-96V64C64 11 107-32 160-32H448c53 0 96 43 96 96V352c0 53-43 96-96 96H309.3L208 504z"/></svg>',
                'roles' => array( 'administrator', 'support_team' )
            ),
            'settings' => array(
                'label' => __( 'Settings', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M487.4 315.7l-42.6-24.6c2.3-14.2 3.5-28.7 3.5-43.1s-1.2-28.9-3.5-43.1l42.6-24.6c11.5-6.6 15.4-21.3 8.7-32.8L447.5 61.2c-6.6-11.5-21.3-15.4-32.8-8.7L372 77.1c-22.1-14.8-46.7-26.3-72.9-33.8L292.8 12C291.1 5.2 285 0 278.1 0h-44.2c-6.9 0-13 5.2-14.7 12L213 43.3c-26.2 7.5-50.8 19-72.9 33.8l-42.7-24.6c-11.5-6.7-26.2-2.8-32.8 8.7L16.1 147.3c-6.7 11.5-2.8 26.2 8.7 32.8l42.6 24.6c-2.3 14.2-3.5 28.7-3.5 43.1s1.2 28.9 3.5 43.1l-42.6 24.6c-11.5 6.6-15.4 21.3-8.7 32.8l48.6 84.3c6.6 11.5 21.3 15.4 32.8 8.7l42.7-24.6c22.1 14.8 46.7 26.3 72.9 33.8L219.2 500c1.7 6.8 7.8 12 14.7 12h44.2c6.9 0 13-5.2 14.7-12l6.3-31.3c26.2-7.5 50.8-19 72.9-33.8l42.7 24.6c11.5 6.7 26.2 2.8 32.8-8.7l48.6-84.3c6.7-11.5 2.8-26.2-8.7-32.9zM256 336c-44.2 0-80-35.8-80-80s35.8-80 80-80 80 35.8 80 80-35.8 80-80 80z"/></svg>',
                'roles' => array( 'administrator' )
            ),
            'logout' => array(
                'label' => __( 'Log Out', 'evg-platform' ),
                'svg'   => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path d="M160 96c17.7 0 32-14.3 32-32s-14.3-32-32-32H96C43 32 0 75 0 128v256c0 53 43 96 96 96h64c17.7 0 32-14.3 32-32s-14.3-32-32-32H96c-17.7 0-32-14.3-32-32V128c0-17.7 14.3-32 32-32h64zm273 135L313 111c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l123 123H192c-17.7 0-32 14.3-32 32s14.3 32 32 32h198.7L267.7 401.7c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l120-120c12.5-12.5 12.5-32.8 0-45.3z"/></svg>',
                'roles' => array()
            ),
        );
    }

    /**
     * Mount Core Admin Navigation Routing Nodes
     */
    public function mount_core_erp_menu() {
        add_menu_page(
            __( 'Elite Vault Grading', 'evg-platform' ),
            __( 'EVG Database', 'evg-platform' ),
            'read', 
            'evg_management_system',
            array( $this, 'render_dynamic_router_interface' ), 
            'dashicons-shield-alt',
            20
        );

        $tabs = $this->get_tabs_config();

        foreach ( $tabs as $slug => $config ) {
            if ( 'logout' === $slug ) {
                continue;
            }

            $cap = 'read';
            if ( in_array( 'administrator', $config['roles'], true ) ) {
                $cap = 'manage_options';
            }

            add_submenu_page(
                'evg_management_system',
                $config['label'] . ' - ' . __( 'EVG Database', 'evg-platform' ),
                $config['label'],
                $cap,
                'evg_tab_' . $slug,
                function() use ( $slug ) {
                    $_GET['tab'] = $slug;
                    $this->render_dynamic_router_interface();
                }
            );
        }
    }

    /**
     * Dynamic Component View Router Interface
     */
    public function render_dynamic_router_interface() {
        $all_tabs = $this->get_tabs_config();

        $active_tab = 'dashboard';
        if ( isset( $_GET['tab'] ) ) {
            $active_tab = sanitize_key( $_GET['tab'] );
        } elseif ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'evg_tab_' ) === 0 ) {
            $active_tab = str_replace( 'evg_tab_', '', sanitize_key( $_GET['page'] ) );
        }

        if ( ! array_key_exists( $active_tab, $all_tabs ) ) {
            $active_tab = 'dashboard';
        }

        if ( ! self::has_access( $all_tabs[ $active_tab ]['roles'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Access Denied: You do not possess the required privilege level for this module.', 'evg-platform' ) . '</p></div>';
            return;
        }

        $is_print_mode = ( isset( $_GET['action'] ) && 'print' === $_GET['action'] );
        $current_user  = wp_get_current_user();
        $display_name  = $current_user->display_name ? $current_user->display_name : __( 'Staff Member', 'evg-platform' );
        $designation   = !empty($current_user->roles) ? ucfirst($current_user->roles[0]) : __( 'Support', 'evg-platform' );
        ?>

        <div id="evg-wrapper" class="evg-management-system <?php echo $is_print_mode ? 'evg-print' : ''; ?>">
            
            <?php if ( ! $is_print_mode ) : ?>
                <div class="evg-sidebar-container">
                    
                    <div class="evg-author-profile">
                        <div class="profile-avatar">
                            <?php 
                            $default_avatar_url = EVG_CORE_URL . 'assets/img/evg-logo.png'; 
                            echo '<img src="' . esc_url( $default_avatar_url ) . '" alt="' . esc_attr( $display_name ) . '" width="52" height="52" />'; 
                            ?>
                        </div>
                        <div class="profile-meta">
                            <h4 class="profile-name"><?php echo esc_html( $display_name ); ?></h4>
                            <span class="profile-designation"><?php echo esc_html( str_replace('_', ' ', $designation) ); ?></span>
                        </div>
                    </div>

                    <ul class="evg-left-tabs">
                        <?php 
                        foreach ( $all_tabs as $slug => $config ) : 
                            if ( ! self::has_access( $config['roles'] ) ) {
                                continue; 
                            }
                            $active_class = ( $active_tab === $slug ) ? 'active' : '';
                            $target_url   = ( 'logout' === $slug ) ? wp_logout_url( admin_url( 'admin.php?page=evg_management_system' ) ) : admin_url( 'admin.php?page=evg_tab_' . $slug );
                            ?>
                            <li class="<?php echo esc_attr( 'tab-' . $slug ); ?>">
                                <a class="<?php echo esc_attr( $active_class ); ?>" href="<?php echo esc_url( $target_url ); ?>">
                                    <?php echo $config['svg']; ?>
                                    <span><?php echo esc_html( $config['label'] ); ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="evg-right-box">
                <?php
                // Dynamic Modular Function Call
                $callback = 'evg_' . str_replace('-', '_', $active_tab) . '_tab';
                if ( function_exists( $callback ) ) {
                    call_user_func( $callback );
                } else {
                    echo '<div class="evg-card"><h2 style="color:#d4af37;">' . esc_html( $all_tabs[$active_tab]['label'] ) . '</h2><p style="color:#a3a3a3;">' . esc_html__( 'Backend Module Handler is being loaded or initialized.', 'evg-platform' ) . '</p></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Dashboard Layout Interceptor (EVG Dark/Gold Branding)
     */
    public function inject_dashboard_white_label_layout() {
        $screen = get_current_screen();
        if ( $screen && ( strpos( $screen->id, 'evg_management_system' ) !== false || strpos( $screen->id, 'evg_tab_' ) !== false ) ) {
            ?>
            <style>
                #wpadminbar, #adminmenu, #adminmenuback, #adminmenuwrap, #wpfooter { display: none !important; }
                #wpcontent, #wpbody-content { margin-left: 0 !important; padding: 0 !important; width: 100% !important; }
                body.wp-admin { background: #0a0a0a !important; overflow-x: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
                .evg-management-system { display: flex; position: relative; min-height: 100vh; width: 100%; }
                .evg-sidebar-container { 
                    width: 260px; flex-shrink: 0; background: #111111; 
                    border-right: 1px solid #262626; position: sticky; top: 0; height: 100vh; 
                    display: flex; flex-direction: column; box-sizing: border-box; z-index: 99;
                }
                .evg-author-profile { 
                    width: 100%; display: flex; align-items: center; gap: 14px; 
                    padding: 20px 18px; border-bottom: 1px solid #262626; 
                    box-sizing: border-box; flex-shrink: 0; background: #111111;
                }
                .evg-author-profile .profile-avatar img { width: 52px; height: 52px; border-radius: 50%; border: 2px solid #d4af37; object-fit: cover; }
                .evg-author-profile .profile-meta { display: flex; flex-direction: column; gap: 2px; }
                .evg-author-profile .profile-name { margin: 0; font-size: 15px; font-weight: 800; color: #ffffff; }
                .evg-author-profile .profile-designation { font-size: 12px; font-weight: 600; color: #a3a3a3; text-transform: capitalize; }
                .evg-left-tabs { width: 100%; margin: 0; padding: 12px 10px; list-style: none; flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: 4px; box-sizing: border-box; }
                .evg-left-tabs li { margin: 0; }
                .evg-left-tabs li a { 
                    display: flex; align-items: center; gap: 12px; padding: 11px 16px; 
                    color: #a3a3a3; text-decoration: none; font-weight: 600; font-size: 14px; 
                    border-radius: 8px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap;
                }
                .evg-left-tabs li a svg { width: 18px; height: 18px; fill: #a3a3a3; transition: fill 0.2s ease; flex-shrink: 0; }
                .evg-left-tabs li a:hover { background: #222222; color: #d4af37; }
                .evg-left-tabs li a:hover svg { fill: #d4af37; }
                .evg-left-tabs li a.active { background: #d4af37; color: #0a0a0a; font-weight: 700; box-shadow: 0 4px 12px rgba(212, 175, 55, 0.25); }
                .evg-left-tabs li a.active svg { fill: #0a0a0a; }
                .evg-left-tabs li.tab-logout a:hover { background: #3f1a1a; color: #ef4444; }
                .evg-left-tabs li.tab-logout a:hover svg { fill: #ef4444; }
                .evg-right-box { flex: 1; background: #0a0a0a; padding: 32px 36px; min-width: 0; box-sizing: border-box; color: #ffffff; }
                .evg-card { background: #111111; border: 1px solid #262626; border-radius: 8px; padding: 24px; margin-bottom: 24px; }
            </style>
            <?php
        }
    }

    /**
     * Terminate Sessions and Redirect Safely
     */
    public function handle_secure_logout_redirection() {
        wp_safe_redirect( home_url() );
        exit;
    }

    /**
     * Staff Login Redirection
     */
    public function handle_login_redirect( $redirect_to, $request, $user ) {
        if ( isset( $user->roles ) && is_array( $user->roles ) ) {
            $staff_roles = array( 'administrator', 'head_grader', 'grader', 'support_team' );
            if ( array_intersect( $staff_roles, $user->roles ) ) {
                return admin_url( 'admin.php?page=evg_management_system' );
            }
        }
        return $redirect_to;
    }

    /**
     * White-Label Branding Overrides for the Login Form Panel
     */
    public function apply_white_label_login_styles() {
        $custom_logo_url = EVG_CORE_URL . 'assets/img/evg-logo.png';
        ?>
        <style type="text/css">
            #login h1 a, .login h1 a {
                background-image: url('<?php echo esc_url( $custom_logo_url ); ?>') !important;
                height: 90px !important; width: 100% !important; background-size: contain !important;
                background-position: center !important; margin-bottom: 25px !important;
            }
            body.login { background: #0a0a0a !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif !important; }
            #login { padding: 6% 0 0 !important; width: 360px !important; }
            .login form { background: #111111 !important; border: 1px solid #333333 !important; box-shadow: 0 10px 25px rgba(212, 175, 55, 0.1) !important; border-radius: 8px !important; padding: 30px !important; }
            .login label { color: #d4af37 !important; font-weight: 500 !important; }
            .login input[type="text"], .login input[type="password"] { border: 1px solid #444 !important; border-radius: 6px !important; padding: 8px 12px !important; background: #222 !important; color: #fff !important; box-shadow: none !important; }
            .wp-core-ui .button-primary { background: #d4af37 !important; color: #0a0a0a !important; border: none !important; border-radius: 6px !important; font-weight: 700 !important; height: 40px !important; width: 100% !important; margin-top: 15px !important; text-shadow: none !important;}
            .wp-core-ui .button-primary:hover { background: #b8962e !important; }
            .login #backtoblog, .login #nav, .privacy-policy-page-link { display: none !important; }
            .evg-captcha-container { margin: 15px 0; }
            .evg-captcha-label { display: block; margin-bottom: 5px; font-weight: bold; }
        </style>
        <?php
    }

    public function get_login_logo_url() {
        return home_url();
    }

    public function get_login_logo_title() {
        return get_bloginfo( 'name' );
    }

    /**
     * Display Mathematical Security Verification
     */
    public function display_mathematical_captcha() {
        $num1 = rand( 1, 9 );
        $num2 = rand( 1, 9 );
        $captcha_token = md5( uniqid( rand(), true ) );
        set_transient( 'evg_captcha_' . $captcha_token, ( $num1 + $num2 ), 300 );
        ?>
        <div class="evg-captcha-container">
            <label class="evg-captcha-label" for="evg_captcha_answer" style="color: #d4af37;"><?php esc_html_e( 'Security Verification', 'evg-platform' ); ?></label>
            <p style="margin: 0 0 8px 0; color: #a3a3a3; font-size: 13px;">
                <?php printf( esc_html__( 'Please solve: %1$d + %2$d = ?', 'evg-platform' ), $num1, $num2 ); ?>
            </p>
            <input type="text" name="evg_captcha_answer" id="evg_captcha_answer" class="input" value="" size="4" autocomplete="off" required style="background:#222; color:#fff; border:1px solid #444; border-radius:6px;"/>
            <input type="hidden" name="evg_captcha_token" value="<?php echo esc_attr( $captcha_token ); ?>" />
        </div>
        <?php
    }

    /**
     * Validate Mathematical Captcha on Login
     */
    public function validate_mathematical_captcha( $user, $username, $password ) {
        if ( is_wp_error( $user ) || 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['log'] ) ) { 
            return $user; 
        }

        $user_answer = isset( $_POST['evg_captcha_answer'] ) ? sanitize_text_field( $_POST['evg_captcha_answer'] ) : '';
        $token       = isset( $_POST['evg_captcha_token'] ) ? sanitize_text_field( $_POST['evg_captcha_token'] ) : '';
        
        $correct_answer = get_transient( 'evg_captcha_' . $token );
        delete_transient( 'evg_captcha_' . $token );

        if ( false === $correct_answer || intval( $user_answer ) !== intval( $correct_answer ) ) {
            return new WP_Error( 'authentication_failed', __( '<strong>ERROR</strong>: Incorrect security verification answer.', 'evg-platform' ) );
        }
        return $user;
    }
}

// Fire up the Engine Instantiation Loop
Elite_Vault_Grading_System::get_instance();