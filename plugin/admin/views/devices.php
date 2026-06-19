<?php
/**
 * Devices admin view.
 *
 * Renders the WPicker Devices screen: a PIN generator card and a table of
 * registered devices with a Revoke action per row.
 *
 * @package WPicker\Admin
 * @var array{ pin?: string, expires_at_gmt?: string }|null $pin Current PIN for the user.
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @var \WPicker\Admin\Dashboard $this */
$pin     = $pin ?? null;
$devices = get_option( 'wpicker_devices', array() );
$devices = is_array( $devices ) ? $devices : array();
?>
<div class="wrap wpicker-admin">
	<h1>WPicker — Devices</h1>
	<p class="description">
		<?php esc_html_e( 'Manage local devices that connect to this site via the WPicker CLI.', 'wpicker' ); ?>
	</p>

	<div class="card wpicker-pin-card" id="wpicker-pin-card">
		<h2 class="title"><?php esc_html_e( 'Pair a new device', 'wpicker' ); ?></h2>
		<p>
			<?php esc_html_e( 'Generate a one-time PIN, then enter it in the CLI when running:', 'wpicker' ); ?>
			<code>wpicker login</code>
		</p>
		<p>
			<button type="button" class="button button-primary" id="wpicker-generate-pin">
				<?php esc_html_e( 'Generate PIN', 'wpicker' ); ?>
			</button>
		</p>
		<div id="wpicker-pin-display" class="wpicker-pin-display" <?php echo $pin ? '' : 'style="display:none;"'; ?>>
			<div class="wpicker-pin-code" id="wpicker-pin-code"><?php echo $pin ? esc_html( $pin['pin'] ) : ''; ?></div>
			<div class="wpicker-pin-expires">
				<?php esc_html_e( 'Refreshes in:', 'wpicker' ); ?> <strong id="wpicker-pin-countdown">10</strong>s
			</div>
			</div>
		</div>
	</div>

	<h2><?php esc_html_e( 'Registered devices', 'wpicker' ); ?></h2>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Device ID', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Created', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Last seen', 'wpicker' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'wpicker' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $devices ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No devices registered yet.', 'wpicker' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $devices as $d ) : ?>
					<tr data-device-id="<?php echo esc_attr( $d['id'] ?? '' ); ?>">
						<td><?php echo esc_html( $d['name'] ?? '' ); ?></td>
						<td><code><?php echo esc_html( $d['id'] ?? '' ); ?></code></td>
						<td><?php echo esc_html( $d['created_at_gmt'] ?? '' ); ?></td>
						<td><?php echo esc_html( $d['last_seen_gmt'] ?? __( 'never', 'wpicker' ) ); ?></td>
						<td>
							<button type="button" class="button button-link-delete wpicker-revoke">
								<?php esc_html_e( 'Revoke', 'wpicker' ); ?>
							</button>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
