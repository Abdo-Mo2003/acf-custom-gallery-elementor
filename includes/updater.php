<?php
/**
 * ACFGE_Updater
 *
 * Lightweight self-contained GitHub updater.
 * Uses native PHP rename() instead of WP filesystem
 * to reliably fix the folder name after update.
 */

defined( 'ABSPATH' ) || exit;

class ACFGE_Updater {

    private $plugin_slug;
    private $plugin_file;
    private $plugin_folder;
    private $github_user;
    private $github_repo;
    private $current_version;
    private $api_url;
    private $cache_key;
    private $cache_hours = 12;

    public function __construct( $plugin_file, $github_user, $github_repo, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->plugin_folder   = dirname( plugin_basename( $plugin_file ) );
        $this->github_user     = $github_user;
        $this->github_repo     = $github_repo;
        $this->current_version = $current_version;
        $this->api_url         = "https://api.github.com/repos/{$github_user}/{$github_repo}/releases/latest";
        $this->cache_key       = 'acfge_update_' . md5( $this->api_url );

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
        add_filter( 'plugins_api',                           [ $this, 'plugin_info' ], 20, 3 );

        // This fires after the zip is extracted, before files are moved
        // Using PHP rename() directly — most reliable approach
        add_filter( 'upgrader_source_selection', [ $this, 'fix_source_folder' ], 10, 4 );
    }

    /* ── Fetch latest release info from GitHub ──────────────── */

    private function get_release_info() {
        $cached = get_transient( $this->cache_key );
        if ( $cached !== false ) return $cached;

        $response = wp_remote_get( $this->api_url, [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
            'timeout' => 10,
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $data->tag_name ) ) {
            set_transient( $this->cache_key, null, HOUR_IN_SECONDS );
            return null;
        }

        set_transient( $this->cache_key, $data, $this->cache_hours * HOUR_IN_SECONDS );
        return $data;
    }

    /* ── Get zip URL ────────────────────────────────────────── */

    private function get_zip_url( $release ) {
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( substr( $asset->name, -4 ) === '.zip' ) {
                    return $asset->browser_download_url;
                }
            }
        }
        return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release->tag_name}.zip";
    }

    /* ── Clean version string ────────────────────────────────── */

    private function clean_version( $version ) {
        return ltrim( $version, 'vV' );
    }

    /* ── Hook: inject update into WordPress transient ───────── */

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $release = $this->get_release_info();
        if ( empty( $release->tag_name ) ) return $transient;

        $latest = $this->clean_version( $release->tag_name );

        if ( version_compare( $latest, $this->current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => $this->plugin_folder,
                'plugin'      => $this->plugin_slug,
                'new_version' => $latest,
                'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'     => $this->get_zip_url( $release ),
                'tested'      => get_bloginfo( 'version' ),
                'icons'       => [],
                'banners'     => [],
            ];
        }

        return $transient;
    }

    /* ── Hook: plugin info popup ────────────────────────────── */

    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_folder ) return $result;

        $release = $this->get_release_info();
        if ( empty( $release ) ) return $result;

        return (object) [
            'name'          => 'ACF Custom Gallery for Elementor',
            'slug'          => $this->plugin_folder,
            'version'       => $this->clean_version( $release->tag_name ),
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

    /* ── Hook: rename extracted folder using PHP rename() ───── */
    /*
     * WordPress extracts the zip to a temp folder first.
     * We use native PHP rename() here — much more reliable
     * than $wp_filesystem->move() which needs credentials.
     *
     * The extracted folder might be named:
     *   acf-custom-gallery-elementor         (correct — do nothing)
     *   acf-custom-gallery-elementor-main    (GitHub Code > Download ZIP)
     *   acf-custom-gallery-elementor-1.2.0   (some zip tools)
     *
     * We rename whatever it is to the correct folder name.
     */
    public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = [] ) {

        // Only act on our plugin
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $source;
        }

        $correct_source = trailingslashit( $remote_source ) . $this->plugin_folder . '/';

        // Already correct — nothing to do
        if ( trailingslashit( $source ) === $correct_source ) {
            return $source;
        }

        // Use native PHP rename — works without WP filesystem credentials
        if ( @rename( $source, $correct_source ) ) {
            return $correct_source;
        }

        // rename() failed (possibly cross-device) — try copy + delete
        if ( $this->copy_dir( $source, $correct_source ) ) {
            $this->delete_dir( $source );
            return $correct_source;
        }

        // All else failed — return original and let WordPress handle it
        return $source;
    }

    /* ── Helper: recursively copy a directory ───────────────── */

    private function copy_dir( $src, $dst ) {
        if ( ! @mkdir( $dst, 0755, true ) && ! is_dir( $dst ) ) {
            return false;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $src, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ( $iterator as $item ) {
            $target = $dst . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ( $item->isDir() ) {
                @mkdir( $target, 0755, true );
            } else {
                @copy( $item->getPathname(), $target );
            }
        }
        return true;
    }

    /* ── Helper: recursively delete a directory ─────────────── */

    private function delete_dir( $dir ) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $iterator as $item ) {
            $item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
        }
        @rmdir( $dir );
    }

    /* ── Clear update cache ──────────────────────────────────── */

    public static function clear_cache() {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_acfge_update_%'"
        );
    }
}
