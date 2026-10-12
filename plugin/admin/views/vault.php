<?php
/**
 * Snapshots admin view.
 *
 * One row per push or rollback. The toolbar searches and filters the rows in
 * the browser and pages them; selecting a row opens a full-width dialog
 * (filled by admin.js over REST) with the changed files, a line-by-line diff,
 * the activity log and the actions: Delete (single, selected rows, or all),
 * Restore, and — for a push held for review — Apply or Reject.
 *
 * @package WPicker\Admin
 * @var array<int,array<string,mixed>> $manifests Manifests (newest first).
 * @var string                         $push_mode "direct" or "review".
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'review'     => __( 'Needs review', 'wpicker' ),
	'applied'    => __( 'Applied', 'wpicker' ),
	'aborted'    => __( 'Failed', 'wpicker' ),
	'reverted'   => __( 'Reverted', 'wpicker' ),
	'restored'   => __( 'Restored', 'wpicker' ),
	'rejected'   => __( 'Rejected', 'wpicker' ),
	'superseded' => __( 'Superseded', 'wpicker' ),
	'pending'    => __( 'Saved', 'wpicker' ),
);
$kind_labels   = array(
	'push'            => __( 'Push', 'wpicker' ),
	'revert'          => __( 'Revert', 'wpicker' ),
	'rollback_safety' => __( 'Safety backup', 'wpicker' ),
);

$counts  = array_fill_keys( array_keys( $status_labels ), 0 );
$devices = array();
foreach ( $manifests as $m ) {
	$s = (string) ( $m['status'] ?? '' );
	if ( isset( $counts[ $s ] ) ) {
		++$counts[ $s ];
	}
	if ( '' !== ( $m['device_name'] ?? '' ) ) {
		$devices[ (string) $m['device_name'] ] = true;
	}
}
ksort( $devices );

// Statuses that have a snapshot on disk to restore from.
$restorable = array( 'applied', 'reverted', 'aborted', 'restored', 'pending' );
?>
<div class="wrap wpicker-admin">
	<?php $this->render_header( \WPicker\Admin\Dashboard::SLUG_VAULT ); ?>

	<section class="wpicker-stats">
		<div class="wpicker-stat">
			<span class="wpicker-stat-value"><?php echo esc_html( (string) count( $manifests ) ); ?></span>
			<span class="wpicker-stat-label"><?php esc_html_e( 'Snapshots', 'wpicker' ); ?></span>
		</div>
		<div class="wpicker-stat<?php echo $counts['review'] > 0 ? ' is-attention' : ''; ?>">
			<span class="wpicker-stat-value"><?php echo esc_html( (string) $counts['review'] ); ?></span>
			<span class="wpicker-stat-label"><?php esc_html_e( 'Waiting for review', 'wpicker' ); ?></span>
		</div>
		<div class="wpicker-stat">
			<span class="wpicker-stat-value"><?php echo esc_html( (string) $counts['aborted'] ); ?></span>
			<span class="wpicker-stat-label"><?php esc_html_e( 'Failed pushes', 'wpicker' ); ?></span>
		</div>
		<div class="wpicker-stat">
			<span class="wpicker-stat-value wpicker-stat-text">
				<?php echo 'review' === $push_mode ? esc_html__( 'Review', 'wpicker' ) : esc_html__( 'Direct', 'wpicker' ); ?>
			</span>
			<span class="wpicker-stat-label">
				<?php esc_html_e( 'Push mode', 'wpicker' ); ?> ·
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \WPicker\Admin\Dashboard::SLUG_SETTINGS ) ); ?>"><?php esc_html_e( 'change', 'wpicker' ); ?></a>
			</span>
		</div>
	</section>

	<section class="wpicker-toolbar" id="wpicker-toolbar">
		<input type="search" id="wpicker-search" class="wpicker-search"
			placeholder="<?php esc_attr_e( 'Search snapshot ID, device or file name…', 'wpicker' ); ?>"
			aria-label="<?php esc_attr_e( 'Search snapshots', 'wpicker' ); ?>">

		<div class="wpicker-chips" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'wpicker' ); ?>">
			<button type="button" class="wpicker-chip is-active" data-status="">
				<?php esc_html_e( 'All', 'wpicker' ); ?> <span><?php echo esc_html( (string) count( $manifests ) ); ?></span>
			</button>
			<?php foreach ( $status_labels as $key => $label ) : ?>
				<?php if ( $counts[ $key ] > 0 ) : ?>
					<button type="button" class="wpicker-chip" data-status="<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $label ); ?> <span><?php echo esc_html( (string) $counts[ $key ] ); ?></span>
					</button>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>

		<select id="wpicker-filter-kind" aria-label="<?php esc_attr_e( 'Filter by type', 'wpicker' ); ?>">
			<option value=""><?php esc_html_e( 'All types', 'wpicker' ); ?></option>
			<?php foreach ( $kind_labels as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>

		<select id="wpicker-filter-device" aria-label="<?php esc_attr_e( 'Filter by device', 'wpicker' ); ?>">
			<option value=""><?php esc_html_e( 'All devices', 'wpicker' ); ?></option>
			<?php foreach ( array_keys( $devices ) as $name ) : ?>
				<option value="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $name ); ?></option>
			<?php endforeach; ?>
		</select>
	</section>

	<div class="wpicker-bulk" id="wpicker-bulk" hidden>
		<span id="wpicker-bulk-count" aria-live="polite"></span>
		<button type="button" class="button wpicker-danger" id="wpicker-delete-selected"><?php esc_html_e( 'Delete selected', 'wpicker' ); ?></button>
		<button type="button" class="button-link" id="wpicker-clear-selection"><?php esc_html_e( 'Clear selection', 'wpicker' ); ?></button>
	</div>

	<div class="wpicker-panel">
			<table class="wpicker-table" id="wpicker-vault-table">
				<thead>
					<tr>
						<th class="wpicker-col-check">
							<input type="checkbox" id="wpicker-check-all" aria-label="<?php esc_attr_e( 'Select all snapshots on this page', 'wpicker' ); ?>">
						</th>
						<th><?php esc_html_e( 'Snapshot', 'wpicker' ); ?></th>
						<th><?php esc_html_e( 'Device', 'wpicker' ); ?></th>
						<th><?php esc_html_e( 'Type', 'wpicker' ); ?></th>
						<th><?php esc_html_e( 'Changes', 'wpicker' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpicker' ); ?></th>
						<th class="wpicker-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wpicker' ); ?></span></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $manifests as $m ) : ?>
						<?php
						$id      = (string) ( $m['id'] ?? '' );
						$status  = (string) ( $m['status'] ?? '' );
						$kind    = (string) ( $m['kind'] ?? 'push' );
						$device  = (string) ( $m['device_name'] ?? '' );
						$changes = is_array( $m['changes'] ?? null ) ? $m['changes'] : array();
						$files   = array_map( 'strval', (array) ( $m['files'] ?? array() ) );
						$tally   = array(
							'added'    => 0,
							'modified' => 0,
							'deleted'  => 0,
						);
						foreach ( $changes as $change ) {
							$action = (string) ( $change['action'] ?? '' );
							if ( isset( $tally[ $action ] ) ) {
								++$tally[ $action ];
							}
						}
						$haystack = strtolower( $id . ' ' . $device . ' ' . implode( ' ', $files ) );
						?>
						<tr class="wpicker-row" tabindex="0"
							data-id="<?php echo esc_attr( $id ); ?>"
							data-status="<?php echo esc_attr( $status ); ?>"
							data-kind="<?php echo esc_attr( $kind ); ?>"
							data-device="<?php echo esc_attr( $device ); ?>"
							data-search="<?php echo esc_attr( $haystack ); ?>">
							<td class="wpicker-col-check">
								<input type="checkbox" class="wpicker-check" value="<?php echo esc_attr( $id ); ?>"
									aria-label="<?php echo esc_attr( sprintf( /* translators: %s: snapshot id. */ __( 'Select snapshot %s', 'wpicker' ), $id ) ); ?>">
							</td>
							<td>
								<code class="wpicker-id"><?php echo esc_html( $id ); ?></code>
								<time class="wpicker-time" datetime="<?php echo esc_attr( (string) ( $m['created_at_gmt'] ?? '' ) ); ?>">
									<?php echo esc_html( (string) ( $m['created_at_gmt'] ?? '' ) ); ?>
								</time>
							</td>
							<td><?php echo esc_html( '' !== $device ? $device : '—' ); ?></td>
							<td><?php echo esc_html( $kind_labels[ $kind ] ?? $kind ); ?></td>
							<td>
								<?php if ( array() !== $changes ) : ?>
									<?php if ( $tally['added'] ) : ?>
										<span class="wpicker-delta is-added" title="<?php esc_attr_e( 'Added', 'wpicker' ); ?>">+<?php echo esc_html( (string) $tally['added'] ); ?></span>
									<?php endif; ?>
									<?php if ( $tally['modified'] ) : ?>
										<span class="wpicker-delta is-modified" title="<?php esc_attr_e( 'Modified', 'wpicker' ); ?>">~<?php echo esc_html( (string) $tally['modified'] ); ?></span>
									<?php endif; ?>
									<?php if ( $tally['deleted'] ) : ?>
										<span class="wpicker-delta is-deleted" title="<?php esc_attr_e( 'Deleted', 'wpicker' ); ?>">−<?php echo esc_html( (string) $tally['deleted'] ); ?></span>
									<?php endif; ?>
								<?php else : ?>
									<span class="wpicker-muted">
										<?php
										/* translators: %d: number of files. */
										echo esc_html( sprintf( _n( '%d file', '%d files', count( $files ), 'wpicker' ), count( $files ) ) );
										?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<span class="wpicker-status wpicker-status-<?php echo esc_attr( $status ); ?>">
									<?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
								</span>
							</td>
							<td class="wpicker-col-actions">
								<?php if ( 'review' === $status ) : ?>
									<button type="button" class="button button-primary wpicker-act" data-act="apply"><?php esc_html_e( 'Review', 'wpicker' ); ?></button>
								<?php else : ?>
									<button type="button" class="button wpicker-act" data-act="view"><?php esc_html_e( 'View', 'wpicker' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

		<p class="wpicker-empty" id="wpicker-empty" <?php echo array() === $manifests ? '' : 'hidden'; ?>>
			<?php
			echo array() === $manifests
				? esc_html__( 'No snapshots yet. One is created every time a device pushes.', 'wpicker' )
				: esc_html__( 'No snapshots match your search or filters.', 'wpicker' );
			?>
		</p>

		<footer class="wpicker-pager" id="wpicker-pager">
			<label for="wpicker-page-size"><?php esc_html_e( 'Rows per page', 'wpicker' ); ?></label>
			<select id="wpicker-page-size">
				<option value="5">5</option>
				<option value="10">10</option>
				<option value="50">50</option>
				<option value="100">100</option>
				<option value="0"><?php esc_html_e( 'All', 'wpicker' ); ?></option>
			</select>
			<span class="wpicker-pager-range" id="wpicker-pager-range" aria-live="polite"></span>
			<button type="button" class="button" id="wpicker-page-prev"><?php esc_html_e( 'Previous', 'wpicker' ); ?></button>
			<button type="button" class="button" id="wpicker-page-next"><?php esc_html_e( 'Next', 'wpicker' ); ?></button>
			<button type="button" class="button-link wpicker-danger-link" id="wpicker-delete-all"
				data-total="<?php echo esc_attr( (string) count( $manifests ) ); ?>"
				data-review="<?php echo esc_attr( (string) $counts['review'] ); ?>">
				<?php esc_html_e( 'Delete all snapshots', 'wpicker' ); ?>
			</button>
		</footer>
	</div>

	<div class="wpicker-modal" id="wpicker-modal" hidden
		data-restorable="<?php echo esc_attr( implode( ',', $restorable ) ); ?>"
		data-status-labels="<?php echo esc_attr( (string) wp_json_encode( $status_labels ) ); ?>"
		data-kind-labels="<?php echo esc_attr( (string) wp_json_encode( $kind_labels ) ); ?>">
		<div class="wpicker-modal-backdrop" data-close="1"></div>
		<div class="wpicker-modal-dialog" id="wpicker-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="wpicker-modal-title" tabindex="-1"></div>
	</div>

	<div class="wpicker-toast" id="wpicker-toast" role="status" hidden></div>
</div>
