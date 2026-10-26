<?php
if( ! defined( 'WP_CLI' ) || ! WP_CLI ) return;

/**
 * Convert PNG images in the media library to JPG.
 */
class png_to_jpg_cli{
	private $plugin;

	function __construct( $plugin ){
		$this->plugin = $plugin;
	}

	/**
	 * Convert one or more PNG attachments to JPG by attachment ID.
	 *
	 * Converts the file and its thumbnails, updates the attachment and replaces
	 * the image URLs in the database. Settings from Media > PNG to JPG are used
	 * unless overridden by the flags below.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * [--quality=<quality>]
	 * : JPG quality 1-100. Default: saved plugin setting.
	 *
	 * [--force]
	 * : Convert even if the JPG is not smaller than the PNG.
	 *
	 * [--skip-transparent]
	 * : Skip PNGs that have transparency ( they would get a white background ).
	 *
	 * [--keep-original]
	 * : Keep the original PNG file on the server.
	 *
	 * [--delete-original]
	 * : Delete the original PNG file after conversion.
	 *
	 * [--dry-run]
	 * : Do not change anything, only report the estimated savings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp png-to-jpg convert 123
	 *     wp png-to-jpg convert 123 124 125 --quality=80 --skip-transparent
	 *     wp png-to-jpg convert 123 --dry-run
	 */
	function convert( $args, $assoc_args ){
		$options = $this->parse_options( $assoc_args );
		$failed = 0;
		foreach( $args as $id ){
			if( ! ctype_digit( (string) $id ) ){
				WP_CLI::warning( sprintf( '"%s" is not a valid attachment ID.', $id ) );
				++$failed;
				continue;
			}
			$result = $this->plugin->convert_attachment( (int) $id, $options );
			$this->log_result( $result );
			if( $result['status'] === 'failed' ) ++$failed;
			$this->free_memory();
		}
		if( $failed ){
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Convert all PNG attachments in the media library to JPG.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum number of PNGs to process. Default: all.
	 *
	 * [--quality=<quality>]
	 * : JPG quality 1-100. Default: saved plugin setting.
	 *
	 * [--force]
	 * : Convert even if the JPG is not smaller than the PNG.
	 *
	 * [--skip-transparent]
	 * : Skip PNGs that have transparency ( they would get a white background ).
	 *
	 * [--keep-original]
	 * : Keep the original PNG files on the server.
	 *
	 * [--delete-original]
	 * : Delete the original PNG files after conversion.
	 *
	 * [--dry-run]
	 * : Do not change anything, only report the estimated savings.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--format=<format>]
	 * : Print one result row per image at the end instead of a log line per image.
	 * ---
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp png-to-jpg bulk --dry-run
	 *     wp png-to-jpg bulk --skip-transparent --yes
	 *     wp png-to-jpg bulk --limit=50 --quality=85 --format=csv
	 */
	function bulk( $args, $assoc_args ){
		global $wpdb;
		$options = $this->parse_options( $assoc_args );
		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		if( isset( $assoc_args['limit'] ) && $limit < 1 ){
			WP_CLI::error( '--limit must be a positive number.' );
		}

		$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/png' ORDER BY ID ASC";
		if( $limit ){
			$sql = $wpdb->prepare( $sql . ' LIMIT %d', $limit );
		}
		$ids = $wpdb->get_col( $sql );
		if( ! $ids ){
			WP_CLI::success( 'No PNG attachments found.' );
			return;
		}

		if( empty( $options['dry_run'] ) ){
			WP_CLI::confirm( sprintf( 'This alters original images and rewrites URLs in the database and cannot be undone. Do you have a backup? Convert %d PNG(s)?', count( $ids ) ), $assoc_args );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : '';
		$progress = $format ? \WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) ) : null;
		$rows = array();
		$totals = array( 'converted' => 0, 'dry-run' => 0, 'skipped' => 0, 'failed' => 0, 'saved' => 0 );

		foreach( $ids as $id ){
			$result = $this->plugin->convert_attachment( (int) $id, $options );
			++$totals[ $result['status'] ];
			if( $result['status'] === 'converted' || $result['status'] === 'dry-run' ){
				$totals['saved'] += $result['saved'];
			}
			if( $format ){
				$rows[] = $result;
				$progress->tick();
			}else{
				$this->log_result( $result );
			}
			$this->free_memory();
		}

		if( $progress ){
			$progress->finish();
			\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'file', 'status', 'before', 'after', 'saved', 'reason' ) );
		}

		$verb = empty( $options['dry_run'] ) ? 'converted' : 'dry-run';
		WP_CLI::log( sprintf(
			'%s: %d, skipped: %d, failed: %d, %s: %s',
			empty( $options['dry_run'] ) ? 'Converted' : 'Would convert',
			$totals[ $verb ],
			$totals['skipped'],
			$totals['failed'],
			empty( $options['dry_run'] ) ? 'saved' : 'estimated savings',
			$this->size( $totals['saved'] )
		) );

		if( $totals['failed'] ){
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Explain whether a PNG attachment has transparency, and exactly why.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more attachment IDs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp png-to-jpg inspect 123
	 */
	function inspect( $args, $assoc_args ){
		foreach( $args as $id ){
			$id = (int) $id;
			$file = get_post_mime_type( $id ) == 'image/png' ? get_attached_file( $id ) : '';
			if( ! $file || ! file_exists( $file ) ){
				WP_CLI::warning( sprintf( '#%d is not a PNG attachment with an existing file.', $id ) );
				continue;
			}
			$report = $this->plugin->png_transparency_report( $file );
			WP_CLI::log( sprintf( '#%d %s: %s - %s', $id, basename( $file ), $report['transparent'] ? 'TRANSPARENT' : 'OPAQUE', $report['summary'] ) );
		}
	}

	/**
	 * Show conversion statistics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp png-to-jpg stats
	 */
	function stats( $args, $assoc_args ){
		global $wpdb;
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type = 'image/png'" );
		$stats = $wpdb->get_row( "SELECT COUNT(*) AS converted, SUM( meta_value ) AS saved FROM {$wpdb->postmeta} WHERE meta_key = 'png_converted'" );
		WP_CLI::log( sprintf( 'Converted images: %d', $stats->converted ) );
		WP_CLI::log( sprintf( 'Saved: %s', $this->size( (int) $stats->saved ) ) );
		WP_CLI::log( sprintf( 'PNG attachments left: %d', $remaining ) );
	}

	private function parse_options( $assoc_args ){
		$options = array();
		if( isset( $assoc_args['quality'] ) ){
			$quality = (int) $assoc_args['quality'];
			if( $quality < 1 || $quality > 100 ){
				WP_CLI::error( '--quality must be between 1 and 100.' );
			}
			$options['quality'] = $quality;
		}
		if( isset( $assoc_args['keep-original'] ) && isset( $assoc_args['delete-original'] ) ){
			WP_CLI::error( 'Use either --keep-original or --delete-original, not both.' );
		}
		if( isset( $assoc_args['keep-original'] ) ) $options['leave_original'] = true;
		if( isset( $assoc_args['delete-original'] ) ) $options['leave_original'] = false;
		$options['force'] = ! empty( $assoc_args['force'] );
		$options['skip_transparent'] = ! empty( $assoc_args['skip-transparent'] );
		$options['dry_run'] = ! empty( $assoc_args['dry-run'] );
		return $options;
	}

	private function log_result( $result ){
		$label = '#' . $result['id'] . ( $result['file'] ? ' ' . $result['file'] : '' );
		switch( $result['status'] ){
			case 'converted':
				WP_CLI::log( sprintf( 'Converted %s: %s -> %s ( saved %s )', $label, $this->size( $result['before'] ), $this->size( $result['after'] ), $this->size( $result['saved'] ) ) );
				break;
			case 'dry-run':
				WP_CLI::log( sprintf( 'Would convert %s: %s -> ~%s ( saves ~%s )', $label, $this->size( $result['before'] ), $this->size( $result['after'] ), $this->size( $result['saved'] ) ) );
				break;
			case 'skipped':
				WP_CLI::log( sprintf( 'Skipped %s: %s', $label, $result['reason'] ) );
				break;
			default:
				WP_CLI::warning( sprintf( 'Failed %s: %s', $label, $result['reason'] ) );
		}
	}

	private function size( $bytes ){
		$prefix = $bytes < 0 ? '-' : '';
		return $prefix . size_format( abs( $bytes ), 2 );
	}

	// long bulk runs: do not let the query log grow
	private function free_memory(){
		global $wpdb;
		if( ! empty( $wpdb->queries ) ) $wpdb->queries = array();
	}
}
