<?php
/**
 * EVG Module: Public QR Code & Slab Verification Endpoint
 * Handles requests to /verify/?cert=EVG-XXXXX and renders authenticated slab metadata.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

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
        SELECT c.*, a.centreing_score, a.corner_score, a.edge_score, a.surface_score, a.assessed_date, s.order_number, s.label_option
        FROM {$table_cards} c
        LEFT JOIN {$table_assessments} a ON c.id = a.card_id
        LEFT JOIN {$table_submissions} s ON c.submission_id = s.id
        WHERE c.id = %d AND (c.grading_status = 'Completed' OR c.final_grade > 0)
    ", intval( $cleaned_id ) ) );

    return $card_record;
}