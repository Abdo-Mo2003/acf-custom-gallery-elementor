/**
 * ACFGE Custom Gallery Field — Admin JS
 *
 * Handles:
 *  1. Opening the WP media library (multi-select)
 *  2. Appending new thumbnails + hidden inputs
 *  3. Removing individual images
 *  4. Drag-to-reorder via jQuery UI Sortable
 *     (reorders hidden inputs to match visual order)
 */
(function ($) {
    'use strict';

    /* ── Initialise a single gallery field ─────────────────── */
    function initGalleryField( $field ) {
        var $thumbs  = $field.find('.acfge-thumbs');
        var $inputs  = $field.find('.acfge-inputs');
        var $addBtn  = $field.find('.acfge-add-images');
        var preview  = $addBtn.data('preview-size') || 'medium';
        var library  = $addBtn.data('library')      || 'all';
        var inputName = $inputs.find('input').first().attr('name') || '';

        /* Strip trailing [] if present so we can re-add it cleanly */
        inputName = inputName.replace(/\[\]$/, '');

        /* ── Sortable ───────────────────────────────────────── */
        $thumbs.sortable({
            items:       '.acfge-thumb',
            handle:      '.acfge-drag-handle',
            tolerance:   'pointer',
            placeholder: 'acfge-thumb ui-sortable-placeholder',
            forcePlaceholderSize: true,
            start: function ( e, ui ) {
                ui.placeholder.css({
                    width:  ui.helper.outerWidth(),
                    height: ui.helper.outerHeight(),
                });
            },
            update: function () {
                /* Re-sync hidden inputs to match new visual order */
                var orderedIds = [];
                $thumbs.find('.acfge-thumb').each(function () {
                    orderedIds.push( $(this).data('id') );
                });
                syncInputs( $inputs, inputName, orderedIds );
            },
        });

        /* ── Remove button ──────────────────────────────────── */
        $thumbs.on('click', '.acfge-remove', function () {
            var $thumb = $(this).closest('.acfge-thumb');
            var id     = parseInt( $thumb.data('id'), 10 );
            $thumb.remove();

            /* Remove matching hidden input */
            $inputs.find('input[value="' + id + '"]').remove();

            /* If no images left, add the empty placeholder */
            if ( $thumbs.find('.acfge-thumb').length === 0 ) {
                addEmptyPlaceholder( $inputs, inputName );
            }
        });

        /* ── Add Images button → media library ─────────────── */
        $addBtn.on('click', function ( e ) {
            e.preventDefault();

            var frame = wp.media({
                title:    'Select Gallery Images',
                button:   { text: 'Add to Gallery' },
                multiple: true,
                library:  library === 'uploadedTo'
                              ? { uploadedTo: wp.media.view.settings.post.id }
                              : {},
            });

            frame.on('select', function () {
                var selection = frame.state().get('selection');

                /* Remove empty placeholder before adding real images */
                $inputs.find('.acfge-empty-placeholder').remove();

                selection.each(function ( attachment ) {
                    var id  = attachment.get('id');
                    var url = getSizeUrl( attachment, preview );

                    /* Skip duplicates */
                    if ( $thumbs.find('[data-id="' + id + '"]').length ) {
                        return;
                    }

                    /* Append thumbnail */
                    $thumbs.append(
                        '<li class="acfge-thumb" data-id="' + id + '">' +
                            '<img src="' + url + '" alt="">' +
                            '<button type="button" class="acfge-remove" aria-label="Remove image">&#x2715;</button>' +
                            '<span class="acfge-drag-handle" title="Drag to reorder">&#8801;</span>' +
                        '</li>'
                    );

                    /* Append hidden input */
                    $inputs.append(
                        '<input type="hidden" name="' + inputName + '[]" value="' + id + '">'
                    );
                });

                /* Re-enable sortable on newly added items */
                $thumbs.sortable('refresh');
            });

            frame.open();
        });
    }

    /* ── Helper: get a thumbnail URL for a given size ───────── */
    function getSizeUrl( attachment, size ) {
        var sizes = attachment.get('sizes');
        if ( sizes && sizes[size] ) {
            return sizes[size].url;
        }
        /* Fallback chain */
        var fallbacks = ['medium', 'thumbnail', 'full'];
        for ( var i = 0; i < fallbacks.length; i++ ) {
            if ( sizes && sizes[ fallbacks[i] ] ) {
                return sizes[ fallbacks[i] ].url;
            }
        }
        return attachment.get('url');
    }

    /* ── Helper: rebuild hidden inputs from an ordered ID array  */
    function syncInputs( $inputs, name, ids ) {
        $inputs.empty();
        if ( ids.length === 0 ) {
            addEmptyPlaceholder( $inputs, name );
            return;
        }
        $.each( ids, function ( i, id ) {
            $inputs.append(
                '<input type="hidden" name="' + name + '[]" value="' + id + '">'
            );
        });
    }

    /* ── Helper: add the empty placeholder input ─────────────── */
    function addEmptyPlaceholder( $inputs, name ) {
        $inputs.append(
            '<input type="hidden" name="' + name + '[]" value="" class="acfge-empty-placeholder">'
        );
    }

    /* ── Boot: init every gallery field on the page ─────────── */
    function initAll() {
        $('.acfge-gallery-field').each(function () {
            initGalleryField( $(this) );
        });
    }

    /* ACF fires acf/setup_fields after dynamic field appends */
    $(document).on('acf/setup_fields', function () {
        initAll();
    });

    /* Standard page load */
    $(function () {
        initAll();
    });

}(jQuery));
