<?php
/**
 * Plugin Name: ACF Custom Gallery for Elementor
 * Description: Adds a "Gallery (Free)" ACF field type with drag-to-reorder, compatible with Elementor Basic Gallery (free) and Gallery (Pro) via a Dynamic Tag.
 * Version: 1.1.0
 * Author: Abdo-Mo2003
 * Author URI: https://github.com/Abdo-Mo2003
 * Text Domain: acf-gallery-elementor
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'ACFGE_PATH',    plugin_dir_path( __FILE__ ) );
define( 'ACFGE_URL',     plugin_dir_url( __FILE__ ) );
define( 'ACFGE_VERSION', '1.1.0' );

/* ---------------------------------------------------------------
 * 1. AUTO-UPDATES — built-in GitHub updater (no library needed)
 *    Just update the version number and publish a new GitHub
 *    Release — WordPress will notify all users automatically.
 * ------------------------------------------------------------- */
add_action( 'init', 'acfge_setup_updater', 5 );
function acfge_setup_updater() {
    // Only run in wp-admin
    if ( ! is_admin() ) return;

    require_once ACFGE_PATH . 'includes/updater.php';

    new ACFGE_Updater(
        __FILE__,
        'Abdo-Mo2003',                       // ← your GitHub username
        'acf-custom-gallery-elementor',      // ← your GitHub repo name
        ACFGE_VERSION
    );
}


/* ---------------------------------------------------------------
 * 2. REGISTER CUSTOM ACF FIELD TYPE
 * ------------------------------------------------------------- */
add_action( 'acf/include_field_types', 'acfge_register_field_type' );
add_action( 'acf/register_fields',     'acfge_register_field_type' );
add_action( 'init',                    'acfge_register_field_type', 20 );

function acfge_register_field_type() {
    static $done = false;
    if ( $done ) return;
    if ( ! function_exists( 'acf_register_field_type' ) && ! function_exists( 'acf' ) ) return;

    require_once ACFGE_PATH . 'includes/acf-field-gallery.php';

    if ( function_exists( 'acf_register_field_type' ) ) {
        acf_register_field_type( 'ACFGE_Field_Gallery' );
    } elseif ( function_exists( 'acf' ) ) {
        new ACFGE_Field_Gallery();
    }

    $done = true;
}


/* ---------------------------------------------------------------
 * 3. REGISTER ELEMENTOR DYNAMIC TAG
 * ------------------------------------------------------------- */
add_action( 'elementor/dynamic_tags/register', 'acfge_register_dynamic_tags' );
function acfge_register_dynamic_tags( $dynamic_tags_manager ) {
    require_once ACFGE_PATH . 'includes/dynamic-tag-gallery.php';

    $dynamic_tags_manager->register_group( 'acfge', [
        'title' => 'ACF Gallery',
    ] );

    $dynamic_tags_manager->register( new ACFGE_Dynamic_Tag_Gallery() );
}
