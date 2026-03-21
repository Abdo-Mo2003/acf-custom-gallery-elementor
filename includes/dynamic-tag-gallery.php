<?php
/**
 * ACFGE_Dynamic_Tag_Gallery
 *
 * Reads any "Gallery (Free)" ACF field and returns
 * [ ['id' => 123], ['id' => 456], … ] — compatible with
 * Elementor Basic Gallery (free) and Gallery (Pro).
 */

defined( 'ABSPATH' ) || exit;

use Elementor\Core\DynamicTags\Data_Tag;
use Elementor\Modules\DynamicTags\Module as TagsModule;

class ACFGE_Dynamic_Tag_Gallery extends Data_Tag {

    public function get_name()  { return 'acfge-gallery'; }
    public function get_title() { return __( 'ACF Gallery', 'acf-gallery-elementor' ); }
    public function get_group() { return 'acfge'; }

    public function get_categories() {
        return [ TagsModule::GALLERY_CATEGORY ];
    }

    /* ── Controls ───────────────────────────────────────────── */

    protected function register_controls() {

        $choices = $this->get_gallery_field_choices();

        if ( ! empty( $choices ) ) {
            $this->add_control( 'acf_field_key', [
                'label'       => __( 'Gallery Field', 'acf-gallery-elementor' ),
                'type'        => \Elementor\Controls_Manager::SELECT,
                'options'     => $choices,
                'default'     => array_key_first( $choices ),
                'description' => __( 'Select the ACF gallery field to use.', 'acf-gallery-elementor' ),
            ] );
        } else {
            $this->add_control( 'acf_field_key', [
                'label'       => __( 'Field Name', 'acf-gallery-elementor' ),
                'type'        => \Elementor\Controls_Manager::TEXT,
                'default'     => '',
                'placeholder' => 'e.g. project_images',
                'description' => __( 'Enter the ACF gallery field name exactly as set in your field group.', 'acf-gallery-elementor' ),
            ] );
        }

        $this->add_control( 'post_id_override', [
            'label'       => __( 'Post ID (leave blank for current post)', 'acf-gallery-elementor' ),
            'type'        => \Elementor\Controls_Manager::NUMBER,
            'placeholder' => get_the_ID(),
        ] );
    }

    /* ── Auto-discover all acfge_gallery fields ─────────────── */

    private function get_gallery_field_choices() {
        $choices = [];
        if ( ! function_exists( 'acf_get_field_groups' ) ) return $choices;

        foreach ( acf_get_field_groups() as $group ) {
            $fields = acf_get_fields( $group['key'] );
            if ( empty( $fields ) ) continue;
            foreach ( $fields as $field ) {
                if ( $field['type'] === 'acfge_gallery' ) {
                    $choices[ $field['name'] ] = $field['label'] . ' (' . $field['name'] . ')';
                }
            }
        }
        return $choices;
    }

    /* ── Value ──────────────────────────────────────────────── */

    public function get_value( array $options = [] ) {
        if ( ! function_exists( 'get_field' ) ) return [];

        $field_name = trim( $this->get_settings( 'acf_field_key' ) );
        $post_id    = (int) ( $this->get_settings( 'post_id_override' ) ?: get_the_ID() );

        if ( empty( $field_name ) || $post_id <= 0 ) return [];

        $value = get_field( $field_name, $post_id );

        if ( empty( $value ) || ! is_array( $value ) ) {
            // Fallback: read raw post meta directly
            $raw = get_post_meta( $post_id, $field_name, true );
            if ( empty( $raw ) ) return [];
            $raw = is_array( $raw ) ? $raw : maybe_unserialize( $raw );
            if ( ! is_array( $raw ) ) return [];
            $gallery = [];
            foreach ( $raw as $id ) {
                $id = (int) $id;
                if ( $id > 0 ) $gallery[] = [ 'id' => $id ];
            }
            return $gallery;
        }

        $gallery = [];
        foreach ( $value as $item ) {
            if ( is_array( $item ) && ! empty( $item['id'] ) ) {
                $gallery[] = [ 'id' => (int) $item['id'] ];
            } elseif ( is_numeric( $item ) ) {
                $gallery[] = [ 'id' => (int) $item ];
            }
        }
        return $gallery;
    }
}
