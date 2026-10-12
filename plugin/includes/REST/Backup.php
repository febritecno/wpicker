<?php
/**
 * REST Backup controller — full-site backups, each a single archive.
 *
 * Routes:
 *   GET    /backup                 list backups, newest first
 *   POST   /backup                 start one: { label, include: { db, plugins, themes, uploads } }
 *   POST   /backup/{id}/step       do a few seconds of work; repeat until done
 *   GET    /backup/{id}/download   the archive (supports Range, for resuming)
 *   DELETE /backup/{id}            delete a backup
 *   POST   /backup/upload          start receiving an archive: { name, size }
 *   POST   /backup/{id}/chunk      one piece of it (raw body, ?offset=N)
 *   POST   /backup/{id}/finish     validate and list it
 *   POST   /restore                rebuild THIS site from a backup (WP-Admin only)
 *   GET    /restore                the restore in progress, if any
 *   POST   /restore/step           continue it (authorised by the restore token)
 *   DELETE /restore                forget a finished or failed restore
 *
 * The archive contains the full database, including user records. It is
 * available to administrators only and can be switched off in Settings.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Backup\Job;
use WPicker\Backup\Restore;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Backup
 */
final class Backup extends Base {

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		$id = array(
			'id' => array(
				'type'     => 'string',
				'required' => true,
			),
		);
		return array(
			array(
				'path'                => '/backup',
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
			),
			array(
				'path'                => '/backup',
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'include' => array(
						'type'    => 'object',
						'default' => array(),
					),
					'label'   => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'path'                => '/backup/upload',
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_start' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'name' => array(
						'type'              => 'string',
						'default'           => 'backup.tar',
						'sanitize_callback' => 'sanitize_file_name',
					),
					'size' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1024,
					),
				),
			),
			array(
				'path'                => '/backup/(?P<id>[0-9a-zA-Z\-]+)/chunk',
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_chunk' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $id + array(
					'offset' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 0,
					),
				),
			),
			array(
				'path'                => '/backup/(?P<id>[0-9a-zA-Z\-]+)/finish',
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_finish' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $id,
			),
			array(
				'path'                => '/restore',
				'methods'             => 'POST',
				'callback'            => array( $this, 'restore_start' ),
				'permission_callback' => array( $this, 'permission_admin_session' ),
				'args'                => array(
					'backup_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'parts'     => array(
						'type'    => 'object',
						'default' => array(),
					),
					'safety'    => array(
						'type'    => 'boolean',
						'default' => true,
					),
					'exact'     => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
			array(
				'path'                => '/restore',
				'methods'             => 'GET',
				'callback'            => array( $this, 'restore_status' ),
				'permission_callback' => array( $this, 'permission' ),
			),
			array(
				'path'                => '/restore/step',
				'methods'             => 'POST',
				'callback'            => array( $this, 'restore_step' ),
				'permission_callback' => array( $this, 'permission_restore_token' ),
			),
			array(
				'path'                => '/restore',
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'restore_clear' ),
				'permission_callback' => array( $this, 'permission_admin_session' ),
			),
			array(
				'path'                => '/backup/(?P<id>[0-9a-zA-Z\-]+)/step',
				'methods'             => 'POST',
				'callback'            => array( $this, 'step' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $id,
			),
			array(
				'path'                => '/backup/(?P<id>[0-9a-zA-Z\-]+)/download',
				'methods'             => 'GET',
				'callback'            => array( $this, 'download' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $id,
			),
			array(
				'path'                => '/backup/(?P<id>[0-9a-zA-Z\-]+)',
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $id,
			),
		);
	}

	/**
	 * Permission callback: administrators, and only while backups are enabled.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		$settings = get_option( 'wpicker_settings', array() );
		if ( (int) ( $settings['allow_backup'] ?? 1 ) !== 1 ) {
			return new \WP_Error(
				'wpicker_backup_disabled',
				__( 'Full-site backups are switched off in WPicker → Settings.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
		return wpicker()->caps->rest_manage();
	}

	/**
	 * GET /backup — list backups, newest first.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		$backups = array();
		foreach ( Job::all() as $job ) {
			$backups[] = $job->progress();
		}
		return $this->ok(
			array(
				'backups' => $backups,
				'count'   => count( $backups ),
			)
		);
	}

	/**
	 * POST /backup — start a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response {
		if ( is_multisite() ) {
			return $this->fail( 'wpicker_multisite_unsupported', 'Backing up a multisite network is not supported.', 400 );
		}
		try {
			$job = Job::create( (array) $request->get_param( 'include' ), (string) $request->get_param( 'label' ) );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_backup_failed', $e->getMessage(), 500 );
		}
		return $this->ok( $job->progress(), 201 );
	}

	/**
	 * POST /backup/{id}/step — continue a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function step( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->fail( 'wpicker_backup_not_found', 'Backup not found.', 404 );
		}
		try {
			return $this->ok( $job->step() );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_backup_failed', $e->getMessage(), 500 );
		}
	}

	/**
	 * GET /backup/{id}/download — stream the archive.
	 *
	 * Sends the file itself rather than a JSON envelope, and honours a
	 * single-range `Range: bytes=N-` so an interrupted download can resume.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response Only on error; on success the script ends after the file.
	 */
	public function download( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->fail( 'wpicker_backup_not_found', 'Backup not found.', 404 );
		}
		if ( ! $job->done() ) {
			return $this->fail( 'wpicker_backup_not_ready', 'Backup is not finished yet.', 409 );
		}

		$path = $job->archive();
		$size = (int) filesize( $path );
		$from = 0;
		if ( preg_match( '/^bytes=(\d+)-$/', (string) $request->get_header( 'range' ), $m ) ) {
			$from = (int) $m[1];
			if ( $from >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}
		}

		$in = fopen( $path, 'rb' );
		if ( false === $in ) {
			return $this->fail( 'wpicker_backup_failed', 'Could not read the archive.', 500 );
		}

		// Nothing may be buffered or appended around the file.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		@set_time_limit( 0 );
		nocache_headers();
		status_header( $from > 0 ? 206 : 200 );
		header( 'Content-Type: application/x-tar' );
		header( 'Content-Disposition: attachment; filename="wpicker-' . $request->get_param( 'id' ) . '.tar"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $size - $from ) );
		header( 'X-Accel-Buffering: no' );
		if ( $from > 0 ) {
			header( 'Content-Range: bytes ' . $from . '-' . ( $size - 1 ) . '/' . $size );
			fseek( $in, $from );
		}
		while ( ! feof( $in ) && ! connection_aborted() ) {
			echo fread( $in, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			flush();
		}
		fclose( $in );
		exit;
	}

	/**
	 * DELETE /backup/{id} — delete a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function remove( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'id' ) );
		if ( null !== $job ) {
			$job->delete();
		}
		return $this->ok( array( 'deleted' => true ) );
	}

	/**
	 * Permission for starting a restore: an administrator signed in to
	 * WP-Admin. Restoring replaces the whole site; a paired device must not
	 * be able to do that on its own.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_admin_session() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error(
				'wpicker_restore_requires_admin_session',
				__( 'A restore can only be started from WP-Admin.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
		return $this->permission();
	}

	/**
	 * Permission for continuing a restore: the one-time token returned when
	 * it started. Half-way through, the database — and with it the user who
	 * started the restore — is replaced, so the WordPress login cannot be
	 * what authorises the remaining steps.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission_restore_token( WP_REST_Request $request ) {
		$restore = Restore::current();
		if ( null === $restore || ! $restore->authorises( (string) $request->get_header( 'x_wpicker_restore_token' ) ) ) {
			return new \WP_Error(
				'wpicker_restore_token_invalid',
				__( 'Missing or wrong restore token.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST /backup/upload — start receiving an archive.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function upload_start( WP_REST_Request $request ): WP_REST_Response {
		try {
			$job = Job::begin_upload( (string) $request->get_param( 'name' ), (int) $request->get_param( 'size' ) );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_backup_failed', $e->getMessage(), 500 );
		}
		return $this->ok( $job->progress(), 201 );
	}

	/**
	 * POST /backup/{id}/chunk?offset=N — append a piece (raw request body).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function upload_chunk( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->fail( 'wpicker_backup_not_found', 'Backup not found.', 404 );
		}
		try {
			$received = $job->receive( (int) $request->get_param( 'offset' ), (string) $request->get_body() );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_upload_failed', $e->getMessage(), 409 );
		}
		return $this->ok( array( 'received' => $received ) );
	}

	/**
	 * POST /backup/{id}/finish — validate an upload and list it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function upload_finish( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'id' ) );
		if ( null === $job ) {
			return $this->fail( 'wpicker_backup_not_found', 'Backup not found.', 404 );
		}
		try {
			$job->finish_upload();
		} catch ( \Throwable $e ) {
			$job->delete();
			return $this->fail( 'wpicker_upload_failed', $e->getMessage(), 422 );
		}
		return $this->ok( $job->progress() );
	}

	/**
	 * POST /restore — start rebuilding this site from a backup.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function restore_start( WP_REST_Request $request ): WP_REST_Response {
		$job = Job::load( (string) $request->get_param( 'backup_id' ) );
		if ( null === $job ) {
			return $this->fail( 'wpicker_backup_not_found', 'Backup not found.', 404 );
		}
		$have  = $job->progress()['include'];
		$parts = (array) $request->get_param( 'parts' );
		$want  = array(
			'safety' => (bool) $request->get_param( 'safety' ),
			'exact'  => (bool) $request->get_param( 'exact' ),
		);
		foreach ( array( 'db', 'plugins', 'themes', 'uploads' ) as $part ) {
			// Default: everything the backup contains.
			$want[ $part ] = ! empty( $have[ $part ] ) && ( ! array_key_exists( $part, $parts ) || ! empty( $parts[ $part ] ) );
		}
		if ( ! ( $want['db'] || $want['plugins'] || $want['themes'] || $want['uploads'] ) ) {
			return $this->fail( 'wpicker_nothing_selected', 'Nothing to restore: no part selected.', 400 );
		}

		try {
			$started = Restore::start( $job, $want );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_restore_failed', $e->getMessage(), 409 );
		}
		return $this->ok( $started['restore']->progress() + array( 'token' => $started['token'] ), 201 );
	}

	/**
	 * GET /restore — the restore in progress, if any.
	 *
	 * @return WP_REST_Response
	 */
	public function restore_status(): WP_REST_Response {
		$restore = Restore::current();
		return $this->ok( array( 'restore' => null === $restore ? null : $restore->progress() ) );
	}

	/**
	 * POST /restore/step — continue the restore.
	 *
	 * @return WP_REST_Response
	 */
	public function restore_step(): WP_REST_Response {
		$restore = Restore::current();
		if ( null === $restore ) {
			return $this->fail( 'wpicker_restore_not_found', 'No restore in progress.', 404 );
		}
		@set_time_limit( 60 );
		return $this->ok( $restore->step() );
	}

	/**
	 * DELETE /restore — forget a finished or failed restore.
	 *
	 * @return WP_REST_Response
	 */
	public function restore_clear(): WP_REST_Response {
		Restore::clear();
		return $this->ok( array( 'cleared' => true ) );
	}
}
