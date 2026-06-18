<?php
/**
 * Vault / History admin view.
 *
 * Renders the WPicker Vault screen: a table of manifests (pushes + rollbacks),
 * with status, file count, the acting device, and a Restore action. Aborted
 * manifests expand to show the structured error log for self-healing forensics.
 *
 * @package WPicker\Admin
 * @var array<int,array<string,mixed>> $manifests Manifests (newest first).
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$status_labels = array(
	'pending'  => __( 'Pending', 'wpicker' ),
	'applied'  => __( 'Applied', 'wpicker' ),
	'aborted'  => __( 'Aborted', 'wpicker' ),
	'restored' => __( 'Restored', 'wpicker' ),
);
?>
<div class="wrap wpicker-admin">
	<h1>WPicker — Vault / History</h1>
	<p class="description">
		<?php esc_html_e( 'Every push creates an atomic snapshot. Restore any prior state instantly, and inspect aborted pushes for error details.', 'wpicker' ); ?>
	</p>

	<h2><?php esc_html_e( 'Deployment history', 'wpicker' ); ?></h2>
	<table class="widefat striped" id="wpicker-vault-table">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Manifest', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Time (GMT)', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Device', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Kind', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Files', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Status', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wpicker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $manifests ) ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'No deployments recorded yet.', 'wpicker' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $manifests as $m ) : ?>
					<?php
					$id     = $m['id'] ?? '';
					$status = $m['status'] ?? '';
					$has_err = ! empty( $m['error'] );
					?>
					<tr data-manifest-id="<?php echo esc_attr( $id ); ?>">
						<td><code><?php echo esc_html( $id ); ?></code></td>
						<td><?php echo esc_html( $m['created_at_gmt'] ?? '' ); ?></td>
						<td><?php echo esc_html( $m['device_name'] ?? '' ); ?></td>
						<td><?php echo esc_html( $m['kind'] ?? 'push' ); ?></td>
						<td><?php echo esc_html( (string) ( $m['count'] ?? 0 ) ); ?></td>
						<td>
							<span class="wpicker-status wpicker-status-<?php echo esc_attr( $status ); ?>">
								<?php echo esc_html( $status_labels[ $status ] ?? $status ); ?>
							</span>
						</td>
						<td>
							<button type="button" class="button wpicker-restore" data-id="<?php echo esc_attr( $id ); ?>">
								<?php esc_html_e( 'Restore', 'wpicker' ); ?>
							</button>
							<?php if ( $has_err ) : ?>
								<button type="button" class="button wpicker-show-error" data-id="<?php echo esc_attr( $id ); ?>">
									<?php esc_html_e( 'Error log', 'wpicker' ); ?>
								</button>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( $has_err ) : ?>
						<tr class="wpicker-error-row" data-for="<?php echo esc_attr( $id ); ?>" style="display:none;">
							<td colspan="7">
								<pre class="wpicker-error-pre"><?php echo esc_html( wp_json_encode( $m['error'], JSON_PRETTY_PRINT ) ); ?></pre>
							</td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
