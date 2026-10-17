<?php
/**
 * DeviceFlow — pairing a device the way `gh auth login` does (RFC 8628).
 *
 *   1. The CLI asks to pair (start). It gets a SECRET device_code that only it
 *      holds, and a short user_code that is only for people to compare.
 *   2. A person opens a link in a browser, logs in to WordPress normally (any
 *      password and two-factor plugin apply: it is wp-admin), sees which site and
 *      which device are asking, and approves or denies.
 *   3. The CLI polls with its device_code. Only when a decision exists does it get
 *      anything, and only once.
 *
 * Two layers, then: possession of the device_code, and a real login that approved
 * THIS request. The user_code alone grants nothing (the poll never accepts it), so
 * a bystander who reads it off a screen cannot do anything with it.
 *
 * Atomicity comes from DeviceRequestStore: approve/deny is insert-once, and the
 * credentials are released to whoever's delete of the request row succeeded.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Class DeviceFlow
 */
final class DeviceFlow {

	/**
	 * No I, O, 0 or 1: they are read wrong aloud and typed wrong by hand.
	 */
	public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	public const USER_CODE_LENGTH = 8;
	public const TTL              = 600;
	public const INTERVAL         = 3;

	public const MAX_PENDING_PER_IP   = 3;
	public const MAX_PENDING_PER_WIDE = 15;
	public const MAX_PENDING_SITE   = 150;

	public const REQ = 'wpicker_dc_';
	public const DEC = 'wpicker_dcd_';
	public const USR = 'wpicker_dcu_';

	/**
	 * @var DeviceRequestStore
	 */
	private $store;

	/**
	 * @param DeviceRequestStore $store Storage.
	 */
	public function __construct( DeviceRequestStore $store ) {
		$this->store = $store;
	}

	// --- pure helpers ----------------------------------------------------------------------

	/**
	 * What a person typed, reduced to what can be a user code.
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	public static function normalize_user_code( string $input ): string {
		return strtoupper( (string) preg_replace( '/[^A-Za-z0-9]/', '', $input ) );
	}

	/**
	 * @param string $normalized Result of normalize_user_code().
	 * @return bool
	 */
	public static function well_formed( string $normalized ): bool {
		return 1 === preg_match( '/^[' . self::ALPHABET . ']{' . self::USER_CODE_LENGTH . '}$/', $normalized );
	}

	/**
	 * `ABCDEFGH` -> `ABCD-EFGH`.
	 *
	 * @param string $normalized Normalised user code.
	 * @return string
	 */
	public static function format( string $normalized ): string {
		return substr( $normalized, 0, 4 ) . '-' . substr( $normalized, 4 );
	}

	/**
	 * A keyed hash, so a secret is never stored and is found by hash.
	 *
	 * @param string $secret Secret or normalised user code.
	 * @return string
	 */
	public static function hash( string $secret ): string {
		return substr( hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) ), 0, 40 );
	}

	/**
	 * A name for a device that came from a stranger: no control characters, short.
	 *
	 * @param string $name Raw name.
	 * @return string
	 */
	public static function clean_label( string $name, int $max = 80 ): string {
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $name );
		$name = trim( $name );
		if ( function_exists( 'mb_substr' ) ) {
			$name = mb_substr( $name, 0, $max );
		} else {
			$name = substr( $name, 0, $max );
		}
		return $name;
	}

	// --- start -----------------------------------------------------------------------------

	/**
	 * Begin a pairing. Returns null when there is no room (too many pending).
	 *
	 * @param string $device_name Name the requester gives itself (unverified).
	 * @param string $ip          Requesting address.
	 * @param string $agent       Requesting User-Agent (unverified).
	 * @return array{device_code:string,user_code:string,expires_in:int,interval:int}|null
	 */
	public function start( string $device_name, string $ip, string $agent ): ?array {
		$this->prune();

		$now    = time();
		$bucket = PairRateLimit::bucket( $ip );
		$wide   = PairRateLimit::wide_bucket( $ip );
		$site   = 0;
		$mine   = 0;
		$family = 0;
		foreach ( $this->store->all( self::REQ ) as $req ) {
			if ( (int) ( $req['expires'] ?? 0 ) > $now ) {
				++$site;
				if ( ( $req['bucket'] ?? '' ) === $bucket ) {
					++$mine;
				}
				if ( ( $req['wide'] ?? '' ) === $wide ) {
					++$family;
				}
			}
		}
		// Refuse rather than drop the oldest: dropping would let an attacker evict a real request.
		if ( $mine >= self::MAX_PENDING_PER_IP || $family >= self::MAX_PENDING_PER_WIDE || $site >= self::MAX_PENDING_SITE ) {
			return null;
		}

		$label = self::clean_label( $device_name );
		$label = '' === $label ? 'WPicker CLI' : $label;

		for ( $try = 0; $try < 6; $try++ ) {
			$device_code = bin2hex( random_bytes( 24 ) ); // 192 bits; never stored.
			$hash        = self::hash( $device_code );
			$user_code   = $this->new_user_code();
			$ucode_hash  = self::hash( $user_code );

			// The user code must be unique among pending requests: claim its index row first.
			if ( ! $this->store->insert( self::USR . $ucode_hash, array( 'h' => $hash ) ) ) {
				continue;
			}
			$stored = $this->store->insert(
				self::REQ . $hash,
				array(
					'user_code' => $user_code,
					'ucode'     => $ucode_hash,
					'name'      => $label,
					'ip'        => $ip,
					'bucket'    => $bucket,
					'wide'      => $wide,
					'agent'     => self::clean_label( $agent, 200 ),
					'created'   => $now,
					'expires'   => $now + self::TTL,
				)
			);
			if ( ! $stored ) {
				$this->store->delete( self::USR . $ucode_hash );
				continue;
			}
			return array(
				'device_code' => $device_code,
				'user_code'   => self::format( $user_code ),
				'expires_in'  => self::TTL,
				'interval'    => self::INTERVAL,
			);
		}
		return null;
	}

	/**
	 * @return string Eight characters from the safe alphabet.
	 */
	private function new_user_code(): string {
		$max  = strlen( self::ALPHABET ) - 1;
		$code = '';
		for ( $i = 0; $i < self::USER_CODE_LENGTH; $i++ ) {
			$code .= self::ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	// --- the person's side -----------------------------------------------------------------

	/**
	 * Find the pending request a person is looking at, by the code on their
	 * terminal. Used only on the authenticated approval page.
	 *
	 * @param string $input What was typed or came in the link.
	 * @return array{hash:string,request:array<string,mixed>,decided:bool}|null
	 */
	public function lookup( string $input ): ?array {
		$code = self::normalize_user_code( $input );
		if ( ! self::well_formed( $code ) ) {
			return null;
		}
		$index = $this->store->get( self::USR . self::hash( $code ) );
		if ( ! is_array( $index ) || empty( $index['h'] ) ) {
			return null;
		}
		$hash = (string) $index['h'];
		$req  = $this->store->get( self::REQ . $hash );
		if ( ! is_array( $req ) || (int) ( $req['expires'] ?? 0 ) <= time() ) {
			return null;
		}
		return array(
			'hash'    => $hash,
			'request' => $req,
			'decided' => null !== $this->store->get( self::DEC . $hash ),
		);
	}

	/**
	 * A pending request by its hash (the approval form submits this).
	 *
	 * @param string $hash Request hash.
	 * @return array<string,mixed>|null
	 */
	public function request( string $hash ): ?array {
		if ( 1 !== preg_match( '/^[0-9a-f]{40}$/', $hash ) ) {
			return null;
		}
		$req = $this->store->get( self::REQ . $hash );
		if ( ! is_array( $req ) || (int) ( $req['expires'] ?? 0 ) <= time() ) {
			return null;
		}
		return $req;
	}

	/**
	 * Record the decision. The FIRST decision wins: approve, deny and a second
	 * approve cannot disagree.
	 *
	 * @param string $hash        Request hash.
	 * @param string $status      approved | denied.
	 * @param int    $user_id     Who decided (a logged-in administrator).
	 * @param string $approver_ip Their address.
	 * @return bool True if this call recorded the decision.
	 */
	public function decide( string $hash, string $status, int $user_id, string $approver_ip ): bool {
		if ( ! in_array( $status, array( 'approved', 'denied' ), true ) || $user_id <= 0 || null === $this->request( $hash ) ) {
			return false;
		}
		return $this->store->insert(
			self::DEC . $hash,
			array(
				'status'  => $status,
				'user_id' => $user_id,
				'ip'      => $approver_ip,
				'at'      => time(),
			)
		);
	}

	// --- the CLI's side --------------------------------------------------------------------

	/**
	 * What the CLI is told when it polls with its device_code.
	 *
	 * status: pending | slow_down | denied | expired | invalid | approved. On
	 * `approved` it also carries user_id and the request; that answer is given to
	 * exactly one caller, once.
	 *
	 * @param string $device_code The secret the CLI holds.
	 * @return array<string,mixed>
	 */
	public function poll( string $device_code ): array {
		if ( 1 !== preg_match( '/^[0-9a-f]{48}$/', $device_code ) ) {
			return array( 'status' => 'invalid' );
		}
		$hash = self::hash( $device_code );
		$req  = $this->store->get( self::REQ . $hash );
		if ( ! is_array( $req ) ) {
			return array( 'status' => 'invalid' );
		}
		if ( (int) ( $req['expires'] ?? 0 ) <= time() ) {
			$this->forget( $hash, $req );
			return array( 'status' => 'expired' );
		}

		$decision = $this->store->get( self::DEC . $hash );
		if ( ! is_array( $decision ) ) {
			// Too eager a client is told to slow down; one reply per interval is plenty.
			$key  = 'wpicker_dcp_' . $hash;
			$last = (int) get_transient( $key );
			set_transient( $key, time(), self::TTL );
			return array( 'status' => ( $last > 0 && time() - $last < self::INTERVAL - 1 ) ? 'slow_down' : 'pending' );
		}

		// A decision exists. Whoever's delete of the request row succeeds takes it; the
		// other of two parallel polls finds nothing.
		if ( true !== $this->store->delete( self::REQ . $hash ) ) {
			return array( 'status' => 'invalid' );
		}
		$this->store->delete( self::DEC . $hash );
		$this->store->delete( self::USR . (string) ( $req['ucode'] ?? '' ) );

		if ( 'approved' !== ( $decision['status'] ?? '' ) ) {
			return array( 'status' => 'denied' );
		}
		return array(
			'status'  => 'approved',
			'user_id' => (int) ( $decision['user_id'] ?? 0 ),
			'request' => $req,
		);
	}

	/**
	 * Drop a request and everything that points at it.
	 *
	 * @param string              $hash Request hash.
	 * @param array<string,mixed> $req  Request.
	 * @return void
	 */
	private function forget( string $hash, array $req ): void {
		$this->store->delete( self::REQ . $hash );
		$this->store->delete( self::DEC . $hash );
		$this->store->delete( self::USR . (string) ( $req['ucode'] ?? '' ) );
	}

	/**
	 * Remove what has expired, and index rows whose request is gone.
	 *
	 * @return int How many requests were removed.
	 */
	public function prune(): int {
		$now = time();
		$n   = 0;
		foreach ( $this->store->all( self::REQ ) as $name => $req ) {
			if ( (int) ( $req['expires'] ?? 0 ) <= $now ) {
				$this->forget( substr( $name, strlen( self::REQ ) ), $req );
				++$n;
			}
		}
		foreach ( $this->store->all( self::DEC ) as $name => $dec ) {
			$hash = substr( $name, strlen( self::DEC ) );
			if ( null === $this->store->get( self::REQ . $hash ) || ( (int) ( $dec['at'] ?? 0 ) + self::TTL + 60 ) < $now ) {
				$this->store->delete( $name );
			}
		}
		foreach ( $this->store->all( self::USR ) as $name => $idx ) {
			if ( null === $this->store->get( self::REQ . (string) ( $idx['h'] ?? '' ) ) ) {
				$this->store->delete( $name );
			}
		}
		return $n;
	}
}
