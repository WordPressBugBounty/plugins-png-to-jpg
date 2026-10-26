<?php
/*
	Plugin Name: PNG to JPG
	Plugin URI: https://wp-speedup.eu
	Description: Convert PNG images to JPG, free up web space and speed up your webpage
	Version: 4.6
	Author: KubiQ
	Author URI: https://www.paypal.me/jakubnovaksl
	Text Domain: png_to_jpg
	Domain Path: /languages
*/

class png_to_jpg{
	var $plugin_admin_page;
	var $settings;
	var $tab;
	var $image;
	var $converted_stats = 0;
	var $overrides = array(); // per-run setting overrides ( used by WP-CLI )
	var $last_error = '';
	var $db_tables = array();

	function __construct(){
		add_action( 'plugins_loaded', array( $this, 'plugins_loaded' ) );
		add_action( 'admin_menu', array( $this, 'plugin_menu_link' ) );
		add_action( 'init', array( $this, 'plugin_init' ) );
		add_action( 'admin_notices', array( $this, 'server_gd_library' ) );
		add_filter( 'wp_handle_upload', array( $this, 'upload_converting' ) );
		add_action( 'wp_ajax_hasTransparency', array( $this, 'ajax_has_transparency' ) );
		add_action( 'wp_ajax_convert_old_png', array( $this, 'convert_old_png' ) );
		add_action( 'add_attachment', array( $this, 'attachment_converted_meta' ) );
		add_action( 'attachment_updated', array( $this, 'attachment_converted_meta' ) );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'attachment_submitbox_misc_actions' ), 90, 1 );
		add_action( 'admin_init', array( $this, 'alter_media_template' ) );
		add_action( 'delete_attachment', array( $this, 'delete_attachment_png_version' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'media_row_action' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'attachment_fields_to_edit' ), 10, 2 );
		add_action( 'wp_loaded', array( $this, 'convert_png_from_media_library' ) );

		add_filter( 'posts_clauses', function( $clauses, $query ){
			global $wpdb;
			if( is_admin() ){
				$direction = $query->get('sort_filesize');
				if( in_array( $direction, [ 'asc', 'desc' ] ) ){
					$clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS mt_filesize ON {$wpdb->posts}.ID = mt_filesize.post_id AND mt_filesize.meta_key = '_wp_attachment_metadata' ";
					$clauses['orderby'] = "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(mt_filesize.meta_value, 's:8:\"filesize\";i:', -1), ';', 1) AS UNSIGNED) " . strtoupper( $direction );
				}
			}
			return $clauses;
		}, 10, 2 );
	}

	function can_convert(){
		return current_user_can( 'manage_options' );
	}

	function convert_url( $attachment_id ){
		return wp_nonce_url( admin_url( 'upload.php?convert_png=' . absint( $attachment_id ) ), 'convert_png_image_' . absint( $attachment_id ), 'ptj_nonce' );
	}

	function media_row_action( $actions, $post ){
		if( $post->post_mime_type == 'image/png' && $this->can_convert() ){
			$url = $this->convert_url( $post->ID );
			$actions['pjt-convert-image'] = '<a href="' . esc_url( $url ) . '" title="' . esc_attr( __( 'Convert this PNG to JPG', 'png_to_jpg' ) ) . '">' . __( 'Convert to JPG', 'png_to_jpg' ) . '</a>';
		}
		return $actions;
	}

	function attachment_fields_to_edit( $form_fields, $post ){
		if( get_post_mime_type( $post->ID ) == 'image/png' && $this->can_convert() ){
			$url = $this->convert_url( $post->ID );
			$form_fields['pjt_convert_image'] = array(
				'value' => 1,
				'label' => __( 'PNG to JPG', 'png_to_jpg' ),
				'input' => 'html',
				'html' => '<a class="button button-small" href="' . esc_url( $url ) . '" title="' . esc_attr( __( 'Convert this PNG to JPG', 'png_to_jpg' ) ) . '">' . __( 'Convert', 'png_to_jpg' ) . '</a>'
			);
		}
		return $form_fields;
	}

	function convert_png_from_media_library(){
		global $pagenow;
		if( $pagenow != 'upload.php' || ! isset( $_GET['convert_png'], $_GET['ptj_nonce'] ) || ! $this->can_convert() ){
			return;
		}
		$id = absint( $_GET['convert_png'] );
		if(
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['ptj_nonce'] ) ), 'convert_png_image_' . $id ) ||
			get_post_mime_type( $id ) != 'image/png'
		){
			return;
		}
		if( $this->convert_single_image( $id ) ){
			add_action( 'admin_notices', function(){
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Image successfully converted to JPG.', 'png_to_jpg' ) . '</p></div>';
			});
		}else{
			add_action( 'admin_notices', function(){
				echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Image not converted to JPG.', 'png_to_jpg' ) . '</p></div>';
			});
		}
	}

	function delete_attachment_png_version( $post_id, $post ){
		if( get_post_meta( $post_id, 'png_converted', 1 ) && $filepath = get_post_meta( $post_id, '_wp_attached_file', 1 ) ){
			$uploadpath = wp_get_upload_dir();
			$parts = explode( '.', $filepath );
			array_pop( $parts );
			$filepath = $uploadpath['basedir'] . '/' . implode( '.', $parts ) . '.png';
			if( file_exists( $filepath ) ){
				@unlink( $filepath );
			}
		}
	}

	function alter_media_template(){
		global $pagenow;
		add_filter( 'wp_prepare_attachment_for_js', array( $this, 'wp_prepare_attachment_for_js' ), 10, 3 );

		// buffer output only on screens that print the media templates, never for AJAX, exports, downloads...
		if( ! wp_doing_ajax() && in_array( $pagenow, array( 'upload.php', 'post.php', 'post-new.php' ), true ) ){
			ob_start( array( $this, 'final_output' ) );
		}
	}

	function final_output( $content ){
		if( strpos( $content, "data.png_converted" ) === false ){
			$content = str_replace(
				"<# if ( 'image' === data.type && ! data.uploading ) { #>",
				"<# if ( data.png_converted && data.controller.el.getAttribute('class').indexOf('edit-attachment-frame') != -1 ){ #>{{{ data.png_converted }}}<# } #><# if ( 'image' === data.type && ! data.uploading ) { #>",
				$content
			);
		}
		return $content;
	}

	function wp_prepare_attachment_for_js( $response, $attachment, $meta ){
		if( $png_converted = get_post_meta( $attachment->ID, 'png_converted', 1 ) ){
			if( $png_converted < 0 ){
				$png_converted = abs( $png_converted );
				$png_converted = '<span style="color:#f00">-' . size_format( $png_converted, 2 ) . '</span>';
			}else{
				$png_converted = size_format( $png_converted, 2 );
			}
			$response['png_converted'] = '<div class="png_converted"><strong>' . __( 'PNG to JPG saved:', 'png_to_jpg' ) . '</strong> ' . $png_converted . '</div>';
		}
		return $response;
	}

	function attachment_converted_meta( $post_id ){
		if( $this->converted_stats ){
			update_post_meta( $post_id, 'png_converted', $this->converted_stats );
			$this->converted_stats = 0; // do not leak the stats to the next attachment saved in the same request
		}
	}

	function attachment_submitbox_misc_actions( $post ){
		if( $png_converted = get_post_meta( $post->ID, 'png_converted', 1 ) ){
			if( $png_converted < 0 ){
				$png_converted = abs( $png_converted );
				$png_converted = '<strong style="color:#f00">-' . size_format( $png_converted, 2 ) . '</strong>';
			}else{
				$png_converted = '<strong>' . size_format( $png_converted, 2 ) . '</strong>';
			}
			echo '<div class="misc-pub-section">' . __( 'PNG to JPG saved:', 'png_to_jpg' ) . ' ' . $png_converted . '</div>';
		}
	}

	function plugins_loaded(){
		load_plugin_textdomain( 'png_to_jpg', FALSE, basename( dirname( __FILE__ ) ) . '/languages/' );
	}

	function activate(){
		if( ! get_option( 'png_to_jpg_settings', 0 ) ){
			$defaults = array(
				'general' => array(
					'upload_convert' => 0,
					'jpg_quality' => '90',
					'only_lower' => 'checked',
					'leave_original' => 'checked',
					'autodetect' => 'checked',
					'images_per_page' => '-1'
				)
			);
			update_option( 'png_to_jpg_settings', $defaults );
		}
	}

	function filter_plugin_actions( $links, $file ){
		$settings_link = '<a href="upload.php?page=' . basename( __FILE__ ) . '">' . __('Settings') . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	function plugin_menu_link(){
		$this->plugin_admin_page = add_submenu_page(
			'upload.php',
			__( 'PNG to JPG', 'png_to_jpg' ),
			__( 'PNG to JPG', 'png_to_jpg' ),
			'manage_options',
			basename( __FILE__ ),
			array( $this, 'admin_options_page' )
		);
		add_action( 'load-' . $this->plugin_admin_page, array( $this, 'handle_yoast_reindex' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'filter_plugin_actions' ), 10, 2 );
	}

	function plugin_init(){
		$this->settings = get_option( 'png_to_jpg_settings' );
		if( ! is_array( $this->settings ) ) $this->settings = array();
		if( ! isset( $this->settings['general'] ) || ! is_array( $this->settings['general'] ) ) $this->settings['general'] = array();
		// checkboxes are "unchecked" when missing, so only fill defaults for scalar values
		$this->settings['general'] += array(
			'upload_convert' => 0,
			'jpg_quality' => 90,
			'images_per_page' => -1,
		);
	}

	// returns a setting, WP-CLI flags ( $this->overrides ) win over saved settings
	function get_setting( $key ){
		if( array_key_exists( $key, $this->overrides ) ) return $this->overrides[ $key ];
		return isset( $this->settings['general'][ $key ] ) ? $this->settings['general'][ $key ] : null;
	}

	function handle_yoast_reindex(){
		if( ! isset( $_GET['yoast_reindex_everything'] ) ) return;
		if( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ) );
		check_admin_referer( 'ptj_yoast_reindex' );
		global $wpdb;
		foreach( array( 'yoast_indexable', 'yoast_indexable_hierarchy' ) as $table ){
			if( $this->table_exists( $wpdb->prefix . $table ) ){
				$wpdb->query( "DELETE FROM {$wpdb->prefix}{$table}" );
			}
		}
		foreach( array( 'wpseo_total_unindexed_post_type_archives', 'wpseo_total_unindexed_general_items', 'wpseo_total_unindexed_posts', 'wpseo_total_unindexed_terms', 'wpseo_unindexed_post_link_count', 'wpseo_unindexed_term_link_count' ) as $transient ){
			delete_transient( $transient );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wpseo_tools' ) );
		exit;
	}

	function table_exists( $table ){
		global $wpdb;
		if( ! isset( $this->db_tables[ $table ] ) ){
			$this->db_tables[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
		}
		return $this->db_tables[ $table ];
	}

	function plugin_admin_tabs( $current = 'general' ){
		$tabs = array(
			'general' => __('General'),
			'convert' => __( 'Convert existing PNGs', 'png_to_jpg' ),
		); ?>
		<h2 class="nav-tab-wrapper">
		<?php foreach( $tabs as $tab => $name ){ ?>
			<a class="nav-tab <?php echo $tab == $current ? 'nav-tab-active' : '' ?>" href="?page=<?php echo basename( __FILE__ ) ?>&amp;tab=<?php echo $tab ?>"><?php echo $name ?></a>
		<?php } ?>
		</h2><br><?php
	}

	function admin_options_page(){
		global $wpdb;
		if( get_current_screen()->id != $this->plugin_admin_page ) return;
		$this->tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

		if( isset( $_POST['plugin_sent'] ) && check_admin_referer( 'save_these_settings_' . get_current_user_id(), 'settings_nonce' ) ){

			$this->settings = array( 'general' => array() );
			$this->settings['general']['jpg_quality'] = isset( $_POST['jpg_quality'] ) ? intval( $_POST['jpg_quality'] ) : 90;
			if( $this->settings['general']['jpg_quality'] > 100 ) $this->settings['general']['jpg_quality'] = 100;
			if( $this->settings['general']['jpg_quality'] < 1 ) $this->settings['general']['jpg_quality'] = 1;

			$this->settings['general']['upload_convert'] = isset( $_POST['upload_convert'] ) ? intval( $_POST['upload_convert'] ) : 0;
			if( $this->settings['general']['upload_convert'] < 0 || $this->settings['general']['upload_convert'] > 2 ) $this->settings['general']['upload_convert'] = 0;

			if( isset( $_POST['only_lower'] ) ) $this->settings['general']['only_lower'] = 'checked';
			if( isset( $_POST['leave_original'] ) ) $this->settings['general']['leave_original'] = 'checked';
			if( isset( $_POST['autodetect'] ) ) $this->settings['general']['autodetect'] = 'checked';
			if( isset( $_POST['images_per_page'] ) ){
				$per_page = intval( $_POST['images_per_page'] );
				$this->settings['general']['images_per_page'] = $per_page < 1 ? -1 : $per_page;
			}else{
				$this->settings['general']['images_per_page'] = -1;
			}

			update_option( 'png_to_jpg_settings', $this->settings );
		} ?>
		<div class="wrap">
			<h2><?php _e( 'PNG to JPG', 'png_to_jpg' ); ?></h2>
			<?php if( isset( $_POST['plugin_sent'] ) ) echo '<div class="updated"><p>' . __('Settings saved.') . '</p></div>'; ?>
			<form method="post" action="<?php echo admin_url( 'upload.php?page=' . basename( __FILE__ ) ) ?>">
				<input type="hidden" name="plugin_sent" value="1"><?php
				wp_nonce_field( 'save_these_settings_' . get_current_user_id(), 'settings_nonce' );
				$this->plugin_admin_tabs( $this->tab );
				switch( $this->tab ):
					case 'general':
						$this->tab_general();
						break;
					case 'convert':
						$this->tab_convert();
						break;
				endswitch; ?>
			</form>
		</div><?php
	}

	function server_gd_library(){
		if( ! function_exists('imagecreatefrompng') ){
			echo '<div class="error"><p>' . __( 'PNG to JPG requires gd library enabled!', 'png_to_jpg' ) . '</p></div>';
		}
	}

	function tab_general(){
		global $wpdb;
		$stats = $wpdb->get_row("SELECT COUNT(*) as converted, SUM( meta_value ) as saved FROM {$wpdb->postmeta} WHERE meta_key = 'png_converted'"); ?>
		<div class="below-h2 updated">
			<p><?php
				printf( __( '%d images converted', 'png_to_jpg' ), $stats->converted );
				if( $stats->saved < 0 ){
					$stats->saved = abs( $stats->saved );
					$stats->saved = '<span style="color:#f00">-' . size_format( $stats->saved, 2 ) . '</span>';
				}else{
					$stats->saved = size_format( $stats->saved, 2 );
				}
				echo '<br>';
				printf( __( '%s saved', 'png_to_jpg' ), $stats->saved ); ?>
			</p>
		</div>
		<table class="form-table">
			<tr>
				<th>
					<label for="q_field_1"><?php _e( 'JPG quality', 'png_to_jpg' ) ?></label> 
				</th>
				<td>
					<input type="number" min="1" max="100" step="1" name="jpg_quality" placeholder="90" value="<?php echo $this->settings['general']['jpg_quality'] ?>" id="q_field_1"> %
				</td>
			</tr>
			<tr>
				<th>
					<label for="q_field_2"><?php _e( 'Convert PNG to JPG during upload', 'png_to_jpg' ) ?></label> 
				</th>
				<td><?php
					$this->q_select(array(
						'name' => 'upload_convert',
						'id' => 'q_field_2',
						'value' => $this->settings['general']['upload_convert'],
						'options' => array(
							0 => __('No'),
							1 => __('Yes'),
							2 => __( 'Yes, but only images without transparency', 'png_to_jpg' )
						)
					)); ?>
				</td>
			</tr>
			<tr>
				<th>
					<label for="q_field_5"><?php _e( 'Only convert image if JPG filesize is lower than PNG filesize', 'png_to_jpg' ) ?></label> 
				</th>
				<td>
					<input type="checkbox" name="only_lower" value="checked" id="q_field_5" <?php echo isset( $this->settings['general']['only_lower'] ) ? $this->settings['general']['only_lower'] : '' ?>>
				</td>
			</tr>
			<tr>
				<th>
					<label for="q_field_3"><?php _e( 'Leave original PNG images on the server', 'png_to_jpg' ) ?></label> 
				</th>
				<td>
					<input type="checkbox" name="leave_original" value="checked" id="q_field_3" <?php echo isset( $this->settings['general']['leave_original'] ) ? $this->settings['general']['leave_original'] : '' ?>>
				</td>
			</tr>
			<tr>
				<th>
					<label for="q_field_4"><?php _e( 'Autodetect transparency for existing PNG images', 'png_to_jpg' ) ?></label> 
				</th>
				<td>
					<input type="checkbox" name="autodetect" value="checked" id="q_field_4" <?php echo isset( $this->settings['general']['autodetect'] ) ? $this->settings['general']['autodetect'] : '' ?>>
				</td>
			</tr>
			<tr>
				<th>
					<label for="q_field_5"><?php _e( 'Images per page in Convert existing tab', 'png_to_jpg' ) ?></label> 
					<br><small>( -1 means no pagination )</small> 
				</th>
				<td>
					<input type="number" name="images_per_page" min="-1" value="<?php echo isset( $this->settings['general']['images_per_page'] ) ? intval( $this->settings['general']['images_per_page'] ) : '-1' ?>" id="q_field_5">
				</td>
			</tr>
		</table>
		<p class="submit"><input type="submit" class="button button-primary button-large" value="<?php _e('Save') ?>"></p><?php
	}

	function tab_convert(){
		global $wpdb;
		$nonce = wp_create_nonce('convert_old_png');
		wp_enqueue_media();
		$paged = isset( $_GET['paged'] ) && intval( $_GET['paged'] ) ? intval( $_GET['paged'] ) : 1;
		$args = [
			'post_type' => 'attachment',
			'post_mime_type' => 'image/png',
			'post_status' => 'inherit',
			'posts_per_page' => $this->settings['general']['images_per_page'],
			'paged' => $paged,
		];
		if( ! empty( $_GET['order'] ) ){
			$args['sort_filesize'] = $_GET['order'] == 'asc' ? 'asc' : 'desc';
		}
		$query_images = new WP_Query( $args ); ?>
		<div class="below-h2 error"><p><strong><?php _e( 'Do you have BACKUP? This operation will alter your original images and cannot be undone!', 'png_to_jpg' ) ?></strong></p></div>
		<div class="below-h2 error">
			<p>
				<?php _e( 'Converted images will be fixed only in these tables:', 'png_to_jpg' ) ?> 
				<em><?php echo "posts, postmeta, options, yoast_seo_links, revslider_slides, revslider_static_slides, toolset_post_guid_id, fpd_products, fpd_views, blc_instances, blc_links, fv_player_videos" ?></em>. 
				<?php _e( 'If you need support for more database tables from various plugins, let me know by mail to info@kubiq.sk', 'png_to_jpg' ) ?>
			</p>
		</div>
		<?php if( defined('WPSEO_VERSION') ): ?>
			<div class="below-h2 notice notice-large notice-warning" style="display:flex;align-items:center;gap:20px;justify-content:space-between;flex-wrap:wrap">
				<p><strong><?php _e( 'You are using Yoast SEO plugin and you will need to reindex everything after conversion of existing images.', 'png_to_jpg' ) ?></strong></p>
				<a href="#" id="yoast_reindex_everything" class="button"><?php _e( 'Reindex everything', 'png_to_jpg' ) ?></a>
			</div>
		<?php endif ?>
		<?php if( isset( $this->settings['general']['autodetect'] ) ): ?>
			<div id="transparency_status_message" class="below-h2 updated">
				<button type="button" class="button right" style="margin-top:4px">
					<?php esc_html_e( 'Stop it', 'png_to_jpg' ) ?>
				</button>
				<p><img src="<?php echo admin_url('/images/loading.gif') ?>" alt="" style="vertical-align:sub">&emsp;<span><?php _e( "Please wait, I'm getting transparency status for images...", 'png_to_jpg' ) ?></span></p>
			</div>
		<?php endif ?>
		<br>
		<div style="display:flex;align-items:center;gap:20px">
			<button type="button" class="button button-primary convert-pngs"><?php _e( 'Convert selected PNGs', 'png_to_jpg' ) ?></button>
			<button type="button" class="button button-default select-transparent"><?php _e( 'Select all transparent PNGs', 'png_to_jpg' ) ?></button>
			<button type="button" class="button button-default select-non-transparent"><?php _e( 'Select all non-transparent PNGs', 'png_to_jpg' ) ?></button>
			<?php if( $query_images->max_num_pages ): ?>
				<div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-left:auto">
					<?php for( $i = 1; $i <= $query_images->max_num_pages; $i++ ){
						echo '<a href="' . esc_url( admin_url( sprintf( 'upload.php?%s', http_build_query( array_merge( $_GET, [ 'paged' => $i ] ) ) ) ) ) . '" class="button button-' . ( $i == $paged ? 'primary' : 'default' ) . '">' . $i . '</a>';
					} ?>
				</div>
			<?php endif ?>
		</div>
		<br>
		<table class="wp-list-table widefat striped media">
			<thead>
				<tr>
					<th class="check-column"><input type="checkbox"></th>

					<th><?php _e('Media') ?></th>


					<th scope="col" id="filesize" class="manage-column column-filesize column-primary <?php echo empty( $_GET['order'] ) ? 'sortable' : ( $_GET['order'] == 'asc' ? 'sorted asc' : 'sorted desc' ) ?>" abbr="Filesize">
						<a href="?page=<?php echo basename( __FILE__ ) ?>&amp;tab=convert&amp;order=<?php echo empty( $_GET['order'] ) || $_GET['order'] == 'asc' ? 'desc' : 'asc' ?>">
							<span><?php _e( 'Filesize', 'png_to_jpg' ) ?></span>
							<span class="sorting-indicators">
								<span class="sorting-indicator asc" aria-hidden="true"></span>
								<span class="sorting-indicator desc" aria-hidden="true"></span>
							</span>
						</a>
					</th>

					<?php if( isset( $this->settings['general']['autodetect'] ) ): ?>
						<th><?php _e( 'Has transparency', 'png_to_jpg' ) ?></th>
					<?php endif ?>
				</tr>
			</thead>
			<tbody><?php
				foreach( $query_images->posts as $image ){
					$file_path = get_attached_file( $image->ID );
					if( file_exists( $file_path ) && strtolower( substr( $file_path, -3 ) ) == 'png' ){
						$transparency = get_post_meta( $image->ID, 'transparency', 1 );
						$transparency = $transparency == '' ? $transparency : (int)$transparency;
						$image->link = wp_get_attachment_url( $image->ID ); ?>
						<tr data-id="<?php echo (int) $image->ID ?>" data-transparency="<?php echo $transparency === 1 || $transparency === 0 ? $transparency : '-' ?>">
							<th scope="row" class="check-column">
								<input type="checkbox" name="media[]" value="<?php echo (int) $image->ID ?>" <?php if( isset( $this->settings['general']['autodetect'] ) ) echo 'disabled' ?>>
							</th>
							<td class="title column-title has-row-actions column-primary">
								<strong class="has-media-icon">
									<a href="<?php echo esc_url( $image->link ) ?>">
										<span class="media-icon image-icon">
											<?php echo wp_get_attachment_image( $image->ID, 'thumbnail' ) ?>
										</span>
										<?php echo esc_html( $image->post_title ) ?>
									</a>
								</strong>
								<p class="filename">
									<?php echo esc_html( basename( $image->link ) ) ?>
								</p>
							</td>
							<td>
								<?php echo size_format( filesize( $file_path ), 2 ) ?>
							</td>
							<?php if( isset( $this->settings['general']['autodetect'] ) ): ?>
								<td class="transparency">
									<?php echo $transparency === '' ? '' : ( $transparency ? 'YES' : 'NO' ) ?>
									<small class="transparency-info" style="display:block;color:#646970"><?php echo esc_html( get_post_meta( $image->ID, 'transparency_info', true ) ) ?></small>
								</td>
							<?php endif ?>
						</tr><?php
					}
				} ?>
			</tbody>
		</table>
		<br>

		<div style="display:flex;align-items:center;gap:20px">
			<button type="button" class="button button-primary convert-pngs"><?php _e( 'Convert selected PNGs', 'png_to_jpg' ) ?></button>
			<button type="button" class="button button-default select-transparent"><?php _e( 'Select all transparent PNGs', 'png_to_jpg' ) ?></button>
			<button type="button" class="button button-default select-non-transparent"><?php _e( 'Select all non-transparent PNGs', 'png_to_jpg' ) ?></button>
			<?php if( $query_images->max_num_pages ): ?>
				<div style="display:flex;align-items:center;flex-wrap:wrap;gap:2px;margin-left:auto">
					<?php for( $i = 1; $i <= $query_images->max_num_pages; $i++ ){
						echo '<a href="' . esc_url( admin_url( sprintf( 'upload.php?%s', http_build_query( array_merge( $_GET, [ 'paged' => $i ] ) ) ) ) ) . '" class="button button-' . ( $i == $paged ? 'primary' : 'default' ) . '">' . $i . '</a>';
					} ?>
				</div>
			<?php endif ?>
		</div>

		<div id="png_preview" class="media-modal" style="display:none">
			<button type="button" class="button-link media-modal-close"><span class="media-modal-icon"></span></button>
			<div class="media-modal-content">
				<div class="edit-attachment-frame mode-select hide-menu hide-router">
					<div class="media-frame-title">
						<h1><?php _e( 'Background switch:', 'png_to_jpg' ) ?></h1>
						<div id="png-background-switch">
							<a href="#" class="bg-chess active"></a>
							<a href="#" class="bg-black"></a>
							<a href="#" class="bg-white"></a>
						</div>
					</div>
					<div class="media-frame-content bg-chess">
						<div class="media-wrapper"></div>
					</div>
				</div>
			</div>
		</div>

		<style type="text/css" media="screen">
			.widefat thead .check-column{
				padding: 10px 0 0 4px;
			}
			#png_preview .bg-chess{
				background: linear-gradient(135deg, transparent 75%, rgba(255, 255, 255, 1) 0%) 0 0, linear-gradient(-45deg, transparent 75%, rgba(255, 255, 255, 1) 0%) 15px 15px, linear-gradient(135deg, transparent 75%, rgba(255, 255, 255, 1) 0%) 15px 15px, linear-gradient(-45deg, transparent 75%, rgba(255, 255, 255, 1) 0%) 0 0, #c4c4c4;
				background-size: 30px 30px;
			}
			#png_preview .bg-black{
				background: #000;
			}
			#png_preview .bg-white{
				background: #fff;
			}
			#png_preview .media-frame-content{
				display: flex;
				align-items: flex-start;
				justify-content: flex-start;
			}
			#png_preview .media-frame-title{
				display: flex;
				-ms-align-items: center;
				align-items: center;
			}
			#png-background-switch{
				display: flex;
				-ms-align-items: center;
				align-items: center;
			}
			#png-background-switch a,
			#png-background-switch a:focus{
				display: block;
				margin: 0 10px;
				width: 20px;
				height: 20px;
				border: 2px solid #000;
				border-radius: 50%;
				outline: none;
				box-shadow: none;
			}
			#png-background-switch a.active{
				border-color: #0f0;
			}
			#png-background-switch a.bg-chess{
				background-size: 10px 10px;
			}
			#png_preview .media-wrapper{
				position: relative;
				font-size: 0;
				line-height: 1;
				transition: box-shadow 300ms ease;
			}
			#png_preview .media-wrapper:hover{
				box-shadow: 0 0 0 1px #000;
			}
			#png_preview .media-wrapper span{
				content: '';
				display: block;
				padding: 3px 4px;
				position: absolute;
				top: -1px;
				left: -1px;
				background: #000;
				font-family: Consolas, monaco, monospace;
				font-size: 11px;
				color: #fff;
				opacity: 0;
				-webkit-transition: opacity 300ms ease;
				transition: opacity 300ms ease;
			}
			#png_preview .media-wrapper:hover span{
				opacity: 1;
			}
		</style>

		<script>
			jQuery(document).ready(function($){
				$('#yoast_reindex_everything').on('click', function(e){
					e.preventDefault();
					if( confirm('<?php esc_attr_e( 'This will empty yoast indexable database tables and redirect you to Yoast SEO where you need to click on Start SEO data optimization', 'png_to_jpg' ) ?>') ){
						const link = new URL( location.href );
						link.searchParams.set( 'tab', 'general' );
						link.searchParams.set( 'yoast_reindex_everything', 1 );
						link.searchParams.set( '_wpnonce', '<?php echo esc_js( wp_create_nonce( 'ptj_yoast_reindex' ) ) ?>' );
						location.href = link.href;
					}
				});

				$('.has-media-icon a').click(function(e){
					e.preventDefault();
					$('#png_preview .media-wrapper').html('<span></span><img src="' + this.href + '" alt="">');
					$('<img/>', {
						load: function(){
							$('#png_preview .media-wrapper span').text( this.width + ' x ' + this.height );
						},
						src: this.href
					});
					$('#png_preview').show();
				});
				$(document).keyup(function(e){
					if( $('#png_preview').is(':visible') ){
						var keycode = ( e.keyCode ? e.keyCode : e.which );
						if( keycode == 27 ){
							$('#png_preview').hide();
						}
					}
				});
				$('#png-background-switch a').click(function(e){
					e.preventDefault();
					$('#png-background-switch a').removeClass('active');
					$(this).addClass('active');
					$('#png_preview .media-frame-content').removeClass('bg-chess bg-white bg-black').addClass( $(this).attr('class') );
				});
				$('#png_preview .media-modal-close').click(function(e){
					e.preventDefault();
					$('#png_preview').hide();
				});
				$('.select-transparent').click(function(e){
					e.preventDefault();
					$('tr[data-transparency] input').prop( 'checked', false );
					$('tr[data-transparency=1] input').prop( 'checked', true );
				});
				$('.select-non-transparent').click(function(e){
					e.preventDefault();
					$('tr[data-transparency] input').prop( 'checked', false );
					$('tr[data-transparency=0] input').prop( 'checked', true );
				});
				$('.convert-pngs').click(function(e){
					e.preventDefault();
					$('#transparency_status_message span').text('<?php _e( 'Please wait, converting your PNG images is in progress...', 'png_to_jpg' ) ?>');
					$('#transparency_status_message').show();
					$('tbody tr input').prop( 'disabled', true );
					delete_selected_pngs();
				});

				window.stopPNGtoJPG = false;
				$('#transparency_status_message button').on('click', function(e){
					e.preventDefault();
					stopPNGtoJPG = true;
				});

				<?php if( isset( $this->settings['general']['autodetect'] ) ): ?>
					get_transparency();

					function get_transparency(){
						var $el = $('tbody tr[data-transparency="-"]').first();
						if( $el.length && ! stopPNGtoJPG ){
							$.post( '<?php echo admin_url('admin-ajax.php') ?>', {
								action: 'hasTransparency',
								id: $el.attr('data-id'),
								nonce: '<?php echo esc_js( $nonce ) ?>'
							}, function(response){
								var transparency = response.success ? parseInt( response.data.transparent ) : 0;
								$el.attr('data-transparency', transparency);
								$el.find('.transparency').text( transparency == 1 ? 'YES' : 'NO' ).append(
									$('<small class="transparency-info" style="display:block;color:#646970"></small>').text( response.success ? response.data.summary : '' )
								);
								if( ! stopPNGtoJPG ){
									get_transparency();
								}else{
									$('#transparency_status_message').hide();
									$('tbody tr input').prop( 'disabled', false );
								}
							});
						}else{
							$('#transparency_status_message').hide();
							$('tbody tr input').prop( 'disabled', false );
						}
					}
				<?php endif; ?>

				function delete_selected_pngs(){
					var $el = $('tbody tr input:checked').first();
					if( $el.length && ! stopPNGtoJPG ){
						var $tr = $el.parent().parent();
						$.post( '<?php echo admin_url('admin-ajax.php') ?>', {
							action: 'convert_old_png',
							id: $tr.attr('data-id'),
							nonce: '<?php echo $nonce ?>'
						}, function(){
							$tr.remove();
						}).fail(function( xhr ){
							$el.prop( 'checked', false );
							var reason = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data : '<?php echo esc_js( __( 'Your server is not powerful enough to process this image.', 'png_to_jpg' ) ) ?>';
							alert( $.trim( $tr.find('.filename').text() ) + ': ' + reason );
						}).always(function(){
							if( stopPNGtoJPG ){
								$('#transparency_status_message').html('<p><?php _e('Done') ?>.</p>');
								$('tbody tr input').prop('disabled', false);
							}else{
								delete_selected_pngs();
							}
						});
					}else{
						$('#transparency_status_message').html('<p><?php _e('Done') ?>.</p>');
						$('tbody tr input').prop('disabled', false);
					}
				}
			});
		</script><?php
	}

	function q_select( $field_data = array(), $print = 1, $cols = array( 'value' => 'ID', 'text' => 'post_title' ) ){
		if( ! is_object( $field_data ) ) $field_data = (object)$field_data;
		$field_data->value = is_array( $field_data->value ) ? $field_data->value : array( $field_data->value );
		$select = sprintf(
			'<select name="%s" id="%s" %s %s>',
			$field_data->name,
			$field_data->id,
			isset( $field_data->multiple ) ? 'multiple' : '',
			isset( $field_data->size ) ? 'size="' . $field_data->size . '"' : ''
		);
		if( isset( $field_data->placeholder ) ){
			$select .= '<option value="" disabled>' . $field_data->placeholder . '</option>';
		}
		foreach( $field_data->options as $option => $value ){
			if( isset( $value->ID ) || isset( $value->term_id ) ){
				$post_id = isset( $value->ID ) ? $value->ID : $value->term_id;
				$value = (array)$value;
				if( class_exists( 'PLL_Model' ) ){
					$post_lang = pll_get_post_language( $post_id );
					if( pll_default_language() != $post_lang ) continue;
				}
				$select .= sprintf(
					'<option value="%s" %s>%s</option>',
					$value[ $cols['text'] ],
					in_array( $value[ $cols['text'] ] , $field_data->value ) ? 'selected' : '',
					$value[ $cols['value'] ]
				);
			}else{
				$select .= sprintf(
					'<option value="%s" %s>%s</option>',
					$option,
					in_array( $option, $field_data->value ) ? 'selected' : '',
					$value
				);
			}
		}
		$select .= '</select>';
		if( $print )
			echo $select;
		else
			return $select;
	}

	function upload_converting( $params ){
		if( ! empty( $params['file'] ) && isset( $params['type'] ) && $params['type'] == 'image/png' ){
			$mode = (int) $this->get_setting( 'upload_convert' );
			if( $mode == 1 || ( $mode == 2 && ! $this->png_has_transparency( $params['file'] ) ) ){
				$new_params = $this->convert_image( $params );
				if( $new_params ) $params = $new_params;
			}
		}
		return $params;
	}

	/**
	 * Converts the PNG file in $params['file'] to a JPG next to it.
	 * Returns the updated $params ( file, url, type, saved ) or 0 when nothing was converted ( see $this->last_error ).
	 * When $params['dry_run'] is set, nothing is left on disk and only the estimated 'saved' bytes are returned.
	 */
	function convert_image( $params ){
		$this->converted_stats = 0;
		$this->last_error = '';
		$file = $params['file'];
		$dry_run = ! empty( $params['dry_run'] );

		if( ! function_exists( 'imagecreatefrompng' ) || ! function_exists( 'imagejpeg' ) ){
			$this->last_error = 'gd';
			return 0;
		}
		if( ! is_readable( $file ) ){
			$this->last_error = 'unreadable';
			return 0;
		}

		wp_raise_memory_limit( 'image' );
		$stats_before = filesize( $file );
		$img = @imagecreatefrompng( $file );
		if( ! $img ){
			$this->last_error = 'decode';
			return 0;
		}
		$width = imagesx( $img );
		$height = imagesy( $img );
		$bg = imagecreatetruecolor( $width, $height );
		if( ! $bg ){
			imagedestroy( $img );
			$this->last_error = 'decode';
			return 0;
		}
		imagefill( $bg, 0, 0, imagecolorallocate( $bg, 255, 255, 255 ) );
		imagealphablending( $bg, true );
		imagecopy( $bg, $img, 0, 0, 0, 0, $width, $height );
		imagedestroy( $img );

		$i = 1;
		$base_path = preg_replace( '/\.png$/i', '', $file );
		$base_url = isset( $params['url'] ) ? preg_replace( '/\.png$/i', '', $params['url'] ) : '';
		$new_path = $base_path . '.jpg';
		$new_url = $base_url . '.jpg';
		while( file_exists( $new_path ) ){
			$new_path = $base_path . '-' . $i . '.jpg';
			$new_url = $base_url . '-' . $i . '.jpg';
			++$i;
		}

		$quality = (int) $this->get_setting( 'jpg_quality' );
		if( $quality < 1 || $quality > 100 ) $quality = 90;

		$target = $dry_run ? tempnam( get_temp_dir(), 'ptj' ) : $new_path;
		$written = imagejpeg( $bg, $target, $quality );
		imagedestroy( $bg );
		if( ! $written || ! file_exists( $target ) ){
			if( file_exists( $target ) ) unlink( $target );
			$this->last_error = 'write';
			return 0;
		}

		clearstatcache( true, $target );
		$saved = $stats_before - filesize( $target );
		if( $this->get_setting( 'only_lower' ) && $saved <= 0 ){
			unlink( $target );
			$this->last_error = 'larger';
			return 0;
		}

		if( $dry_run ){
			unlink( $target );
			$params['saved'] = $saved;
			return $params;
		}

		$this->converted_stats = $saved;
		if( is_array( $this->image ) ){
			// attachment mode: the caller removes the original after the database is updated
			$this->image['new_path'] = $new_path;
			$this->image['new_url'] = $new_url;
		}elseif( ! $this->get_setting( 'leave_original' ) ){
			unlink( $file );
		}
		$params['file'] = $new_path;
		$params['url'] = $new_url;
		$params['type'] = 'image/jpeg';
		$params['saved'] = $saved;
		return $params;
	}

	/**
	 * Fast answer for "is this PNG transparent?" ( see png_transparency_report() for the reason ).
	 */
	function png_has_transparency( $file ){
		$report = $this->png_transparency_report( $file );
		return $report['transparent'];
	}

	/**
	 * Explains WHY a PNG is or is not considered transparent.
	 *
	 * 1) The PNG headers say if the file CAN be transparent: alpha channel ( color types 4 and 6 ) or a tRNS chunk.
	 * 2) A file that can be transparent is scanned pixel by pixel ( sampled for huge images ), because many exports contain an alpha channel where every pixel is fully opaque - that is safe to convert.
	 *
	 * Returns array(
	 * 	transparent 0|1, reason ( code ), summary ( human readable ), color_type, pixels, transparent_pixels,
	 * 	partial_pixels, bbox ( x1, y1, x2, y2 ) | null, sampled ( bool )
	 * )
	 */
	function png_transparency_report( $file ){
		$report = array(
			'transparent' => 0,
			'reason' => 'invalid',
			'summary' => __( 'Not a readable PNG file.', 'png_to_jpg' ),
			'color_type' => null,
			'pixels' => 0,
			'transparent_pixels' => 0,
			'partial_pixels' => 0,
			'bbox' => null,
			'sampled' => false,
		);
		$handle = @fopen( $file, 'rb' );
		if( ! $handle ) return $report;

		$color_types = array(
			0 => 'grayscale', 2 => 'RGB', 3 => 'palette', 4 => 'grayscale + alpha', 6 => 'RGBA',
		);
		$has_alpha = false;
		$has_trns = false;
		$color_type = null;
		if( fread( $handle, 8 ) === "\x89PNG\r\n\x1a\n" ){
			while( ! feof( $handle ) ){
				$head = fread( $handle, 8 );
				if( strlen( $head ) < 8 ) break;
				$length = current( unpack( 'N', substr( $head, 0, 4 ) ) );
				$type = substr( $head, 4, 4 );
				if( $type === 'IHDR' ){
					$ihdr = fread( $handle, $length );
					if( strlen( $ihdr ) >= 10 ){
						$color_type = ord( $ihdr[9] );
						$has_alpha = (bool) ( $color_type & 4 );
					}
					fseek( $handle, 4, SEEK_CUR ); // CRC
				}elseif( $type === 'tRNS' ){
					$has_trns = true;
					break;
				}elseif( $type === 'IDAT' || $type === 'IEND' ){
					break; // tRNS has to be before the first IDAT
				}else{
					fseek( $handle, $length + 4, SEEK_CUR );
				}
			}
		}
		fclose( $handle );

		if( $color_type === null ) return $report;
		$report['color_type'] = isset( $color_types[ $color_type ] ) ? $color_types[ $color_type ] : (string) $color_type;

		if( ! $has_alpha && ! $has_trns ){
			$report['reason'] = 'no_alpha';
			$report['summary'] = sprintf( __( 'No transparency: %s image without alpha channel or tRNS chunk.', 'png_to_jpg' ), $report['color_type'] );
			return $report;
		}

		$source = $has_alpha
			? sprintf( __( 'Has an alpha channel ( %s )', 'png_to_jpg' ), $report['color_type'] )
			: sprintf( __( 'Has a tRNS transparency chunk ( %s )', 'png_to_jpg' ), $report['color_type'] );

		// can be transparent, let's look at the pixels
		if( ! function_exists( 'imagecreatefrompng' ) ){
			$report['transparent'] = 1;
			$report['reason'] = 'unscanned';
			$report['summary'] = $source . '. ' . __( 'Pixels could not be scanned ( GD library missing ), assuming transparent.', 'png_to_jpg' );
			return $report;
		}
		wp_raise_memory_limit( 'image' );
		$img = @imagecreatefrompng( $file );
		if( ! $img ){
			$report['transparent'] = 1;
			$report['reason'] = 'unscanned';
			$report['summary'] = $source . '. ' . __( 'Pixels could not be scanned ( decoding failed ), assuming transparent.', 'png_to_jpg' );
			return $report;
		}

		$width = imagesx( $img );
		$height = imagesy( $img );
		$true_color = imageistruecolor( $img );
		$step = max( 1, (int) ceil( sqrt( $width * $height / 12000000 ) ) ); // exact up to ~12M pixels, sampled above
		$min_alpha = 3; // GD alpha: 0 = opaque, 127 = fully transparent; ignore invisible noise
		$total = $transparent = $partial = 0;
		$x1 = $y1 = PHP_INT_MAX;
		$x2 = $y2 = -1;
		for( $y = 0; $y < $height; $y += $step ){
			for( $x = 0; $x < $width; $x += $step ){
				$color = imagecolorat( $img, $x, $y );
				if( $true_color ){
					$alpha = ( $color >> 24 ) & 0x7F;
				}else{
					$info = imagecolorsforindex( $img, $color );
					$alpha = $info['alpha'];
				}
				++$total;
				if( $alpha >= $min_alpha ){
					if( $alpha >= 120 ) ++$transparent; else ++$partial;
					if( $x < $x1 ) $x1 = $x;
					if( $y < $y1 ) $y1 = $y;
					if( $x > $x2 ) $x2 = $x;
					if( $y > $y2 ) $y2 = $y;
				}
			}
		}
		imagedestroy( $img );

		$report['pixels'] = $total;
		$report['transparent_pixels'] = $transparent;
		$report['partial_pixels'] = $partial;
		$report['sampled'] = $step > 1;

		if( $transparent + $partial === 0 ){
			$report['reason'] = 'opaque_alpha';
			if( $step > 1 ){
				$report['summary'] = $source . ', ' . sprintf( __( 'but every checked pixel ( every %d. pixel of a very large image ) is fully opaque. Tiny transparent spots could be missed.', 'png_to_jpg' ), $step );
			}else{
				$report['summary'] = $source . ', ' . __( 'but every pixel is fully opaque. Safe to convert - nothing will turn white.', 'png_to_jpg' );
			}
			return $report;
		}

		$report['transparent'] = 1;
		$report['reason'] = $transparent ? 'transparent_pixels' : 'partial_pixels';
		$report['bbox'] = array( $x1, $y1, $x2, $y2 );
		$percent = ( $transparent + $partial ) / $total * 100;
		$report['summary'] = sprintf(
			__( '%1$s: %2$s%% of pixels are not opaque ( %3$s fully transparent, %4$s semi-transparent ), located within x %5$d-%6$d, y %7$d-%8$d of %9$dx%10$d px%11$s.', 'png_to_jpg' ),
			$source,
			number_format_i18n( $percent, $percent < 0.1 ? 3 : 1 ),
			number_format_i18n( $transparent ),
			number_format_i18n( $partial ),
			$x1, $x2, $y1, $y2,
			$width, $height,
			$step > 1 ? sprintf( __( ' ( estimated from every %d. pixel )', 'png_to_jpg' ), $step ) : ''
		);
		return $report;
	}

	function ajax_has_transparency(){
		if( ! $this->can_convert() ) wp_die( '', '', 403 );
		check_ajax_referer( 'convert_old_png', 'nonce' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if( get_post_type( $id ) != 'attachment' || get_post_mime_type( $id ) != 'image/png' ) wp_die( '', '', 400 );
		$file = get_attached_file( $id );
		$report = $file && file_exists( $file ) ? $this->png_transparency_report( $file ) : $this->png_transparency_report( '' );
		update_post_meta( $id, 'transparency', $report['transparent'] );
		update_post_meta( $id, 'transparency_info', $report['summary'] );
		wp_send_json_success( array( 'transparent' => $report['transparent'], 'summary' => $report['summary'] ) );
	}

	function convert_single_image( $attachment_id ){
		$result = $this->convert_attachment( $attachment_id );
		return $result['status'] === 'converted';
	}

	/**
	 * Converts one PNG attachment ( file, thumbnails, attachment data and URLs in the database ).
	 *
	 * $args: quality ( 1-100 ), force ( ignore "only if lower" ), leave_original ( bool ), skip_transparent, dry_run
	 * Returns array( id, status [converted|dry-run|skipped|failed], reason, file, before, after, saved )
	 */
	function convert_attachment( $attachment_id, $args = array() ){
		$id = absint( $attachment_id );
		$result = array( 'id' => $id, 'status' => 'failed', 'reason' => '', 'file' => '', 'before' => 0, 'after' => 0, 'saved' => 0 );

		if( get_post_type( $id ) != 'attachment' ){
			$result['reason'] = __( 'not an attachment', 'png_to_jpg' );
			return $result;
		}
		if( get_post_mime_type( $id ) != 'image/png' ){
			$result['reason'] = __( 'not a PNG', 'png_to_jpg' );
			return $result;
		}
		$path = get_attached_file( $id );
		$link = wp_get_attachment_url( $id );
		if( ! $path || ! $link || ! file_exists( $path ) || ! preg_match( '/\.png$/i', $path ) ){
			$result['reason'] = __( 'file is missing or has no .png extension', 'png_to_jpg' );
			return $result;
		}
		$result['file'] = basename( $path );
		$result['before'] = $result['after'] = filesize( $path );

		if( ! empty( $args['skip_transparent'] ) ){
			$report = $this->png_transparency_report( $path );
			if( $report['transparent'] ){
				$result['status'] = 'skipped';
				$result['reason'] = $report['summary'];
				return $result;
			}
		}

		$this->overrides = array();
		if( isset( $args['quality'] ) ) $this->overrides['jpg_quality'] = (int) $args['quality'];
		if( ! empty( $args['force'] ) ) $this->overrides['only_lower'] = false;
		if( isset( $args['leave_original'] ) ) $this->overrides['leave_original'] = (bool) $args['leave_original'];

		$this->image = array( 'ID' => $id, 'link' => $link, 'path' => $path );
		try{
			$converted = $this->convert_image( array(
				'ID' => $id,
				'file' => $path,
				'url' => $link,
				'dry_run' => ! empty( $args['dry_run'] ),
			) );
			if( ! $converted ){
				$reasons = array(
					'gd' => __( 'GD library is missing', 'png_to_jpg' ),
					'unreadable' => __( 'file is not readable', 'png_to_jpg' ),
					'decode' => __( 'could not decode the PNG ( corrupted file or not enough memory )', 'png_to_jpg' ),
					'write' => __( 'could not write the JPG ( permissions or disk space )', 'png_to_jpg' ),
					'larger' => __( 'JPG would not be smaller than PNG', 'png_to_jpg' ),
				);
				$result['status'] = $this->last_error == 'larger' ? 'skipped' : 'failed';
				$result['reason'] = isset( $reasons[ $this->last_error ] ) ? $reasons[ $this->last_error ] : $this->last_error;
			}elseif( ! empty( $args['dry_run'] ) ){
				$result['status'] = 'dry-run';
				$result['saved'] = $converted['saved'];
				$result['after'] = $result['before'] - $converted['saved'];
			}else{
				$this->update_image_data();
				if( ! $this->get_setting( 'leave_original' ) && file_exists( $this->image['new_path'] ) ){
					unlink( $path );
				}
				if( $converted['saved'] ){
					update_post_meta( $id, 'png_converted', $converted['saved'] );
				}
				$result['status'] = 'converted';
				$result['saved'] = $converted['saved'];
				$result['after'] = filesize( $this->image['new_path'] );
			}
		}finally{
			$this->image = null;
			$this->overrides = array();
			$this->converted_stats = 0;
		}
		return $result;
	}

	function convert_old_png(){
		if( ! $this->can_convert() ) wp_send_json_error( __( 'Sorry, you are not allowed to do that.' ), 403 );
		check_ajax_referer( 'convert_old_png', 'nonce' );
		$result = $this->convert_attachment( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );
		if( $result['status'] === 'converted' ){
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result['reason'], 422 );
	}

	function update_image_data(){
		$id = $this->image['ID'];
		$old_name = basename( $this->image['link'] );
		$new_name = basename( $this->image['new_url'] );
		$old_name_clean = preg_replace( '/\.png$/i', '', $old_name );
		$new_name_clean = preg_replace( '/\.jpg$/i', '', $new_name );

		// match URLs including the upload sub-folder, so 2023/05/a.png does not rewrite 2024/01/a.png
		$old_file_meta = get_post_meta( $id, '_wp_attached_file', true );
		$folder = dirname( $old_file_meta );
		$folder = $folder === '.' ? '' : $folder . '/';

		$replaces = array( $folder . $old_name => $folder . $new_name );

		$old_meta = wp_get_attachment_metadata( $id );
		$old_thumbs = array();
		if( is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) && is_array( $old_meta['sizes'] ) ){
			foreach( $old_meta['sizes'] as $size ){
				if( empty( $size['file'] ) || ! preg_match( '/\.png$/i', $size['file'] ) ) continue;
				$new_thumb = preg_replace( '/\.png$/i', '.jpg', $size['file'] );
				if( $old_name_clean !== $new_name_clean && strpos( $new_thumb, $old_name_clean ) === 0 ){
					$new_thumb = $new_name_clean . substr( $new_thumb, strlen( $old_name_clean ) );
				}
				$replaces[ $folder . $size['file'] ] = $folder . $new_thumb;
				$old_thumbs[] = dirname( $this->image['path'] ) . '/' . $size['file'];
			}
		}

		wp_update_post( array(
			'ID' => $id,
			'post_mime_type' => 'image/jpeg'
		) );

		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'guid' => $this->image['new_url'] ),
			array( 'ID' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		if( substr( $old_file_meta, -strlen( $old_name ) ) === $old_name ){
			$new_file_meta = substr( $old_file_meta, 0, -strlen( $old_name ) ) . $new_name;
			update_post_meta( $id, '_wp_attached_file', $new_file_meta );
		}

		require_once( ABSPATH . 'wp-admin/includes/image.php' );
		$attach_data = wp_generate_attachment_metadata( $id, $this->image['new_path'] );
		if( is_array( $attach_data ) && is_array( $old_meta ) && ! empty( $old_meta['original_image'] ) ){
			$attach_data['original_image'] = $old_meta['original_image']; // keep the big-image-threshold original
		}
		wp_update_attachment_metadata( $id, $attach_data );

		foreach( array_unique( $old_thumbs ) as $thumb ){
			if( file_exists( $thumb ) ) unlink( $thumb );
		}

		$this->replace_urls( apply_filters( 'png_to_jpg_replacements', $replaces, $id ) );
	}

	/**
	 * $replaces: array( 'path/old.png' => 'path/new.jpg' ), relative to the uploads folder
	 */
	function replace_urls( $replaces ){
		global $wpdb;

		// all pairs go into ONE UPDATE per column, so every table is scanned once per image instead of once per size
		$pairs = array();
		foreach( $replaces as $old => $new ){
			$pairs[ '/' . $old ] = '/' . $new;
			$pairs[ str_replace( '/', '\\/', '/' . $old ) ] = str_replace( '/', '\\/', '/' . $new ); // JSON escaped slashes ( page builders )
		}

		$targets = array(
			$wpdb->posts => array( 'post_content', 'post_excerpt' ),
			$wpdb->postmeta => array( 'meta_value' ),
			$wpdb->options => array( 'option_value' ),
		);
		$optional = array(
			'yoast_seo_links' => array( 'url' ),
			'revslider_slides' => array( 'params', 'layers' ),
			'revslider_slides7' => array( 'params', 'layers', 'settings' ),
			'revslider_static_slides' => array( 'layers' ),
			'toolset_post_guid_id' => array( 'guid' ),
			'fpd_products' => array( 'thumbnail' ),
			'fpd_views' => array( 'thumbnail', 'elements' ),
			'blc_instances' => array( 'link_text', 'raw_url' ),
			'blc_links' => array( 'url', 'final_url', 'log', 'result_hash' ),
			'fv_player_videos' => array( 'splash' ),
		);
		foreach( $optional as $name => $columns ){
			if( $this->table_exists( $wpdb->prefix . $name ) ){
				$targets[ $wpdb->prefix . $name ] = $columns;
			}
		}

		foreach( apply_filters( 'png_to_jpg_db_targets', $targets ) as $table => $columns ){
			foreach( $columns as $column ){
				$this->replace_in_column( $table, $column, $pairs );
			}
		}

		// raw queries bypass the object cache
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if( function_exists( 'wp_cache_set_posts_last_changed' ) ) wp_cache_set_posts_last_changed();
	}

	function replace_in_column( $table, $column, $pairs ){
		global $wpdb;
		$expr = "`{$column}`";
		$set_args = array();
		$where = array();
		$where_args = array();
		foreach( $pairs as $search => $replace ){
			$expr = "REPLACE( {$expr}, %s, %s )";
			$set_args[] = $search;
			$set_args[] = $replace;
			$where[] = "`{$column}` LIKE %s";
			$where_args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}
		$sql = "UPDATE `{$table}` SET `{$column}` = {$expr} WHERE " . implode( ' OR ', $where );
		$wpdb->query( $wpdb->prepare( $sql, array_merge( $set_args, $where_args ) ) );
	}
}

$png_to_jpg_var = new png_to_jpg();
register_activation_hook( __FILE__, array( $png_to_jpg_var, 'activate' ) );

if( defined( 'WP_CLI' ) && WP_CLI ){
	require_once __DIR__ . '/includes/class-png-to-jpg-cli.php';
	WP_CLI::add_command( 'png-to-jpg', new png_to_jpg_cli( $png_to_jpg_var ) );
}
