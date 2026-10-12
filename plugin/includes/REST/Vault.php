<?php
/**
 * REST Vault controller — history & rollback endpoints.
 *
 * Routes:
 *   GET  /history                 list recent manifests
 *   GET  /history/{id}            inspect one manifest (changes, log, error)
 *   POST /rollback                restore: put the whole theme back to how it
 *                                 was before an entry (undoes it and every
 *                                 later one)
 *   POST /snapshot/{id}/revert    revert: undo only what that one push changed,
 *                                 keeping later pushes
 *   GET  /snapshot/{id}/diff      diff of one file (what the push changes, or
 *                                 what restoring the snapshot would change)
 *   POST /snapshot/{id}/apply     approve a push held for review
 *   POST /snapshot/{id}/reject    reject a push held for review
 *   POST /snapshot/delete         delete entries: { ids: [...] } or { all: true }
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Vault\Manager;
use WPicker\Sync\PathGuard;
use WPicker\Sync\Deployer;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Vault
 */
final class Vault extends Base {

	/**
	 * Vault manager.
	 *
	 * @var Manager
	 */
	private $vault;

	/**
	 * Path guard.
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Deployer.
	 *
	 * @var Deployer
	 */
	private $deployer;

	/**
	 * Largest file (bytes) the diff endpoint will render.
	 */
	private const DIFF_MAX_BYTES = 512000;

	/**
	 * Most rows the diff endpoint will return for one file.
	 */
	private const DIFF_MAX_ROWS = 4000;

	/**
	 * Files per entry that get line counts in the detail response.
	 */
	private const DETAIL_MAX_FILES = 100;

	/**
	 * Unchanged lines shown either side of a change.
	 */
	private const DIFF_CONTEXT = 3;

	/**
	 * Constructor.
	 *
	 * @param Manager   $vault    Vault manager.
	 * @param PathGuard $guard    Path guard.
	 * @param Deployer  $deployer Deployer.
	 */
	public function __construct( Manager $vault, PathGuard $guard, Deployer $deployer ) {
		$this->vault    = $vault;
		$this->guard    = $guard;
		$this->deployer = $deployer;
	}

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/history',
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			),
			array(
				'path'                => '/history/(?P<id>[0-9a-zA-Z\-]+)',
				'methods'             => 'GET',
				'callback'            => array( $this, 'one' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'path'                => '/rollback',
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'manifest_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'device'      => array( 'type' => 'object', 'required' => false, 'default' => array() ),
				),
			),
			array(
				'path'                => '/snapshot/(?P<id>[0-9a-zA-Z\-]+)/diff',
				'methods'             => 'GET',
				'callback'            => array( $this, 'diff' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'path' => array( 'type' => 'string', 'required' => true ),
					'mode' => array( 'type' => 'string', 'default' => 'change', 'enum' => array( 'change', 'restore' ) ),
				),
			),
			array(
				'path'                => '/snapshot/(?P<id>[0-9a-zA-Z\-]+)/apply',
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'permission_review' ),
				'args'                => array(
					'resolve' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			),
			array(
				'path'                => '/snapshot/(?P<id>[0-9a-zA-Z\-]+)/revert',
				'methods'             => 'POST',
				'callback'            => array( $this, 'revert' ),
				'permission_callback' => array( $this, 'permission_review' ),
				'args'                => array(
					'resolve' => array(
						'type'    => 'object',
						'default' => array(),
					),
				),
			),
			array(
				'path'                => '/snapshot/delete',
				'methods'             => 'POST',
				'callback'            => array( $this, 'delete' ),
				'permission_callback' => array( $this, 'permission_review' ),
				'args'                => array(
					'ids' => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
					'all' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			),
			array(
				'path'                => '/snapshot/(?P<id>[0-9a-zA-Z\-]+)/reject',
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'permission_review' ),
			),
		);
	}

	/**
	 * Permission for approving / rejecting a held push and for deleting
	 * entries: an administrator signed in to WP-Admin. A device's Application
	 * Password is refused, or a device could approve its own push — or erase
	 * the snapshots that exist to undo what it did.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_review() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error(
				'wpicker_review_requires_admin_session',
				__( 'Held pushes can only be approved or rejected from WP-Admin.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
		return wpicker()->caps->rest_manage();
	}

	/**
	 * Permission callback: require edit_themes.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		return wpicker()->caps->rest_sync_files();
	}

	/**
	 * GET /history — list recent manifests (newest first).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$all   = wpicker()->manifests->all();
		$all   = array_slice( array_reverse( $all ), 0, $limit );

		// Slim each record for the list view.
		$slim = array_map(
			static function ( array $m ): array {
				return array(
					'id'             => $m['id'] ?? '',
					'kind'           => $m['kind'] ?? 'push',
					'created_at_gmt' => $m['created_at_gmt'] ?? '',
					'device_name'    => $m['device_name'] ?? '',
					'count'          => $m['count'] ?? 0,
					'status'         => $m['status'] ?? '',
					'restore_count'  => $m['restore_count'] ?? 0,
					'changes'        => $m['changes'] ?? array(),
				);
			},
			$all
		);

		return $this->ok( array( 'manifests' => $slim, 'count' => count( $slim ) ) );
	}

	/**
	 * GET /history/{id} — full manifest including file list and error log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function one( WP_REST_Request $request ): WP_REST_Response {
		$id   = (string) $request->get_param( 'id' );
		$m    = wpicker()->manifests->get( $id );
		if ( null === $m ) {
			return $this->fail( 'wpicker_manifest_not_found', 'Manifest not found.', 404 );
		}
		$m['snapshot_dir'] = $this->vault->dir_for( $id );
		$m['snapshot_exists'] = is_dir( $m['snapshot_dir'] );
		$m['can_revert']   = $m['snapshot_exists'] && $this->revertible( $m );

		// Where the files live, for display: "wp-content/themes/my-child".
		$m['theme_path'] = ltrim( str_replace( untrailingslashit( wp_normalize_path( ABSPATH ) ), '', wp_normalize_path( $this->guard->theme_root() ) ), '/' );

		// Per-file detail for the list: lines added/removed and size.
		$changes = array_slice( (array) ( $m['changes'] ?? array() ), 0, self::DETAIL_MAX_FILES );
		foreach ( $changes as $i => $change ) {
			$cmp = $this->compare( $m, (string) ( $change['path'] ?? '' ), 'change', false );
			if ( null === $cmp ) {
				continue;
			}
			$changes[ $i ]['added']     = $cmp['added'];
			$changes[ $i ]['removed']   = $cmp['removed'];
			$changes[ $i ]['binary']    = $cmp['binary'];
			$changes[ $i ]['too_large'] = $cmp['too_large'];
			$changes[ $i ]['bytes']     = $cmp['right_exists'] ? $cmp['right_bytes'] : $cmp['left_bytes'];
		}
		if ( array() !== $changes ) {
			$m['changes'] = array_merge( $changes, array_slice( (array) $m['changes'], self::DETAIL_MAX_FILES ) );
		}

		return $this->ok( $m );
	}
	/**
	 * POST /rollback — restore a snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rollback( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'manifest_id' );
		$device = (array) $request->get_param( 'device' );
		$device = $device + array( 'name' => 'unknown' );

		try {
			$result = $this->vault->restore( $id, $device );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_rollback_failed', $e->getMessage(), 500, array( 'manifest_id' => $id ) );
		}

		wpicker()->manifests->log( $id, 'restored', 'Restored by ' . $device['name'] . '. Safety snapshot: ' . $result['safety_manifest_id'] . '.' );

		return $this->ok( $result );
	}

	/**
	 * POST /snapshot/{id}/apply — approve a push held for review.
	 *
	 * A target file that changed on the site since the push was submitted is
	 * a conflict: the request is answered 409 with the list, and is sent
	 * again with `resolve: { path: "apply" | "skip" }` once the reviewer has
	 * decided for each one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response {
		$id       = (string) $request->get_param( 'id' );
		$manifest = wpicker()->manifests->get( $id );
		if ( null === $manifest || 'review' !== ( $manifest['status'] ?? '' ) ) {
			return $this->fail( 'wpicker_not_in_review', 'This snapshot is not waiting for review.', 409, array( 'manifest_id' => $id ) );
		}
		$staged = $this->vault->staged( $id );
		if ( null === $staged ) {
			return $this->fail( 'wpicker_staged_missing', 'The staged files for this push are gone.', 410, array( 'manifest_id' => $id ) );
		}

		$body = array(
			'files'  => array(),
			'delete' => array_column( $staged['delete'], 'path' ),
		);
		foreach ( $staged['files'] as $f ) {
			$body['files'][] = array(
				'path'     => $f['path'],
				'contents' => $f['contents'],
			);
		}
		$plan = $this->deployer->plan( $body );
		if ( isset( $plan['error'] ) ) {
			return $this->fail_from( $plan['error'] );
		}

		// A file that changed on the site after the push was submitted is a
		// conflict: what the reviewer saw in the diff is no longer the whole
		// truth. The reviewer decides per file — "apply" (the push wins,
		// overwriting the newer change) or "skip" (the site keeps its file).
		$resolve  = (array) $request->get_param( 'resolve' );
		$expected = array_column( array_merge( $staged['files'], $staged['delete'] ), 'before', 'path' );
		$open     = array();
		$skipped  = array();
		foreach ( array( 'files', 'remove' ) as $group ) {
			foreach ( $plan[ $group ] as $i => $target ) {
				$now = is_file( $target['abs'] ) ? hash_file( 'sha256', $target['abs'] ) : null;
				if ( $now === ( $expected[ $target['rel'] ] ?? null ) ) {
					continue;
				}
				$decision = $resolve[ $target['rel'] ] ?? '';
				if ( 'skip' === $decision ) {
					$skipped[] = $target['rel'];
					unset( $plan[ $group ][ $i ] );
				} elseif ( 'apply' !== $decision ) {
					$open[] = $target['rel'];
				}
			}
			$plan[ $group ] = array_values( $plan[ $group ] );
		}
		if ( array() !== $open ) {
			return $this->conflict( 'wpicker_stale', 'The site changed since this push was submitted.', $id, $open );
		}
		if ( array() === $plan['files'] && array() === $plan['remove'] ) {
			return $this->fail( 'wpicker_nothing_left', 'Every file was skipped, so there is nothing to apply. Reject the push instead.', 400, array( 'manifest_id' => $id ) );
		}

		$device = array(
			'id'   => $manifest['device_id'] ?? '',
			'name' => $manifest['device_name'] ?? 'unknown',
		);
		wpicker()->manifests->log( $id, 'approved', 'Approved in WP-Admin.' . self::skipped_note( $skipped ) );
		$result = $this->deployer->execute( $plan['files'], $plan['remove'], $device, $id );
		$this->vault->discard_staged( $id );

		if ( isset( $result['error'] ) ) {
			return $this->fail_from( $result['error'] );
		}
		return $this->ok( $result['data'] );
	}

	/**
	 * POST /snapshot/{id}/reject — reject a push held for review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function reject( WP_REST_Request $request ): WP_REST_Response {
		$id       = (string) $request->get_param( 'id' );
		$manifest = wpicker()->manifests->get( $id );
		if ( null === $manifest || 'review' !== ( $manifest['status'] ?? '' ) ) {
			return $this->fail( 'wpicker_not_in_review', 'This snapshot is not waiting for review.', 409, array( 'manifest_id' => $id ) );
		}
		$manifest['status'] = 'rejected';
		wpicker()->manifests->update( $id, $manifest );
		wpicker()->manifests->log( $id, 'rejected', 'Rejected in WP-Admin.' );
		$this->vault->discard_staged( $id );

		return $this->ok(
			array(
				'manifest_id' => $id,
				'status'      => 'rejected',
			)
		);
	}

	/**
	 * Can this entry be reverted? Only an applied push (or revert) that
	 * recorded what it changed.
	 *
	 * @param array<string,mixed> $manifest Manifest record.
	 * @return bool
	 */
	private function revertible( array $manifest ): bool {
		return 'applied' === ( $manifest['status'] ?? '' )
			&& in_array( $manifest['kind'] ?? '', array( 'push', 'revert' ), true )
			&& array() !== (array) ( $manifest['changes'] ?? array() );
	}

	/**
	 * POST /snapshot/{id}/revert — undo what one applied push changed, and
	 * nothing else.
	 *
	 * For each file the push touched: a file it added is deleted, a file it
	 * modified gets its previous content back, a file it deleted is recreated.
	 * Pushes made afterwards are kept — which is the difference from restore
	 * (POST /rollback), where the whole theme goes back in time.
	 *
	 * A file that changed again since is a conflict — undoing it would also
	 * throw away the later edit. The request is answered 409 with the list
	 * and sent again with `resolve: { path: "apply" | "skip" }`: "apply"
	 * undoes that file anyway, "skip" leaves it as it is now. The undo is
	 * applied as a new entry (kind "revert") through the same lint → snapshot
	 * → apply → health-check path as a push, so it can itself be reverted or
	 * restored.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function revert( WP_REST_Request $request ): WP_REST_Response {
		$id       = (string) $request->get_param( 'id' );
		$manifest = wpicker()->manifests->get( $id );
		if ( null === $manifest || ! is_dir( $this->vault->dir_for( $id ) ) || ! $this->revertible( $manifest ) ) {
			return $this->fail( 'wpicker_not_revertible', 'Only an applied push whose snapshot still exists can be reverted.', 409, array( 'manifest_id' => $id ) );
		}

		$before = $this->vault->dir_for( $id );
		$after  = $this->vault->after_dir( $id );
		$body    = array(
			'files'  => array(),
			'delete' => array(),
		);
		$resolve = (array) $request->get_param( 'resolve' );
		$moved   = array();
		$skipped = array();

		foreach ( (array) $manifest['changes'] as $change ) {
			$rel     = (string) ( $change['path'] ?? '' );
			$action  = (string) ( $change['action'] ?? '' );
			$current = $this->guard->resolve( $rel );
			if ( null === $current ) {
				return $this->fail( 'wpicker_path_blocked', 'Refused path outside child theme or blocked basename.', 400, array( 'file' => $rel ) );
			}

			// What the push left behind: nothing for a delete, else its "after" copy.
			$left    = 'deleted' === $action ? null : ( is_file( $after . '/' . $rel ) ? hash_file( 'sha256', $after . '/' . $rel ) : false );
			$now     = is_file( $current ) ? hash_file( 'sha256', $current ) : null;
			if ( false === $left ) {
				return $this->fail( 'wpicker_not_revertible', 'This entry predates revert support (no record of what it wrote). Use restore instead.', 409, array( 'manifest_id' => $id ) );
			}
			// The file changed again after this push. Per file the admin picks
			// "apply" (undo anyway: the later change is lost too) or "skip"
			// (leave this file as it is now).
			if ( $now !== $left ) {
				$decision = $resolve[ $rel ] ?? '';
				if ( 'skip' === $decision ) {
					$skipped[] = $rel;
					continue;
				}
				if ( 'apply' !== $decision ) {
					$moved[] = $rel;
					continue;
				}
			}

			if ( 'added' === $action ) {
				if ( null !== $now ) {
					$body['delete'][] = $rel;
				}
			} elseif ( is_file( $before . '/' . $rel ) ) {
				$body['files'][] = array(
					'path'     => $rel,
					'contents' => (string) file_get_contents( $before . '/' . $rel ),
				);
			}
		}

		if ( array() !== $moved ) {
			return $this->conflict( 'wpicker_revert_conflict', 'These files changed again after this push.', $id, $moved );
		}
		if ( array() === $body['files'] && array() === $body['delete'] ) {
			return $this->fail( 'wpicker_nothing_left', 'Every file was skipped, so there is nothing to revert.', 400, array( 'manifest_id' => $id ) );
		}

		$plan = $this->deployer->plan( $body );
		if ( isset( $plan['error'] ) ) {
			return $this->fail_from( $plan['error'] );
		}
		$lint = $this->deployer->lint( $plan['files'] );
		if ( null !== $lint ) {
			return $this->fail_from( $lint['error'] );
		}

		$user   = wp_get_current_user();
		$device = array(
			'id'   => '',
			'name' => 'admin: ' . $user->user_login,
		);
		$result = $this->deployer->execute( $plan['files'], $plan['remove'], $device, null, 'revert' );
		if ( isset( $result['error'] ) ) {
			return $this->fail_from( $result['error'] );
		}

		$new_id = (string) $result['data']['manifest_id'];
		$record = wpicker()->manifests->get( $new_id );
		if ( null !== $record ) {
			$record['note'] = 'Revert of ' . $id;
			wpicker()->manifests->update( $new_id, $record );
		}
		$manifest['status'] = 'reverted';
		wpicker()->manifests->update( $id, $manifest );
		wpicker()->manifests->log( $id, 'reverted', 'Undone by ' . $new_id . '.' . self::skipped_note( $skipped ) );

		$result['data']['reverted'] = $id;
		return $this->ok( $result['data'] );
	}

	/**
	 * Uniform "some files collide" response. The client shows the files and
	 * sends the request again with a decision for each:
	 *   resolve: { "<path>": "apply" | "skip" }
	 *
	 * @param string            $code    Error code.
	 * @param string            $message Message.
	 * @param string            $id      Manifest id.
	 * @param array<int,string> $files   Conflicting theme-relative paths.
	 * @return WP_REST_Response
	 */
	private function conflict( string $code, string $message, string $id, array $files ): WP_REST_Response {
		return $this->fail(
			$code,
			$message . ' Decide for each file: ' . implode( ', ', $files ) . '.',
			409,
			array(
				'manifest_id' => $id,
				'files'       => array_values( $files ),
				'resolvable'  => true,
			)
		);
	}

	/**
	 * Log suffix naming the files a resolution left untouched.
	 *
	 * @param array<int,string> $skipped Paths.
	 * @return string
	 */
	private static function skipped_note( array $skipped ): string {
		return array() === $skipped ? '' : ' Left as they are (conflict, kept current): ' . implode( ', ', $skipped ) . '.';
	}

	/**
	 * POST /snapshot/delete — permanently delete entries and their snapshots.
	 *
	 * Body: { ids: [ id, ... ] } for a selection, or { all: true }. Deleting a
	 * push that is waiting for review discards it. The theme is not changed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function delete( WP_REST_Request $request ): WP_REST_Response {
		$ids = array_map( 'strval', (array) $request->get_param( 'ids' ) );
		if ( $request->get_param( 'all' ) ) {
			$ids = array_column( wpicker()->manifests->all(), 'id' );
		}
		if ( array() === $ids ) {
			return $this->fail( 'wpicker_nothing_selected', 'No snapshots selected.', 400 );
		}

		$deleted = array();
		foreach ( array_unique( $ids ) as $id ) {
			// Ids are also directory names: accept only the shape we generate.
			if ( preg_match( '/^[0-9a-zA-Z\-]+$/', $id ) && $this->vault->delete( $id ) ) {
				$deleted[] = $id;
			}
		}

		return $this->ok(
			array(
				'deleted' => $deleted,
				'count'   => count( $deleted ),
			)
		);
	}

	/**
	 * GET /snapshot/{id}/diff?path=...&mode=change|restore — diff of one file.
	 *
	 *   change   what the push did (snapshot → after), or for a push still in
	 *            review what it would do (current site → proposed).
	 *   restore  what restoring this snapshot would do (current site → snapshot).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function diff( WP_REST_Request $request ): WP_REST_Response {
		$manifest = wpicker()->manifests->get( (string) $request->get_param( 'id' ) );
		$data     = null === $manifest
			? null
			: $this->compare( $manifest, (string) $request->get_param( 'path' ), (string) $request->get_param( 'mode' ), true );
		if ( null === $data ) {
			return $this->fail( 'wpicker_manifest_not_found', 'Snapshot or path not found.', 404 );
		}
		return $this->ok( $data );
	}

	/**
	 * Compare the two versions of one file that an entry is about.
	 *
	 * @param array<string,mixed> $manifest  Manifest record.
	 * @param string              $rel       Theme-relative path.
	 * @param string              $mode      "change" or "restore" (see diff()).
	 * @param bool                $with_rows Include the diff rows, not only the counts.
	 * @return array<string,mixed>|null Null if the path is not allowed.
	 */
	private function compare( array $manifest, string $rel, string $mode, bool $with_rows ): ?array {
		$id      = (string) $manifest['id'];
		$current = $this->guard->resolve( $rel );
		if ( null === $current ) {
			return null;
		}

		$snapshot = $this->vault->dir_for( $id ) . '/' . $rel;
		$deleted  = false;
		foreach ( (array) ( $manifest['changes'] ?? array() ) as $change ) {
			if ( $rel === ( $change['path'] ?? '' ) && 'deleted' === ( $change['action'] ?? '' ) ) {
				$deleted = true;
			}
		}

		if ( 'restore' === $mode ) {
			$sides = array( $current, $snapshot, __( 'Current site', 'wpicker' ), __( 'This snapshot', 'wpicker' ) );
		} elseif ( 'review' === ( $manifest['status'] ?? '' ) ) {
			$proposed = $deleted ? '' : $this->vault->staged_dir( $id ) . '/files/' . $rel;
			$sides    = array( $current, $proposed, __( 'Current site', 'wpicker' ), __( 'Proposed', 'wpicker' ) );
		} else {
			$after = $deleted ? '' : $this->vault->after_dir( $id ) . '/' . $rel;
			$sides = array( $snapshot, $after, __( 'Before this push', 'wpicker' ), __( 'After this push', 'wpicker' ) );
		}
		list( $left_path, $right_path, $left_title, $right_title ) = $sides;

		$left  = '' !== $left_path && is_file( $left_path ) ? (string) file_get_contents( $left_path ) : null;
		$right = '' !== $right_path && is_file( $right_path ) ? (string) file_get_contents( $right_path ) : null;

		$data = array(
			'path'        => $rel,
			'mode'        => $mode,
			'left_title'  => $left_title,
			'right_title' => $right_title,
			'left_exists' => null !== $left,
			'right_exists' => null !== $right,
			'left_bytes'  => null === $left ? 0 : strlen( $left ),
			'right_bytes' => null === $right ? 0 : strlen( $right ),
			'identical'   => $left === $right,
			'binary'      => false,
			'too_large'   => false,
			'added'       => 0,
			'removed'     => 0,
			'rows'        => array(),
		);

		if ( $data['identical'] ) {
			return $data;
		}
		foreach ( array( $left, $right ) as $side ) {
			if ( null === $side ) {
				continue;
			}
			if ( strlen( $side ) > self::DIFF_MAX_BYTES ) {
				$data['too_large'] = true;
			} elseif ( false !== strpos( $side, "\0" ) || ( '' !== $side && '' === wp_check_invalid_utf8( $side ) ) ) {
				$data['binary'] = true;
			}
		}
		if ( ! $data['binary'] && ! $data['too_large'] ) {
			$diff = $this->line_diff( (string) $left, (string) $right );
			if ( count( $diff['rows'] ) > self::DIFF_MAX_ROWS ) {
				$data['too_large'] = true;
			} else {
				$data = array_merge( $data, $diff );
			}
		}
		if ( ! $with_rows ) {
			$data['rows'] = array();
		}
		return $data;
	}

	/**
	 * Line-by-line diff as rows the admin screen can lay out side by side or
	 * unified, keeping only CONTEXT lines around each change.
	 *
	 * Row shapes:
	 *   { t: "ctx", l: int, r: int, s: string }                 unchanged line
	 *   { t: "chg", l?: int, ls?: string, r?: int, rs?: string } removed (l),
	 *                                                           added (r), or
	 *                                                           both = replaced
	 *   { t: "gap", n: int }                                    n unchanged lines hidden
	 *
	 * @param string $left  Old text.
	 * @param string $right New text.
	 * @return array{ rows: array<int,array<string,mixed>>, added: int, removed: int }
	 */
	private function line_diff( string $left, string $right ): array {
		if ( ! class_exists( 'Text_Diff', false ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		$engine = new \Text_Diff( 'auto', array( self::lines( $left ), self::lines( $right ) ) );
		$all    = array();
		$l      = 1;
		$r      = 1;
		$added  = 0;
		$removed = 0;

		foreach ( $engine->getDiff() as $op ) {
			$orig  = is_array( $op->orig ) ? array_values( $op->orig ) : array();
			$final = is_array( $op->final ) ? array_values( $op->final ) : array();

			if ( $op instanceof \Text_Diff_Op_copy ) {
				foreach ( $orig as $line ) {
					$all[] = array(
						't' => 'ctx',
						'l' => $l++,
						'r' => $r++,
						's' => $line,
					);
				}
				continue;
			}

			$count = max( count( $orig ), count( $final ) );
			for ( $i = 0; $i < $count; $i++ ) {
				$row = array( 't' => 'chg' );
				if ( isset( $orig[ $i ] ) ) {
					$row['l']  = $l++;
					$row['ls'] = $orig[ $i ];
					++$removed;
				}
				if ( isset( $final[ $i ] ) ) {
					$row['r']  = $r++;
					$row['rs'] = $final[ $i ];
					++$added;
				}
				$all[] = $row;
			}
		}

		// Keep CONTEXT unchanged lines either side of every change.
		$total = count( $all );
		$keep  = array_fill( 0, $total, false );
		foreach ( $all as $i => $row ) {
			if ( 'chg' !== $row['t'] ) {
				continue;
			}
			$from = max( 0, $i - self::DIFF_CONTEXT );
			$to   = min( $total - 1, $i + self::DIFF_CONTEXT );
			for ( $k = $from; $k <= $to; $k++ ) {
				$keep[ $k ] = true;
			}
		}

		$rows   = array();
		$hidden = 0;
		foreach ( $all as $i => $row ) {
			if ( $keep[ $i ] ) {
				if ( $hidden > 0 ) {
					$rows[] = array(
						't' => 'gap',
						'n' => $hidden,
					);
					$hidden = 0;
				}
				$rows[] = $row;
			} else {
				++$hidden;
			}
		}
		if ( $hidden > 0 ) {
			$rows[] = array(
				't' => 'gap',
				'n' => $hidden,
			);
		}

		return array(
			'rows'    => $rows,
			'added'   => $added,
			'removed' => $removed,
		);
	}

	/**
	 * Split text into lines without a phantom empty last line.
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	private static function lines( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return $lines;
	}
}
