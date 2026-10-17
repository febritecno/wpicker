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

// A device this user paired in the last ten minutes: shown after the page reloads
// when one has just joined, so "who was that" is never lost.
$just_paired = null;
foreach ( $devices as $d ) {
	$made = isset( $d['created_at_gmt'] ) ? strtotime( (string) $d['created_at_gmt'] ) : false;
	if ( (int) ( $d['user_id'] ?? 0 ) === get_current_user_id() && false !== $made && time() - $made < 600 ) {
		if ( null === $just_paired || $made > strtotime( (string) $just_paired['created_at_gmt'] ) ) {
			$just_paired = $d;
		}
	}
}
?>
<div class="wrap wpicker-admin">
	<?php $this->render_header( \WPicker\Admin\Dashboard::SLUG_DEVICES ); ?>

	<?php if ( $just_paired ) : ?>
		<div class="wpicker-card wpicker-paired-notice" role="status" data-device-id="<?php echo esc_attr( (string) ( $just_paired['id'] ?? '' ) ); ?>">
			<strong><?php esc_html_e( 'A device just paired:', 'wpicker' ); ?></strong>
			<?php echo esc_html( (string) ( $just_paired['name'] ?? '' ) ); ?>
			<?php if ( ! empty( $just_paired['paired_from'] ) ) : ?>
				<?php esc_html_e( 'from', 'wpicker' ); ?> <code><?php echo esc_html( (string) $just_paired['paired_from'] ); ?></code>
			<?php endif; ?>
			<span class="wpicker-muted">(<?php echo esc_html( human_time_diff( strtotime( (string) $just_paired['created_at_gmt'] ) ) ); ?> <?php esc_html_e( 'ago', 'wpicker' ); ?>)</span>
			<?php esc_html_e( 'Not you?', 'wpicker' ); ?>
			<button type="button" class="button button-link-delete wpicker-notice-revoke"><?php esc_html_e( 'Revoke it', 'wpicker' ); ?></button>
		</div>
	<?php endif; ?>

	<div class="wpicker-card wpicker-code-card" id="wpicker-code-card">
		<h2 class="title"><?php esc_html_e( 'Pair a device', 'wpicker' ); ?></h2>
		<p><?php esc_html_e( 'On the computer you want to connect, run:', 'wpicker' ); ?></p>
		<p class="wpicker-code-command"><code>wpicker login <?php echo esc_html( untrailingslashit( home_url() ) ); ?></code></p>
		<p><?php esc_html_e( 'It prints a link and a code. Open the link in this browser, check the code matches, and press Approve. Nothing is created until you do, and your password never leaves this site.', 'wpicker' ); ?></p>
		<p class="wpicker-muted"><?php esc_html_e( 'If you did not just run that command, do not approve a request you are shown.', 'wpicker' ); ?></p>
	</div>

	<details class="wpicker-advanced" id="wpicker-pin-details" <?php echo ( $pin && strtotime( $pin['expires_at_gmt'] ) > time() ) ? 'open' : ''; ?>>
		<summary><?php esc_html_e( 'Pair with a password and a PIN instead (older CLI, or no pairing codes)', 'wpicker' ); ?></summary>
	<?php $pin_left = $pin ? max( 0, strtotime( $pin['expires_at_gmt'] ) - time() ) : 0; ?>
	<div class="wpicker-card wpicker-pin-card" id="wpicker-pin-card" data-expires-in="<?php echo esc_attr( (string) $pin_left ); ?>">
		<h2 class="title"><?php esc_html_e( 'Pair a new device', 'wpicker' ); ?></h2>
		<p>
			<?php esc_html_e( 'On the device, run', 'wpicker' ); ?> <code>wpicker login &lt;site&gt; --password</code>
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

	</details>

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
						<td><?php echo esc_html( $d['created_at_gmt'] ?? '' ); ?><?php echo ! empty( $d['method'] ) ? ' <span class="wpicker-muted">(' . esc_html( (string) $d['method'] ) . ')</span>' : ''; ?></td>
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
