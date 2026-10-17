<?php
/**
 * Approve a device: the screen the CLI's link opens.
 *
 * @var string              $typed        Code from the link / the form.
 * @var string              $msg          Result of the last action.
 * @var array|null          $found        lookup() result.
 * @var bool                $same_network Is the request from this browser's network?
 * @var bool                $proxied      Does the site sit behind a proxy WPicker cannot see past?
 *
 * @package WPicker
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notices = array(
	'approved' => array( 'ok', __( 'Approved. Go back to your terminal: it will finish by itself.', 'wpicker' ) ),
	'denied'   => array( 'ok', __( 'Denied. That device was not given access.', 'wpicker' ) ),
	'expired'  => array( 'bad', __( 'That request has expired or does not exist. Run the command on your computer again.', 'wpicker' ) ),
	'already'  => array( 'bad', __( 'That request was already decided.', 'wpicker' ) ),
	'mismatch' => array( 'bad', __( 'The code you typed does not match the one in the terminal. Nothing was approved.', 'wpicker' ) ),
);
$site_name = (string) get_bloginfo( 'name' );
$site_url  = untrailingslashit( home_url() );
$user      = wp_get_current_user();
?>
<div class="wrap wpicker-admin wpicker-approve">
	<h1><?php esc_html_e( 'Pair a device', 'wpicker' ); ?></h1>

	<?php if ( isset( $notices[ $msg ] ) ) : ?>
		<div class="wpicker-card wpicker-approve-notice is-<?php echo esc_attr( $notices[ $msg ][0] ); ?>" role="status">
			<?php echo esc_html( $notices[ $msg ][1] ); ?>
		</div>
	<?php endif; ?>

	<?php if ( null === $found ) : ?>
		<div class="wpicker-card">
			<?php if ( '' !== $typed && '' === $msg ) : ?>
				<p class="wpicker-approve-notice is-bad"><?php esc_html_e( 'No request is waiting with that code. It may have expired.', 'wpicker' ); ?></p>
			<?php endif; ?>
			<p><?php esc_html_e( 'On your computer run', 'wpicker' ); ?> <code>wpicker login <?php echo esc_html( $site_url ); ?></code>
				<?php esc_html_e( 'It prints a code like ABCD-EFGH. Type it here to find the request:', 'wpicker' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( \WPicker\Admin\Approve::SLUG ); ?>">
				<input type="text" name="user_code" value="<?php echo esc_attr( $typed ); ?>" placeholder="ABCD-EFGH" autocomplete="off" maxlength="12" class="regular-text" style="font-family:monospace;text-transform:uppercase">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Find request', 'wpicker' ); ?></button>
			</form>
		</div>
	<?php else : ?>
		<?php
		$req     = $found['request'];
		$decided = (bool) $found['decided'];
		$age     = human_time_diff( (int) ( $req['created'] ?? time() ), time() );
		?>
		<div class="wpicker-card wpicker-approve-card">
			<p class="wpicker-approve-lead"><?php esc_html_e( 'A device is asking for access to', 'wpicker' ); ?></p>
			<h2 class="wpicker-approve-site"><?php echo esc_html( $site_name ); ?></h2>
			<p class="wpicker-approve-url"><code><?php echo esc_html( $site_url ); ?></code></p>

			<table class="wpicker-approve-facts">
				<tr><th><?php esc_html_e( 'Code to compare', 'wpicker' ); ?></th>
					<td><?php if ( $same_network ) : ?>
						<strong class="wpicker-approve-code"><?php echo esc_html( \WPicker\Auth\DeviceFlow::format( (string) $req['user_code'] ) ); ?></strong>
						<span class="wpicker-muted"><?php esc_html_e( '— it must be the same one your terminal shows', 'wpicker' ); ?></span>
					<?php else : ?>
						<span class="wpicker-muted"><?php esc_html_e( 'Read it in your terminal. It is not shown here on purpose: you have to type it.', 'wpicker' ); ?></span>
					<?php endif; ?></td></tr>
				<tr><th><?php esc_html_e( 'Device name', 'wpicker' ); ?></th>
					<td><?php echo esc_html( (string) ( $req['name'] ?? '' ) ); ?> <span class="wpicker-muted"><?php esc_html_e( '(chosen by the device, not verified)', 'wpicker' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'Asked from', 'wpicker' ); ?></th>
					<td><code><?php echo esc_html( (string) ( $req['ip'] ?? '' ) ); ?></code>
						<?php if ( $same_network ) : ?>
							<span class="wpicker-muted"><?php esc_html_e( '— the same network as this browser', 'wpicker' ); ?></span>
						<?php else : ?>
							<strong class="wpicker-approve-warn"><?php echo $proxied ? esc_html__( '— cannot be compared: this site sits behind a proxy', 'wpicker' ) : esc_html__( '— a DIFFERENT network than this browser', 'wpicker' ); ?></strong>
						<?php endif; ?></td></tr>
				<tr><th><?php esc_html_e( 'Program', 'wpicker' ); ?></th>
					<td><?php echo esc_html( (string) ( $req['agent'] ?? '' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Asked', 'wpicker' ); ?></th>
					<td><?php echo esc_html( $age ); ?> <?php esc_html_e( 'ago', 'wpicker' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'You would give it', 'wpicker' ); ?></th>
					<td><?php
						/* translators: %s: user login. */
						echo esc_html( sprintf( __( 'the same access as %s: it can read this site and change its theme files.', 'wpicker' ), $user->user_login ) );
					?></td></tr>
			</table>

			<p class="wpicker-approve-rule"><strong><?php esc_html_e( 'Only approve if you just ran this command yourself, on your own computer.', 'wpicker' ); ?></strong>
				<?php esc_html_e( 'Never approve a link someone sent you.', 'wpicker' ); ?></p>

			<?php if ( $decided ) : ?>
				<p><?php esc_html_e( 'This request has been decided. If you approved it, the terminal finishes by itself.', 'wpicker' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( \WPicker\Admin\Approve::ACTION ); ?>">
					<input type="hidden" name="hash" value="<?php echo esc_attr( (string) $found['hash'] ); ?>">
					<?php wp_nonce_field( 'wpicker_dc_' . (string) $found['hash'] ); ?>

					<?php if ( ! $same_network ) : ?>
						<div class="wpicker-approve-alert">
							<p><strong><?php esc_html_e( 'This request did not come from the network you are using now.', 'wpicker' ); ?></strong>
								<?php esc_html_e( 'That is normal if you ran the command on a server over SSH. If somebody else asked you to open this link, deny it.', 'wpicker' ); ?></p>
							<p><label for="wpicker-typed-code"><?php esc_html_e( 'To approve, type the code shown in your terminal:', 'wpicker' ); ?></label><br>
								<input id="wpicker-typed-code" type="text" name="typed_code" autocomplete="off" maxlength="12" placeholder="ABCD-EFGH" style="font-family:monospace;text-transform:uppercase" required></p>
						</div>
					<?php endif; ?>

					<p>
						<button type="submit" name="decision" value="approve" class="button button-primary button-large"><?php esc_html_e( 'Approve', 'wpicker' ); ?></button>
						<button type="submit" name="decision" value="deny" class="button button-large" formnovalidate><?php esc_html_e( 'Deny', 'wpicker' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
