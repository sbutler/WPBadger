<?php
/**
 * Badge custom post type.
 *
 * @package wpbadger
 */

/**
 * Implements all the filters and actions needed to make the badge
 * custom post type work.
 */
class WPBadger_Badge_Schema
{
    /** Capability type to use when registering the custom post type. */
    private $post_capability_type;
    /** Name to use when registering the custom post type. */
    private $post_type_name;

    /**
     * Constructs the WPBadger Badge Schema instance. It registers all the hooks
     * needed to support the custom post type. This should only be called once.
     */
    function __construct()
    {
		add_action( 'init', array( $this, 'init' ) );

        add_action( 'load-post.php', array( $this, 'meta_boxes_setup' ) );
        add_action( 'load-post-new.php', array( $this, 'meta_boxes_setup' ) );

        /* Filter the content of the badge post type in the display, so badge metadata
           including badge image are displayed on the page. */
        add_filter( 'the_content', array( $this, 'content_filter' ) );

        /* Filter the title of a badge post type in its display to include version */
        add_filter( 'the_title', array( $this, 'title_filter' ), 10, 3 );

        add_action( 'save_post', array( $this, 'save_post_validate' ), 99, 2 );
        add_filter( 'display_post_states', array( $this, 'display_post_states' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );

        add_filter( 'manage_badge_posts_columns', array( $this, 'manage_posts_columns' ), 10 );
        add_action( 'manage_badge_posts_custom_column', array( $this, 'manage_posts_custom_column' ), 10, 2 );

        /* Support for OpenBadges.me badge designer API */
        add_filter( 'admin_post_thumbnail_html', array( $this, 'admin_post_thumbnail_html' ), 10, 2 );
        add_action( 'wp_ajax_wpbadger_badge_designer_publish', array( $this, 'ajax_badge_designer_publish' ) );
	}

    // Accessors and Mutators

    public function get_post_capability_type()
    {
        return $this->post_capability_type;
    }

    public function get_post_type_name()
    {
		return $this->post_type_name;
	}

    private function set_post_capability_type( $new_val = 'post' )
    {
        $this->post_capability_type = apply_filters( 'wpbadger_badge_post_capability_type', $new_val );
    }

    private function set_post_type_name( $new_val = 'badge' )
    {
		$this->post_type_name = apply_filters( 'wpbadger_badge_post_type_name', $new_val );
	}

    // General Filters and Actions

    /**
     * Initialize the custom post type. This registers what we need to
     * support the Badge type.
     */
    function init()
    {
        $this->set_post_type_name();
        $this->set_post_capability_type();

		$labels = array(
			'name'              => _x( 'Badges', 'post type general name', 'wpbadger' ),
			'singular_name'     => _x( 'Badge', 'post type singular name', 'wpbadger' ),
			'add_new'           => _x( 'Add New', 'badge', 'wpbadger' ),
			'add_new_item'      => __( 'Add New Badge', 'wpbadger' ),
			'edit_item'         => __( 'Edit Badge', 'wpbadger' ),
			'new_item'          => __( 'New Badge', 'wpbadger' ),
			'all_items'         => __( 'All Badges', 'wpbadger' ),
			'view_item'         => __( 'View Badge', 'wpbadger' ),
			'search_items'      => __( 'Search Badges', 'wpbadger' ),
			'not_found'         => __( 'No badges found', 'wpbadger' ),
			'not_found_in_trash' => __( 'No badges found in Trash', 'wpbadger' ),
			'parent_item_colon' => '',
			'menu_name'         => __( 'Badges', 'wpbadger' )
		);

		$args = array(
			'labels'            => $labels,
			'public'            => true,
			'query_var'         => true,
			'rewrite' => array(
				'slug'          => 'badges',
				'with_front'    => false,
			),
			'capability_type'   => $this->get_post_capability_type(),
			'has_archive'       => true,
			'hierarchical'      => false,
            'supports'          => array( 'title', 'editor', 'thumbnail' ),
            'taxonomies'        => array( 'category' )
		);

		register_post_type( $this->get_post_type_name(), $args );

        # Actions and filters that depend on the post_type name, so can't run
        # until here
	}
    
    // Loop Filters and Actions
    
    /**
     * Adds the badge image to the content when we are in The Loop.
     */
    function content_filter( $content )
    {
        if (get_post_type() == $this->get_post_type_name() && in_the_loop())
            return '<p>' . get_the_post_thumbnail( get_the_ID(), 'thumbnail', array( 'class' => 'alignright' ) ) . $content . '</p>';
        else
            return $content;
    }

    /**
     * Adds the badge version to the title when we are in The Loop.
     */
    function title_filter( $title )
    {
        if (get_post_type() == $this->get_post_type_name() && in_the_loop())
            return $title . ' (Version ' . get_post_meta( get_the_ID(), 'wpbadger-badge-version', true ) . ')';
        else
            return $title;
    }

    // Admin Filters and Actions

    /**
     * Display admin notices about invalid posts.
     */
    function admin_notices()
    {
        global $pagenow, $post;

        if ($pagenow != 'post.php')
            return;
        if (empty( $post ) || ($post->post_type != $this->get_post_type_name()))
            return;
        if ($post->post_status != 'publish')
            return;

        $valid = $this->check_valid( $post->ID, $post );

        if (!$valid[ 'image' ])
            echo '<div class="error"><p>'.__("You must set a badge image.", 'wpbadger').'</p></div>';
        if (!$valid[ 'image-png' ])
            echo '<div class="error"><p>'.__("You must set a badge image that is a PNG file.", 'wpbadger').'</p></div>';
        if (!$valid[ 'description' ])
            echo '<div class="error"><p>'.__("You must enter a badge description.", 'wpbadger').'</p></div>';
        if (!$valid[ 'description-length' ])
            echo '<div class="error"><p>'.__("The description cannot be longer than 128 characters.", 'wpbadger').'</p></div>';
        if (!$valid[ 'criteria' ])
            echo '<div class="error"><p>'.__("You must enter the badge criteria.", 'wpbadger').'</p></div>';
    }

    /**
     * For badge pages, change the feature image content to add the designer. This
     * gets called as a filter by WP whenever the content inside the postimagediv
     * needs to be updated (even via AJAX).
     */
    function admin_post_thumbnail_html( $content, $post_id )
    {
        global $user_email, $content_width, $_wp_additional_image_sizes;

        if (get_post_type( $post_id ) != $this->get_post_type_name())
            return $content;

        /* if we already have a thumbnail, don't modify the content, just return */
        if (has_post_thumbnail( $post_id ))
            return $content;

        /* Copied from wp-admin/includes/post.php */
        $upload_iframe_src = esc_url( get_upload_iframe_src( 'image', $post_id ) );
        $media_manager_link = sprintf( '<a title="%2$s" href="%1$s" id="set-post-thumbnail" class="thickbox">%3$s</a>',
            $upload_iframe_src,
            esc_attr__( 'Media Library' ),
            esc_html__( 'Media Library' )
        );

        $designer_link = sprintf( '<a href="https://www.openbadges.me/designer.html?format=json&amp;origin=%3$s&amp;email=%4$s" title="%1$s" id="wpbadger-badge-designer" data-post-id="%5$d" data-nonce="%6$s">%2$s</a>',
            esc_attr__( 'Badge Designer', 'wpbadger' ),
            esc_html__( 'Badge Designer', 'wpbadger' ),
            urlencode( get_site_url() ),
            urlencode( $user_email ),
            $post_id,
            esc_attr( wp_create_nonce( 'wpbadger-badge-designer' ) )
        );

        return <<<EOHTML
<p class="hide-if-no-js">Use the {$media_manager_link} to upload or select an existing image. Or use
the {$designer_link} to create a new one.</p>
EOHTML
        ;
    }

    /**
     * Deletes a temporary file as part of the shutdown of a request.
     */
    function _ajax_delete_tmpfile( $file )
    {
        @unlink( $file );
    }

    /**
     * Handle a media upload that came from AJAX, but was never present in $_FILES.
     * This is here because the original relies on move_upload_file(), and that won't
     * work for us.
     *
     * Copied from wp-admin/includes/media.php media_handle_upload.
     */
    function _ajax_media_handle_upload( $_f, $post_id, $post_data = array(), $overrides = array( 'test_form' => false ) )
    {
        $time = current_time( 'mysql' );
        if ($post = get_post( $post_id ))
        {
            if (substr( $post->post_date, 0, 4 ) > 0)
                $time = $post->post_date;
        }

        $name = $_f[ 'name' ];
        $file = $this->_ajax_wp_handle_upload( $_f, $overrides, $time );

        if (isset( $file[ 'error' ] ))
            return new WP_Error( 'upload_error', $file[ 'error' ] );

        $name_parts = pathinfo( $name );
        $name = trim( substr( $name, 0, -(1 + strlen( $name_parts[ 'extension' ] )) ) );

        $url = $file[ 'url' ];
        $type = $file[ 'type' ];
        $file = $file[ 'file' ];
        $title = $name;
        $content = '';

        if ($image_meta = @wp_read_image_metadata( $file ))
        {
            if (trim( $image_meta[ 'title' ] ) && !is_numeric( sanitize_title( $image_meta[ 'title' ] ) ))
                $title = $image_meta[ 'title' ];
            if (trim( $image_meta[ 'caption' ] ))
                $content = $image_meta[ 'caption' ];
        }

        // Construct the attachment array
        $attachment = array_merge( array(
            'post_mime_type' => $type,
            'guid' => $url,
            'post_parent' => $post_id,
            'post_title' => $title,
            'post_content' => $content,
        ), $post_data );

        // This should never be set as it would then overwrite an existing attachment.
        if (isset( $attachment[ 'ID' ] ))
            unset( $attachment[ 'ID' ] );

        // Save the data
        $id = wp_insert_attachment( $attachment, $file, $post_id );
        if (!is_wp_error( $id ))
            wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );

        return $id;
    }

    /**
     * Handle errors inside of _ajax_wp_handle_upload. Just returns an array with the
     * error message.
     */
    function _ajax_wp_handle_upload_error( &$file, $message )
    {
        return array( 'error' => $message );
    }

    /**
     * Handle an upload that came from AJAX, but was never present in $_FILES.
     * This is here because the original relies on move_upload_file(), and that won't
     * work for us.
     *
     * Copied from wp-admin/includes/file.php wp_handle_upload.
     */
    function _ajax_wp_handle_upload( &$file, $overrides = false, $time = null )
    {
        $file = apply_filters( 'wp_handle_upload_prefilter', $file );

        // You may define your own function and pass the name in $overrides['upload_error_handler']
        $upload_error_handler = array( $this, '_ajax_wp_handle_upload_error' );

        // You may have had one or more 'wp_handle_upload_prefilter' functions error out the file. Handle that gracefully.
        if (isset( $file[ 'error' ] ) && !is_numeric( $file[ 'error' ] ) && $file[ 'error' ])
            return call_user_func( $upload_error_handler, $file, $file['error'] );
        
        // You may define your own function and pass the name in $overrides['unique_filename_callback']
        $unique_filename_callback = null;

        // $_POST['action'] must be set and its value must equal $overrides['action'] or this:
        $action = 'wp_handle_upload';

        // Courtesy of php.net, the strings that describe the error indicated in $_FILES[{form field}]['error'].
        $upload_error_strings = array( false,
            __( "The uploaded file exceeds the upload_max_filesize directive in php.ini." ),
            __( "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form." ),
            __( "The uploaded file was only partially uploaded." ),
            __( "No file was uploaded." ),
            '',
            __( "Missing a temporary folder." ),
            __( "Failed to write file to disk." ),
            __( "File upload stopped by extension." ));

        // All tests are on by default. Most can be turned off by $overrides[{test_name}] = false;
        $test_form = true;
        $test_size = true;
        $test_upload = true;

        // If you override this, you must provide $ext and $type!!!!
        $test_type = true;
        $mimes = false;

        // Install user overrides. Did we mention that this voids your warranty?
        if ( is_array( $overrides ) )
            extract( $overrides, EXTR_OVERWRITE );

        // A correct form post will pass this test.
        if ($test_form && (!isset( $_POST[ 'action' ] ) || ($_POST[ 'action' ] != $action)))
            return call_user_func( $upload_error_handler, $file, __( 'Invalid form submission.' ) );

        // A successful upload will pass this test. It makes no sense to override this one.
        if ($file[ 'error' ] > 0)
            return call_user_func( $upload_error_handler, $file, $upload_error_strings[ $file[ 'error' ] ] );

        // A non-empty file will pass this test.
        if ($test_size && !($file[ 'size' ] > 0 ))
        {
            if (is_multisite())
                $error_msg = __( 'File is empty. Please upload something more substantial.' );
            else
                $error_msg = __( 'File is empty. Please upload something more substantial. This error could also be caused by uploads being disabled in your php.ini or by post_max_size being defined as smaller than upload_max_filesize in php.ini.' );
            return call_user_func( $upload_error_handler, $file, $error_msg );
        }

        if ($test_type)
        {
            // A correct MIME type will pass this test. Override $mimes or use the upload_mimes filter.
            $wp_filetype = wp_check_filetype_and_ext( $file[ 'tmp_name' ], $file[ 'name' ], $mimes );
            extract( $wp_filetype );

            // Check to see if wp_check_filetype_and_ext() determined the filename was incorrect
            if ($proper_filename)
                $file[ 'name' ] = $proper_filename;

            if ((!$type || !$ext) && !current_user_can( 'unfiltered_upload' ))
                return call_user_func( $upload_error_handler, $file, __( 'Sorry, this file type is not permitted for security reasons.' ) );

            if (!$ext)
                $ext = ltrim( strrchr( $file[ 'name' ], '.' ), '.' );

            if (!$type)
                $type = $file[ 'type' ];
        }
        else
            $type = '';

        // A writable uploads dir will pass this test. Again, there's no point overriding this one.
        if (!(($uploads = wp_upload_dir( $time )) && false === $uploads[ 'error' ]))
            return call_user_func( $upload_error_handler, $file, $uploads[ 'error' ] );

        $filename = wp_unique_filename( $uploads[ 'path' ], $file[ 'name' ], $unique_filename_callback );

        // Move the file to the uploads dir
        $new_file = $uploads[ 'path' ] . "/$filename";
        if (false === @copy( $file[ 'tmp_name' ], $new_file ))
            return array( 'error' => sprintf( __('The uploaded file could not be moved to %s.' ), $uploads['path'] ) );

        // Set correct file permissions
        $stat = stat( dirname( $new_file ));
        $perms = $stat[ 'mode' ] & 0000666;
        @chmod( $new_file, $perms );

        // Compute the URL
        $url = $uploads[ 'url' ] . "/$filename";

        if (is_multisite())
            delete_transient( 'dirsize_cache' );

        return apply_filters( 'wp_handle_upload', array( 'file' => $new_file, 'url' => $url, 'type' => $type ), 'upload' );
    }

    /**
     * AJAX callback that handles a JSON response from OpenBadges.me, saves the image in
     * the media library, and then sets it as the post feature image.
     */
    function ajax_badge_designer_publish()
    {
        /* checks copied from wp-admin/async-upload.php */
        nocache_headers();

        /* copied from wp-admin/includes/ajax-actions.php wp_ajax_upload_attachment */
        check_ajax_referer( 'wpbadger-badge-designer', 'nonce' );

        if (!current_user_can( 'upload_files' ))
            wp_die();

        $post_id = null;
        if (isset( $_POST[ 'post_id' ] ))
        {
            $post_id = $_POST[ 'post_id' ];
            if (!current_user_can( 'edit_post', $post_id ))
                wp_die();
        }

        /* Next bits take the OpenBadges.me response and parse it. We do these
         * steps:
         *
         * 1. Decocde the JSON response
         * 2. Check that badge.image starts with 'data:'
         * 3. Get the mime-type and encoding from the 'data' string
         * 4. Decode the data
         * 5. Save it in a temporary file and construct and array just like $_FILES would
         */
        $badge = @json_decode( stripslashes( $_POST[ 'badge' ] ), true );
        if (!$badge)
            wp_send_json_error( array(
                'message'   => __( 'Error decoding the badge designer data.', 'wpbadger' ),
                'filename'  => 'badge.png',
            ) );

        /* get the image header (mime and encoding) and the data */
        if (substr( $badge[ 'image' ], 0, 5 ) != 'data:')
            wp_send_json_error( array(
                'message'   => __('Error decoding the badge designer image data.', 'wpbadger' ),
                'filename'  => 'badge.png',
            ) );

        $pos = strpos( $badge[ 'image' ], ',', 5 );
        if (($pos === false) || ($pos <= 5))
            wp_send_json_error( array(
                'message'   => __('Error decoding the badge designer image data.', 'wpbadger' ),
                'filename'  => 'badge.png',
            ) );
        $image_hdr = explode( ';', substr( $badge[ 'image' ], 5, ($pos - 5) ) );
        $image_data = substr( $badge[ 'image' ], $pos + 1 );

        switch ($image_hdr[ 1 ])
        {
            case 'base64':
                $image_data = @base64_decode( $image_data );
                if ($image_data === false)
                    wp_send_json_error( array(
                        'message'   => __( 'Error decoding the badge designer image data: bad base64 data.', 'wpbadger' ),
                        'filename'  => 'badge.png',
                    ) );
                break;

            default:
                wp_send_json_error( array(
                    'message'   => __( 'Error decoding the badge designer image data: unknown encoding.', 'wpbadger' ),
                    'filename'  => 'badge.png',
                ) );
        }

        /* fake our file upload */
        $_f = array(
            'tmp_name'  => tempnam( sys_get_temp_dir(), 'wpbadger-badge-designer-' ),
            'type'      => $image_hdr[ 0 ],
            'error'     => 0,
            'size'      => strlen( $image_data ),
        );
        $_f[ 'name' ] = pathinfo( $_f[ 'tmp_name' ], PATHINFO_BASENAME ) . '.png';
        register_shutdown_function( array( $this, '_ajax_delete_tmpfile' ), $_f[ 'tmp_name' ] );

        if (file_put_contents( $_f[ 'tmp_name' ], $image_data ) === false)
            wp_send_json_error( array(
                'message'   => __( 'Error saving the badge designer image.', 'wpbadger'),
                'filename'  => 'badge.png',
            ) );

        /* try to build a title for this image from the badgeText, and if that isn't available
         * use the concat of just the 'text' lines.
         */
        $title = sanitize_title( $badge[ 'badgeText' ][ 'value' ] );
        if (empty( $title ) || is_numeric( $title ))
        {
            $title = sanitize_title( $badge[ 'text' ][ 'value' ] . ' ' . $badge[ 'text' ][ 'value2' ] );
            if (is_numeric( $title ))
                $title = '';
        }

        /* DO THE UPLOAD. HOORAY! */
        $attachment_id = $this->_ajax_media_handle_upload(
            $_f,
            $post_id,
            array( 'post_title' => $title ),
            array(
                'test_form' => false,
                'mimes' => array( 'png' => 'image/png' )
            )
        );

        if (is_wp_error( $attachment_id ))
            wp_send_json_error( array(
                'message'  => $attachment_id->get_error_message(),
                'filename' => 'badge.png',
            ) );

        if (!set_post_thumbnail( $post_id, $attachment_id ))
            wp_send_json_error( array(
                'message'   => __( 'Unable to set the badge as the featured image.', 'wpbadger' ),
                'filename'  => 'badge.png',
            ) );

        if (!($attachment = wp_prepare_attachment_for_js( $attachment_id )))
            wp_die();

        $attachment[ 'postimagediv' ] = _wp_post_thumbnail_html( $attachment_id, $post_id );
        wp_send_json_success( $attachment );
    }

    /**
     * Checks that a badge post is valid. Returns an array with the parts checked, and
     * an overall results. Array keys:
     *
     * - image
     * - description
     * - description-length
     * - criteria
     * - status
     * - all
     *
     * @return array
     */
    function check_valid( $post_id, $post = null )
    {
        if (is_null( $post ))
            $post = get_post( $post_id );

        $rv = array(
            'image'         => false,
            'image-png'     => true,        # this gets defaulted to 'false' only if 'image' is true
            'description'   => false,
            'description-length' => false,
            'criteria'      => false,
            'status'        => false
        );

        # Check for post image, and that it is a PNG
        $image_id = get_post_thumbnail_id( $post_id );
        if ($image_id > 0)
        {
            $image_file = get_attached_file( $image_id );
            if (!empty( $image_file ))
            {
                $rv[ 'image' ] = true;
                $rv[ 'image-png' ] = false;

                $image_ext = pathinfo( $image_file, PATHINFO_EXTENSION );
                if (strtolower( $image_ext ) == 'png')
                    $rv[ 'image-png' ] = true;
            }
        }

        # Check that the description is not empty.
        $desc = get_post_meta( $post_id, 'wpbadger-badge-description', true );
        if (!empty( $desc ))
            $rv[ 'description' ] = true;
        if (strlen( $desc ) <= 128)
            $rv[ 'description-length' ] = true;
        
        # Check that the criteria is not empty.
        $criteria = trim( strip_tags( $post->post_content ) );
        if (!empty( $criteria ))
            $rv[ 'criteria' ] = true;

        if ($post->post_status == 'publish')
            $rv[ 'status' ] = true;

        $rv[ 'all' ] =
            $rv[ 'image' ] &&
            $rv[ 'image-png' ] &&
            $rv[ 'description' ] &&
            $rv[ 'description-length' ] &&
            $rv[ 'criteria' ] &&
            $rv[ 'status' ];

        return $rv;
    }

    /**
     * Add a simple description metabox. We can't place this where we want
     * directly in the page, so just dump it wherever and use JS to reposition it.
     *
     * Also, since we're going to re-enable the media buttons, add the label for the criteria
     * box.
     */
    function description_meta_box()
    {
        if (get_post_type() != $this->get_post_type_name())
            return;

        ?>
        <div id="wpbadger-badge-descriptiondiv"><div id="wpbadger-badge-descriptionwrap">
            <label class="screen-reader-text" id="wpbadger-badge-description-prompt-text" for="wpbadger-badge-description"><?php _e( "Enter description here", "wpbadger" ) ?></label>
            <input type="text" class="widefat" name="wpbadger-badge-description" id="wpbadger-badge-description" value="<?php esc_attr_e( get_post_meta( get_the_ID(), 'wpbadger-badge-description', true ) ) ?>" />
        </div></div>
        <?php
    }

    /**
     * If the badge is invalid, add it to the list of post states.
     */
    function display_post_states( $post_states )
    {
        if (get_post_type() != $this->get_post_type_name())
            return $post_states;

        if (get_post_status() == 'publish')
        {
            $valid = get_post_meta( get_the_ID(), 'wpbadger-badge-valid', true );
            if (!$valid)
                $post_states[ 'wpbadger-badge-state' ] = '<span class="wpbadger-badge-state-invalid">'.__( "Invalid", 'wpbadger' ).'</span>';
        }

        return $post_states;
    }

    /**
     * Get the badge description metadata. For legacy reasons, this will
     * try to use the post_content if the description metadata isn't present.
     */
    function get_post_description( $post_id, $post = null )
    {
        if (is_null( $post ))
            $post = get_post( $post_id );

        $desc = get_post_meta( $post_id, 'wpbadger-badge-description', true );
        if (empty( $desc ))
        {
            $desc = strip_tags( $post->post_content );
            $desc = str_replace( array( "\r", "\n" ), '', $desc );
        }

        return $desc;
    }

    /**
     * Modify the Feature Image metabox to be called the Badge Image.
     */
    function image_meta_box()
    {
        global $wp_meta_boxes;

        unset( $wp_meta_boxes[ 'post' ][ 'side' ][ 'core' ][ 'postimagediv' ] );
        add_meta_box(
            'postimagediv',
            esc_html__( 'Badge Image', 'wpbadger' ),
            'post_thumbnail_meta_box',
            $this->get_post_type_name(),
            'side',
            'low'
        );
    }

    /**
     * Add the badge version column to the table listing badges.
     */
    function manage_posts_columns( $defaults )
    {  
        $defaults[ 'badge_version' ] = 'Badge Version';
        return $defaults;  
    }  

    /**
     * Echo data for the badge version when displaying the table.
     */
    function manage_posts_custom_column( $column_name, $post_id )
    {  
        if ($column_name == 'badge_version')
            esc_html_e( get_post_meta( $post_id, 'wpbadger-badge-version', true ) );
    }

    /**
     * Display the Badge Version metabox.
     */
    function meta_box_version( $object, $box )
    {
        wp_nonce_field( basename( __FILE__ ), 'wpbadger_badge_nonce' );

        ?>
        <p>
            <input class="widefat" type="text" name="wpbadger-badge-version" id="wpbadger-badge-version" value="<?php esc_attr_e( get_post_meta( $object->ID, 'wpbadger-badge-version', true ) ); ?>" size="30" />
        </p>
        <?php
    }

    /**
     * Add the meta boxes to the badge post editor page.
     */
    function meta_boxes_add()
    {
        add_meta_box(
            'wpbadger-badge-version',		// Unique ID
            esc_html__( 'Badge Version', 'wpbadger' ),	// Title
            array( $this, 'meta_box_version' ),		// Callback function
            $this->get_post_type_name(),						// Admin page (or post type)
            'side',							// Context
            'low'						// Priority
        );
    }

    /**
     * Add the action hooks needed to support badge post editor metaboxes.
     */
    function meta_boxes_setup()
    {
        add_action( 'add_meta_boxes', array( $this, 'meta_boxes_add' ) );
        add_action( 'add_meta_boxes', array( $this, 'image_meta_box' ), 0 );
        add_action( 'edit_form_advanced', array( $this, 'description_meta_box' ) );

        add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
    }

    /**
     * Save the meta information for a badge post.
     */
    function save_post( $post_id, $post )
    {
        if ($post->post_type != $this->get_post_type_name())
            return $post_id;

        if (empty( $_POST ) || !wp_verify_nonce( $_POST[ 'wpbadger_badge_nonce' ], basename( __FILE__ ) ))
            return $post_id;

        $post_type = get_post_type_object( $post->post_type );
        if (!current_user_can( $post_type->cap->edit_post, $post_id ))
            return $post_id;

        $new_meta_value = $_POST['wpbadger-badge-version'];
        if (preg_match( '/^\d+$/', $new_meta_value )) {
            $new_meta_value .= '.0';
        } elseif (!preg_match( '/^\d+(\.\d+)+$/', $new_meta_value )) {
            $new_meta_value = '1.0';
        }

        $meta_key = 'wpbadger-badge-version';
        $meta_value = get_post_meta( $post_id, $meta_key, true );

        if ($new_meta_value && '' == $meta_value)
            add_post_meta( $post_id, $meta_key, $new_meta_value, true );
        elseif ($new_meta_value && $new_meta_value != $meta_value)
            update_post_meta( $post_id, $meta_key, $new_meta_value );
        elseif ('' == $new_meta_value && $meta_value)
            delete_post_meta( $post_id, $meta_key, $meta_value );		

        $meta_key = 'wpbadger-badge-description';
        $meta_value = strip_tags( stripslashes( $_POST[ $meta_key ] ) );

        if (empty( $meta_value ))
            delete_post_meta( $post_id, $meta_key );
        else
            update_post_meta( $post_id, $meta_key, $meta_value );
    }

    /**
     * Validate the post metadata and mark it as valid or not.
     */
    function save_post_validate( $post_id, $post )
    {
        if ($post->post_type != $this->get_post_type_name())
            return;

        $valid = $this->check_valid( $post_id, $post );

        update_post_meta( $post_id, 'wpbadger-badge-valid', $valid[ 'all' ] );
    }
}

$GLOBALS[ 'wpbadger_badge_schema' ] = new WPBadger_Badge_Schema();

