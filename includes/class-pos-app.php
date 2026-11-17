<?php
/**
 * The POS app at /pos: page, sign-in, web app manifest and service worker.
 *
 * Everything lives under /pos/ so the installed app never leaves its own
 * scope (on iPhone, leaving it can open a separate browser with no login).
 *
 * @package PRDRWNDN_POS
 */

defined( 'ABSPATH' ) || exit;

class PRDRWNDN_POS_App {

	const QV = 'prdrwndn_pos';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 0 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache_rest' ), 10, 3 );
	}

	public static function rewrites() {
		add_rewrite_rule( '^pos/?$', 'index.php?' . self::QV . '=app', 'top' );
		add_rewrite_rule( '^pos/manifest\.webmanifest$', 'index.php?' . self::QV . '=manifest', 'top' );
		add_rewrite_rule( '^pos/sw\.js$', 'index.php?' . self::QV . '=sw', 'top' );
		if ( get_option( 'prdrwndn_pos_flush' ) ) {
			delete_option( 'prdrwndn_pos_flush' );
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QV;
		return $vars;
	}

	public static function url( $path = '' ) {
		return home_url( '/pos/' . ltrim( $path, '/' ) );
	}

	private static function path( $path = '' ) {
		return wp_make_link_relative( self::url( $path ) );
	}

	/** LiteSpeed and other caches must never keep a copy of POS data. */
	public static function no_cache_rest( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . PRDRWNDN_POS_REST::NS ) && $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'no-store, private' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		return $response;
	}

	private static function no_cache() {
		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public static function route() {
		$what = get_query_var( self::QV );
		if ( ! $what ) {
			return;
		}
		if ( 'manifest' === $what ) {
			self::manifest();
		} elseif ( 'sw' === $what ) {
			self::service_worker();
		} else {
			self::app();
		}
		exit;
	}

	/* ------------------------------------------------------------------ */

	private static function manifest() {
		self::no_cache();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		$icons = PRDRWNDN_POS_URL . 'assets/pos/';
		$iv    = '?v=' . PRDRWNDN_POS_VERSION;
		echo wp_json_encode(
			array(
				'name'             => 'PRDRWNDN POS',
				'short_name'       => 'PRD POS',
				'description'      => 'Sell, scan and count stock for the PRDRWNDN store.',
				'id'               => self::path(),
				'start_url'        => self::path(),
				'scope'            => self::path(),
				'display'          => 'standalone',
				'orientation'      => 'any',
				'background_color' => '#1E3B2F',
				'theme_color'      => '#1E3B2F',
				'icons'            => array(
					array(
						'src'   => $icons . 'icon-192.png' . $iv,
						'sizes' => '192x192',
						'type'  => 'image/png',
					),
					array(
						'src'   => $icons . 'icon-512.png' . $iv,
						'sizes' => '512x512',
						'type'  => 'image/png',
					),
					array(
						'src'     => $icons . 'icon-maskable-512.png' . $iv,
						'sizes'   => '512x512',
						'type'    => 'image/png',
						'purpose' => 'maskable',
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		);
	}

	/** Version that changes whenever any app file changes, so installed apps update themselves. */
	public static function build() {
		$sum = PRDRWNDN_POS_VERSION;
		foreach ( array( 'app.js', 'app.css' ) as $f ) {
			$sum .= '|' . (int) @filemtime( PRDRWNDN_POS_DIR . 'assets/pos/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return substr( md5( $sum ), 0, 10 );
	}

	private static function assets() {
		$v    = self::build();
		$iv   = '?v=' . PRDRWNDN_POS_VERSION;
		$base = PRDRWNDN_POS_URL . 'assets/';
		return array(
			'css'        => $base . 'pos/app.css?v=' . $v,
			'js'         => $base . 'pos/app.js?v=' . $v,
			'zxing'      => $base . 'vendor/zxing.min.js?v=0.23.0',
			'icons'      => array( $base . 'pos/icon-192.png' . $iv, $base . 'pos/icon-512.png' . $iv, $base . 'pos/apple-touch-icon.png' . $iv ),
			'logo'       => $base . 'pos/logo.svg' . $iv,
			'logo_light' => $base . 'pos/logo-light.svg' . $iv,
		);
	}

	private static function service_worker() {
		self::no_cache();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::path() );
		$a     = self::assets();
		$shell = array_merge( array( $a['css'], $a['js'], $a['zxing'], $a['logo'], $a['logo_light'] ), $a['icons'] );
		?>
/* PRDRWNDN POS service worker. Build <?php echo esc_js( self::build() ); ?> */
var CACHE = 'prdpos-<?php echo esc_js( self::build() ); ?>';
var SHELL = <?php echo wp_json_encode( $shell, JSON_UNESCAPED_SLASHES ); ?>;
var PAGE = <?php echo wp_json_encode( self::path(), JSON_UNESCAPED_SLASHES ); ?>;

self.addEventListener('install', function (e) {
	e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k.indexOf('prdpos-') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') { return; }
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	// The app page: always fresh when online (it carries the login), saved copy when offline.
	if (req.mode === 'navigate' && url.pathname === PAGE) {
		e.respondWith(fetch(req).then(function (res) {
			if (res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(PAGE, copy); }); }
			return res;
		}).catch(function () { return caches.match(PAGE); }));
		return;
	}
	// App files: from the saved copy first (their URLs change with every update).
	if (SHELL.some(function (s) { return req.url.indexOf(s.split('?')[0]) === 0 || req.url === s; })) {
		e.respondWith(caches.match(req).then(function (hit) { return hit || fetch(req); }));
	}
	// Everything else (store data) goes to the network; the app keeps its own offline copy.
});
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function app() {
		self::no_cache();

		// Sign out.
		if ( isset( $_GET['logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( is_user_logged_in() && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'prdrwndn_pos_logout' ) ) {
				wp_logout();
			}
			wp_safe_redirect( self::url() );
			exit;
		}

		// Sign in (form below posts here, so the installed app never leaves /pos/).
		$error = '';

		// Sign in with Google: Google sends the person back here with a signed answer.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['credential'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$result = PRDRWNDN_POS_Google::sign_in( self::path() );
			if ( ! is_wp_error( $result ) ) {
				wp_safe_redirect( self::url() );
				exit;
			}
			$error = PRDRWNDN_POS_Google::message( $result );
		}

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['prdpos_login'] ) ) {
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'prdrwndn_pos_login' ) ) {
				$error = __( 'The page was open too long. Try again.', 'prdrwndn-pos' );
			} else {
				$user = wp_signon(
					array(
						'user_login'    => sanitize_user( wp_unslash( $_POST['log'] ?? '' ) ),
						'user_password' => (string) wp_unslash( $_POST['pwd'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
						'remember'      => true,
					),
					is_ssl()
				);
				if ( is_wp_error( $user ) ) {
					$error = __( 'Wrong username or password.', 'prdrwndn-pos' );
				} else {
					wp_safe_redirect( self::url() );
					exit;
				}
			}
		}

		if ( ! is_user_logged_in() ) {
			self::page( 'login', array( 'error' => $error ) );
			return;
		}
		if ( ! current_user_can( PRDRWNDN_POS_Roles::CAP_SELL ) ) {
			self::page( 'denied' );
			return;
		}
		self::page( 'app' );
	}

	private static function page( $view, $args = array() ) {
		$a     = self::assets();
		$user  = wp_get_current_user();
		$title = 'PRDRWNDN POS';
		$cfg   = array();
		if ( 'app' === $view ) {
			$cfg = array(
				'rest'      => esc_url_raw( rest_url( PRDRWNDN_POS_REST::NS . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'nonceUrl'  => admin_url( 'admin-ajax.php?action=rest-nonce' ),
				'sw'        => self::path( 'sw.js' ),
				'scope'     => self::path(),
				'logout'    => add_query_arg(
					array(
						'logout'   => 1,
						'_wpnonce' => wp_create_nonce( 'prdrwndn_pos_logout' ),
					),
					self::url()
				),
				'user'      => $user->display_name,
				'userId'    => $user->ID,
				'labelsUrl' => current_user_can( PRDRWNDN_POS_Roles::CAP_LABELS ) ? admin_url( 'admin.php?page=prdrwndn-pos' ) : '',
				'build'     => self::build(),
				'zxing'     => $a['zxing'],
				'logo'      => $a['logo_light'],
			);
		}
		$gnonce = ( 'login' === $view && PRDRWNDN_POS_Google::enabled() ) ? PRDRWNDN_POS_Google::start( self::path() ) : '';
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#1E3B2F">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="color-scheme" content="light only">
<meta name="apple-mobile-web-app-title" content="PRD POS">
<title><?php echo esc_html( $title ); ?></title>
<link rel="manifest" href="<?php echo esc_url( self::url( 'manifest.webmanifest' ) ); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url( $a['icons'][2] ); ?>">
<link rel="icon" type="image/png" href="<?php echo esc_url( $a['icons'][0] ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( $a['css'] ); ?>">
</head>
<body class="view-<?php echo esc_attr( $view ); ?>">
		<?php if ( 'login' === $view ) : ?>
<main class="gate">
	<div class="gate-card">
		<img class="gate-logo" src="<?php echo esc_url( $a['logo'] ); ?>" alt="Proud Rwandan" width="200" height="149">
		<p class="gate-tag"><span class="pos-tag">POS</span></p>
		<h1 class="screen-reader-text">PRDRWNDN POS</h1>
		<p class="muted"><?php esc_html_e( 'Sign in with your store account.', 'prdrwndn-pos' ); ?></p>
			<?php if ( ! empty( $args['error'] ) ) : ?>
		<p class="gate-error" role="alert"><?php echo esc_html( $args['error'] ); ?></p>
		<?php endif; ?>
			<?php if ( $gnonce ) : ?>
		<div class="gate-google" id="gate-google">
			<div id="g_id_onload"
				data-client_id="<?php echo esc_attr( PRDRWNDN_POS_Google::client_id() ); ?>"
				data-ux_mode="redirect"
				data-login_uri="<?php echo esc_url( PRDRWNDN_POS_Google::redirect_uri() ); ?>"
				data-nonce="<?php echo esc_attr( $gnonce ); ?>"
				data-auto_prompt="false"></div>
			<div class="g_id_signin" data-type="standard" data-size="large" data-theme="outline" data-text="signin_with" data-shape="pill" data-logo_alignment="center" data-width="280"></div>
			<p class="gate-or"><span><?php esc_html_e( 'or use your password', 'prdrwndn-pos' ); ?></span></p>
		</div>
		<script src="https://accounts.google.com/gsi/client" async onload="document.getElementById('gate-google').classList.add('is-ready')"></script>
			<?php endif; ?>
		<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="gate-form">
			<?php wp_nonce_field( 'prdrwndn_pos_login' ); ?>
			<input type="hidden" name="prdpos_login" value="1">
			<label for="log"><?php esc_html_e( 'Username or email', 'prdrwndn-pos' ); ?></label>
			<input type="text" id="log" name="log" autocomplete="username" autocapitalize="off" required>
			<label for="pwd"><?php esc_html_e( 'Password', 'prdrwndn-pos' ); ?></label>
			<input type="password" id="pwd" name="pwd" autocomplete="current-password" required>
			<button type="submit" class="btn btn-primary btn-block"><?php esc_html_e( 'Sign in', 'prdrwndn-pos' ); ?></button>
		</form>
	</div>
</main>
		<?php elseif ( 'denied' === $view ) : ?>
<main class="gate">
	<div class="gate-card">
		<img class="gate-logo" src="<?php echo esc_url( $a['logo'] ); ?>" alt="Proud Rwandan" width="200" height="149">
		<p class="gate-tag"><span class="pos-tag">POS</span></p>
		<h1><?php esc_html_e( 'No POS access', 'prdrwndn-pos' ); ?></h1>
		<p class="muted"><?php echo esc_html( sprintf( /* translators: %s: user name */ __( '%s is signed in, but this account cannot use the POS. Ask the owner to make it a POS Cashier.', 'prdrwndn-pos' ), $user->display_name ) ); ?></p>
		<a class="btn btn-block" href="<?php echo esc_url( add_query_arg( array( 'logout' => 1, '_wpnonce' => wp_create_nonce( 'prdrwndn_pos_logout' ) ), self::url() ) ); ?>"><?php esc_html_e( 'Sign out', 'prdrwndn-pos' ); ?></a>
	</div>
</main>
		<?php else : ?>
<div id="app" class="app" aria-busy="true">
	<p class="booting"><?php esc_html_e( 'Loading...', 'prdrwndn-pos' ); ?></p>
</div>
<script>window.PRDPOS = <?php echo wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES ); ?>;</script>
<script src="<?php echo esc_url( $a['js'] ); ?>"></script>
		<?php endif; ?>
</body>
</html>
		<?php
	}
}
