<?php
/**
 * Backup & Restore admin view.
 *
 * Lists full-site backups, creates new ones, receives a backup file made
 * elsewhere, and restores a backup onto THIS site. Each of those runs in the
 * browser as a loop of short REST calls (see admin.js) so it also works on
 * hosts that cut long requests.
 *
 * Restoring onto a local WordPress instead is done from a paired device with
 * `wpicker backup restore`.
 *
 * @package WPicker\Admin
 * @var array<int,array<string,mixed>> $backups Backups, newest first (Job::progress()).
 * @var bool                           $enabled Whether backups are allowed in Settings.
 * @var int                            $keep    Backups kept before the oldest is deleted.
 * @var array<string,mixed>|null       $restore The last restore's progress, if one is recorded.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$parts = array(
	'db'      => __( 'Database', 'wpicker' ),
	'plugins' => __( 'Plugins', 'wpicker' ),
	'themes'  => __( 'Themes', 'wpicker' ),
	'uploads' => __( 'Uploads', 'wpicker' ),
);
?>
<div class="wrap wpicker-admin">
	<?php $this->render_header( \WPicker\Admin\Dashboard::SLUG_BACKUPS ); ?>

	<?php if ( ! $enabled ) : ?>
		<div class="wpicker-card">
			<h2><?php esc_html_e( 'Backups are switched off', 'wpicker' ); ?></h2>
			<p class="wpicker-muted">
				<?php esc_html_e( 'Turn on "Allow full-site backups" to create, download or restore them.', 'wpicker' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . \WPicker\Admin\Dashboard::SLUG_SETTINGS ) ); ?>"><?php esc_html_e( 'Open Settings', 'wpicker' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<?php if ( null !== $restore && ( $restore['done'] || $restore['failed'] ) ) : ?>
			<div class="wpicker-card wpicker-restore-result <?php echo $restore['failed'] ? 'is-failed' : 'is-done'; ?>" id="wpicker-restore-result">
				<?php if ( $restore['failed'] ) : ?>
					<h2><?php esc_html_e( 'The last restore failed', 'wpicker' ); ?></h2>
					<p><?php echo esc_html( (string) $restore['error'] ); ?></p>
					<p class="wpicker-muted"><?php esc_html_e( 'The database was not changed. Files that were already unpacked stay in place.', 'wpicker' ); ?></p>
				<?php else : ?>
					<h2><?php esc_html_e( 'Restore complete', 'wpicker' ); ?></h2>
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: backup id, 2: number of SQL statements, 3: number of files. */
								__( 'Backup %1$s was restored: %2$d database statements, %3$d files.', 'wpicker' ),
								(string) $restore['backup_id'],
								(int) $restore['statements'],
								(int) $restore['files_done']
							)
						);
						?>
					</p>
				<?php endif; ?>
				<button type="button" class="button" id="wpicker-restore-dismiss"><?php esc_html_e( 'Dismiss', 'wpicker' ); ?></button>
			</div>
		<?php endif; ?>

		<div class="wpicker-card-row">
		<section class="wpicker-card wpicker-backup-new" id="wpicker-backup-new">
			<h2><?php esc_html_e( 'Create a backup', 'wpicker' ); ?></h2>
			<p class="wpicker-muted">
				<?php esc_html_e( 'One file with everything needed to rebuild this site somewhere else. Keep this tab open until it finishes.', 'wpicker' ); ?>
			</p>

			<p class="wpicker-field">
				<label for="wpicker-backup-label"><?php esc_html_e( 'Note (optional)', 'wpicker' ); ?></label>
				<input type="text" id="wpicker-backup-label" class="regular-text" maxlength="80"
					placeholder="<?php esc_attr_e( 'e.g. before redesign', 'wpicker' ); ?>">
			</p>

			<fieldset class="wpicker-parts">
				<legend class="screen-reader-text"><?php esc_html_e( 'What to include', 'wpicker' ); ?></legend>
				<?php foreach ( $parts as $key => $label ) : ?>
					<label><input type="checkbox" class="wpicker-backup-part" value="<?php echo esc_attr( $key ); ?>" checked> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
			</fieldset>

			<p>
				<button type="button" class="button button-primary" id="wpicker-backup-create"><?php esc_html_e( 'Create backup', 'wpicker' ); ?></button>
			</p>

			<div class="wpicker-progress" id="wpicker-backup-progress" hidden>
				<div class="wpicker-progress-track"><div class="wpicker-progress-bar" id="wpicker-backup-bar"></div></div>
				<p class="wpicker-muted" id="wpicker-backup-status" role="status"></p>
			</div>
		</section>

		<section class="wpicker-card" id="wpicker-backup-upload">
			<h2><?php esc_html_e( 'Restore from a file', 'wpicker' ); ?></h2>
			<p class="wpicker-muted">
				<?php esc_html_e( 'Have a WPicker backup file (.tar) from another site or from your computer? Add it to the list below, then restore it.', 'wpicker' ); ?>
			</p>
			<p>
				<input type="file" id="wpicker-upload-file" accept=".tar,application/x-tar">
			</p>
			<p>
				<button type="button" class="button" id="wpicker-upload-start" disabled><?php esc_html_e( 'Upload backup file', 'wpicker' ); ?></button>
			</p>
			<div class="wpicker-progress" id="wpicker-upload-progress" hidden>
				<div class="wpicker-progress-track"><div class="wpicker-progress-bar" id="wpicker-upload-bar"></div></div>
				<p class="wpicker-muted" id="wpicker-upload-status" role="status"></p>
			</div>
		</section>
		</div>
	<?php endif; ?>

	<h2 class="wpicker-section-title"><?php esc_html_e( 'Backups on this site', 'wpicker' ); ?></h2>
	<div class="wpicker-panel">
		<table class="wpicker-table" id="wpicker-backup-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Backup', 'wpicker' ); ?></th>
					<th><?php esc_html_e( 'Note', 'wpicker' ); ?></th>
					<th><?php esc_html_e( 'Contains', 'wpicker' ); ?></th>
					<th><?php esc_html_e( 'Size', 'wpicker' ); ?></th>
					<th><?php esc_html_e( 'By', 'wpicker' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wpicker' ); ?></th>
					<th class="wpicker-col-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'wpicker' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $backups as $b ) : ?>
					<?php
					$id       = (string) $b['id'];
					$contains = array();
					foreach ( $parts as $key => $label ) {
						if ( ! empty( $b['include'][ $key ] ) ) {
							$contains[] = $label;
						}
					}
					?>
					<tr data-backup-id="<?php echo esc_attr( $id ); ?>"
						data-include="<?php echo esc_attr( (string) wp_json_encode( $b['include'] ) ); ?>"
						data-source="<?php echo esc_attr( (string) $b['source_url'] ); ?>"
						data-created="<?php echo esc_attr( (string) $b['created_gmt'] ); ?>"
						data-label="<?php echo esc_attr( (string) $b['label'] ); ?>">
						<td>
							<code class="wpicker-id"><?php echo esc_html( $id ); ?></code>
							<time class="wpicker-time" datetime="<?php echo esc_attr( (string) $b['created_gmt'] ); ?>"><?php echo esc_html( (string) $b['created_gmt'] ); ?></time>
						</td>
						<td class="wpicker-wrap">
							<?php echo esc_html( '' !== $b['label'] ? (string) $b['label'] : '—' ); ?>
							<?php if ( 'upload' === $b['origin'] && '' !== $b['source_url'] ) : ?>
								<span class="wpicker-time"><?php echo esc_html( sprintf( /* translators: %s: site address. */ __( 'from %s', 'wpicker' ), (string) $b['source_url'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( implode( ', ', $contains ) ); ?>
							<?php // An uploaded archive was not built here, so nothing was counted. ?>
							<span class="wpicker-time" <?php echo 'upload' === $b['origin'] ? 'hidden' : ''; ?>>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of database tables, 2: number of files. */
										__( '%1$d tables · %2$d files', 'wpicker' ),
										(int) $b['tables_done'],
										(int) $b['files_done']
									)
								);
								?>
							</span>
						</td>
						<td><?php echo esc_html( size_format( (int) $b['bytes'], 1 ) ?: '0 B' ); ?></td>
						<td><?php echo esc_html( '' !== $b['created_by'] ? (string) $b['created_by'] : '—' ); ?></td>
						<td>
							<?php if ( $b['done'] ) : ?>
								<span class="wpicker-status wpicker-status-applied"><?php esc_html_e( 'Ready', 'wpicker' ); ?></span>
							<?php else : ?>
								<span class="wpicker-status wpicker-status-review"><?php esc_html_e( 'Incomplete', 'wpicker' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="wpicker-col-actions">
							<?php if ( $b['done'] && $enabled ) : ?>
								<button type="button" class="button button-primary wpicker-backup-restore"><?php esc_html_e( 'Restore', 'wpicker' ); ?></button>
							<?php endif; ?>
							<?php if ( $b['done'] ) : ?>
								<a class="button" href="<?php echo esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'wpicker/v1/backup/' . $id . '/download' ) ) ); ?>"><?php esc_html_e( 'Download', 'wpicker' ); ?></a>
							<?php endif; ?>
							<button type="button" class="button wpicker-danger wpicker-backup-delete"><?php esc_html_e( 'Delete', 'wpicker' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( array() === $backups ) : ?>
			<p class="wpicker-empty"><?php esc_html_e( 'No backups yet.', 'wpicker' ); ?></p>
		<?php endif; ?>
	</div>

	<p class="wpicker-muted wpicker-hint">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d: number of backups kept. */
				_n( 'The newest %d backup is kept; older ones are deleted when a new one finishes.', 'The newest %d backups are kept; older ones are deleted when a new one finishes.', $keep, 'wpicker' ),
				$keep
			)
		);
		?>
		<?php esc_html_e( '"Restore" rebuilds this site from a backup. To rebuild it on your computer instead, run on a paired device:', 'wpicker' ); ?>
		<code>wpicker backup restore</code>
	</p>

	<div class="wpicker-modal" id="wpicker-restore-modal" hidden
		data-site="<?php echo esc_attr( untrailingslashit( site_url() ) ); ?>"
		data-login="<?php echo esc_url( wp_login_url() ); ?>">
		<div class="wpicker-modal-backdrop"></div>
		<div class="wpicker-modal-dialog is-narrow" id="wpicker-restore-dialog" role="dialog" aria-modal="true" aria-labelledby="wpicker-restore-title" tabindex="-1"></div>
	</div>

	<div class="wpicker-toast" id="wpicker-toast" role="status" hidden></div>
</div>
