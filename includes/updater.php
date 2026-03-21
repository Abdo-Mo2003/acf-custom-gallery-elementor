<?php
/**
 * ACFGE_Updater
 *
 * Lightweight self-contained GitHub updater.
 * No external libraries needed.
 *
 * How it works:
 *  - Checks the GitHub releases API for a newer version tag
 *  - If found, hooks into WordPress update system
 *  - WordPress shows the standard "Update Available" notice
 *  - User clicks Update — WordPress downloads the release zip
 */

defined( 'ABSPATH' ) || exit;

class ACFGE_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $github_user;
    private $github_repo;
    private $current_version;
    private $api_url;
    private $cache_key;
    private $cache_hours = 12; // check for updates every 12 hours

    public function __construct( $plugin_file, $github_user, $github_repo, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->github_user     = $github_user;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;
        $this->api_url         = "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest";
        $this->cache_key       = 'acfge_update_' . md5( $this->api_url );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );
        add_filter( 'upgrader_post_install',                 [ $this, 'after_install' ], 10, 3 );
    }

    /* ── Fetch latest release info from GitHub ──────────────── */

    private function get_release_info() {
        // Return cached result if fresh
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) {
            return $cached;
        }

        $response = wp_remote_get( $this->api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            // Cache failure for 1 hour to avoid hammering the API
            set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $data->tag_name ) ) {
            set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        // Cache for 12 hours
        set_transient( $this->cache_key, $data, $this->cache_hours * HOUR_IN_SECONDS );

        return $data;
    }

    /* ── Get the zip URL from the release assets ────────────── */

    private function get_zip_url( $release ) {
        // First look for an attached asset zip (your manually uploaded zip)
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( substr( $asset->name, -4 ) === '.zip' ) {
                    return $asset->browser_download_url;
                }
            }
        }
        // Fallback: GitHub's auto-generated source zip
        return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release->tag_name}.zip";
    }

    /* ── Clean version string (remove leading 'v') ──────────── */

    private function clean_version( $version ) {
        return ltrim( $version, 'vV' );
    }

    /* ── Hook: check if update is available ─────────────────── */

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_release_info();

        if ( empty( $release->tag_name ) ) {
            return $transient;
        }

        $latest_version = $this->clean_version( $release->tag_name );

        if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $latest_version,
                'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'     => $this->get_zip_url( $release ),
                'tested'      => get_bloginfo( 'version' ),
                'icons'       => [],
                'banners'     => [],
            ];
        }

        return $transient;
    }

    /* ── Hook: show plugin info in the update details popup ─── */

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_release_info();
        if ( empty( $release ) ) {
            return $result;
        }

        $latest_version = $this->clean_version( $release->tag_name );

        return (object) [
            'name'          => 'ACF Custom Gallery for Elementor',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $latest_version,
            'author'        => $this->github_user,
            'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'download_link' => $this->get_zip_url( $release ),
            'sections'      => [
                'description' => $release->body ?? 'ACF Custom Gallery field type for Elementor.',
                'changelog'   => $release->body ?? '',
            ],
            'last_updated'  => $release->published_at ?? '',
            'tested'        => get_bloginfo( 'version' ),
        ];
    }

    /* ── Hook: rename folder after install ───────────────────── */
    /*
     * GitHub zips extract to "repo-name-v1.0.0/" but WordPress
     * expects the folder to match the plugin slug. This renames it.
     */
    public function after_install( $response, $hook_extra, $result ) {
        global $wp_filesystem;

        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }

        $install_path    = $result['destination'];
        $correct_path    = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );

        if ( $install_path !== $correct_path ) {
            $wp_filesystem->move( $install_path, $correct_path, true );
            $result['destination'] = $correct_path;
        }

        // Re-activate the plugin after update
        activate_plugin( $this->plugin_slug );

        return $result;
    }

    /* ── Clear cache (call this after you publish a release) ── */

    public static function clear_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acfge_update_%'"
        );
    }
}
