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
	<?php $this->render_header( \WPicker\Admin\Dashboard::SLUG_DEVICES ); ?>

	<?php $pin_left = $pin ? max( 0, strtotime( $pin['expires_at_gmt'] ) - time() ) : 0; ?>
	<div class="wpicker-card wpicker-pin-card" id="wpicker-pin-card" data-expires-in="<?php echo esc_attr( (string) $pin_left ); ?>">
		<h2 class="title"><?php esc_html_e( 'Pair a new device', 'wpicker' ); ?></h2>
		<p>
			<?php esc_html_e( 'On the device, run', 'wpicker' ); ?> <code>wpicker login</code>
			<?php esc_html_e( 'and enter the PIN shown here when it asks.', 'wpicker' ); ?>
		</p>

		<div id="wpicker-pin-idle" <?php echo $pin_left > 0 ? 'hidden' : ''; ?>>
			<button type="button" class="button button-primary" id="wpicker-generate-pin">
				<?php esc_html_e( 'Generate PIN', 'wpicker' ); ?>
			</button>
			<p class="wpicker-pin-message" id="wpicker-pin-message" role="status"></p>
		</div>

		<div id="wpicker-pin-active" <?php echo $pin_left > 0 ? '' : 'hidden'; ?>>
			<div class="wpicker-pin-display">
				<div class="wpicker-pin-code" id="wpicker-pin-code"><?php echo $pin_left > 0 ? esc_html( $pin['pin'] ) : ''; ?></div>
				<div class="wpicker-pin-expires">
					<?php esc_html_e( 'Expires in', 'wpicker' ); ?> <strong id="wpicker-pin-countdown"><?php echo esc_html( (string) $pin_left ); ?></strong>s
				</div>
			</div>
			<p class="wpicker-pin-actions">
				<button type="button" class="button" id="wpicker-regenerate-pin"><?php esc_html_e( 'Regenerate', 'wpicker' ); ?></button>
				<button type="button" class="button wpicker-danger" id="wpicker-stop-pairing"><?php esc_html_e( 'Stop pairing', 'wpicker' ); ?></button>
			</p>
			<p class="wpicker-muted"><?php esc_html_e( 'Pairing is open until the PIN is used, expires, or you stop it. Regenerating makes the old PIN useless.', 'wpicker' ); ?></p>
		</div>
	</div>

	<h2 class="wpicker-section-title"><?php esc_html_e( 'Registered devices', 'wpicker' ); ?></h2>
	<div class="wpicker-panel">
	<table class="wpicker-table">
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
</div>
