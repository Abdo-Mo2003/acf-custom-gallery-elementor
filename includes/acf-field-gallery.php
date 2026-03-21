<?php
/**
 * ACFGE_Field_Gallery
 *
 * Custom ACF field type — "Gallery (Free)"
 * Compatible with ACF 5.x and 6.x (free versions).
 */

defined( 'ABSPATH' ) || exit;

// Guard: only define if ACF base class exists
if ( ! class_exists( 'acf_field' ) ) return;

if ( ! class_exists( 'ACFGE_Field_Gallery' ) ) :

class ACFGE_Field_Gallery extends acf_field {

    /* ── 1. FIELD IDENTITY ──────────────────────────────────── */

    public function initialize() {
        $this->name     = 'acfge_gallery';
        $this->label    = __( 'Gallery (Free)', 'acf-gallery-elementor' );
        $this->category = 'content';
        $this->defaults = [
            'preview_size' => 'medium',
            'library'      => 'all',
        ];
    }

    // ACF 5.x compatibility — calls initialize() then parent
    public function __construct() {
        $this->initialize();
        parent::__construct();
    }


    /* ── 2. FIELD SETTINGS (field group builder) ────────────── */

    public function render_field_settings( $field ) {

        acf_render_field_setting( $field, [
            'label'   => __( 'Preview size', 'acf-gallery-elementor' ),
            'type'    => 'select',
            'name'    => 'preview_size',
            'choices' => acf_get_image_sizes(),
        ] );

        acf_render_field_setting( $field, [
            'label'   => __( 'Library', 'acf-gallery-elementor' ),
            'type'    => 'radio',
            'name'    => 'library',
            'layout'  => 'horizontal',
            'choices' => [
                'all'        => __( 'All', 'acf-gallery-elementor' ),
                'uploadedTo' => __( 'Uploaded to post', 'acf-gallery-elementor' ),
            ],
        ] );
    }


    /* ── 3. RENDER THE FIELD (post edit screen) ─────────────── */

    public function render_field( $field ) {
        $field_id   = ! empty( $field['id'] ) ? $field['id'] : $field['key'];
        $input_name = $field['name'];
        $items      = is_array( $field['value'] ) ? array_filter( array_map( 'intval', $field['value'] ) ) : [];
        $preview    = ! empty( $field['preview_size'] ) ? $field['preview_size'] : 'medium';
        $library    = ! empty( $field['library'] )      ? $field['library']      : 'all';
        ?>
        <div class="acfge-gallery-field" id="acfge-<?php echo esc_attr( $field_id ); ?>">

            <div class="acfge-inputs">
                <?php foreach ( $items as $id ) : ?>
                    <input type="hidden"
                           name="<?php echo esc_attr( $input_name ); ?>[]"
                           value="<?php echo (int) $id; ?>">
                <?php endforeach; ?>
                <?php if ( empty( $items ) ) : ?>
                    <input type="hidden"
                           name="<?php echo esc_attr( $input_name ); ?>[]"
                           value=""
                           class="acfge-empty-placeholder">
                <?php endif; ?>
            </div>

            <ul class="acfge-thumbs">
                <?php foreach ( $items as $id ) :
                    $src = wp_get_attachment_image_url( $id, $preview ) ?: '';
                    $alt = esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) );
                    ?>
                    <li class="acfge-thumb" data-id="<?php echo (int) $id; ?>">
                        <img src="<?php echo esc_url( $src ); ?>" alt="<?php echo $alt; ?>">
                        <button type="button" class="acfge-remove" aria-label="Remove">&#x2715;</button>
                        <span class="acfge-drag-handle" title="Drag to reorder">&#8801;</span>
                    </li>
                <?php endforeach; ?>
            </ul>

            <button type="button"
                    class="button acfge-add-images"
                    data-field-id="<?php echo esc_attr( $field_id ); ?>"
                    data-preview-size="<?php echo esc_attr( $preview ); ?>"
                    data-library="<?php echo esc_attr( $library ); ?>">
                <?php _e( '+ Add Images', 'acf-gallery-elementor' ); ?>
            </button>

        </div>
        <?php
    }


    /* ── 4. ENQUEUE ADMIN ASSETS ────────────────────────────── */

    public function input_admin_enqueue_scripts() {
        wp_enqueue_media();
        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_enqueue_style(
            'acfge-field-admin',
            ACFGE_URL . 'assets/field-admin.css',
            [],
            '3.1.0'
        );
        wp_enqueue_script(
            'acfge-field-admin',
            ACFGE_URL . 'assets/field-admin.js',
            [ 'jquery', 'jquery-ui-sortable', 'media-editor' ],
            '3.1.0',
            true
        );
    }


    /* ── 5. LOAD VALUE ──────────────────────────────────────── */

    public function load_value( $value, $post_id, $field ) {
        if ( empty( $value ) ) return [];
        if ( is_string( $value ) ) $value = maybe_unserialize( $value );
        if ( ! is_array( $value ) ) $value = explode( ',', $value );
        return array_values( array_filter( array_map( 'intval', $value ) ) );
    }


    /* ── 6. UPDATE VALUE ────────────────────────────────────── */

    public function update_value( $value, $post_id, $field ) {
        if ( empty( $value ) || ! is_array( $value ) ) return [];
        return array_values( array_filter( array_map( 'intval', $value ) ) );
    }


    /* ── 7. FORMAT VALUE (returned by get_field()) ──────────── */

    public function format_value( $value, $post_id, $field ) {
        if ( empty( $value ) || ! is_array( $value ) ) return [];

        $formatted = [];
        foreach ( $value as $id ) {
            $id = (int) $id;
            if ( $id <= 0 ) continue;
            $img = wp_prepare_attachment_for_js( $id );
            if ( ! $img ) continue;

            $sizes = [];
            if ( ! empty( $img['sizes'] ) ) {
                foreach ( $img['sizes'] as $size_name => $size_data ) {
                    $sizes[ $size_name ] = [
                        'url'    => $size_data['url'],
                        'width'  => $size_data['width'],
                        'height' => $size_data['height'],
                    ];
                }
            }

            $formatted[] = [
                'ID'          => $id,
                'id'          => $id,
                'title'       => $img['title']       ?? '',
                'filename'    => $img['filename']    ?? '',
                'url'         => $img['url']         ?? '',
                'link'        => $img['link']        ?? '',
                'alt'         => $img['alt']         ?? '',
                'caption'     => $img['caption']     ?? '',
                'description' => $img['description'] ?? '',
                'mime_type'   => $img['mime']        ?? '',
                'width'       => $img['width']       ?? 0,
                'height'      => $img['height']      ?? 0,
                'sizes'       => $sizes,
            ];
        }
        return $formatted;
    }
}

endif;
