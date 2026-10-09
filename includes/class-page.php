<?php
/**
 * Phone and desktop stock page at /stock/.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Page {

	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'wp', array( __CLASS__, 'mark_uncached' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
		add_action( 'template_redirect', array( __CLASS__, 'render' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 70 );
	}

	public static function register_rewrite() {
		add_rewrite_rule( '^stock/?$', 'index.php?bs_stock=1', 'top' );
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'bs_stock';
		return $vars;
	}

	public static function mark_uncached() {
		if ( self::is_stock_request() && ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	public static function headers() {
		if ( ! self::is_stock_request() ) {
			return;
		}

		self::nocache();
	}

	public static function menu() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'Stock', 'bikesense-stock' ),
			__( 'Stock', 'bikesense-stock' ),
			'manage_woocommerce',
			'bikesense-stock',
			array( __CLASS__, 'admin_redirect' )
		);
	}

	public static function admin_redirect() {
		wp_safe_redirect( home_url( '/stock/' ) );
		exit;
	}

	public static function render() {
		if ( ! self::is_stock_request() ) {
			return;
		}

		self::nocache();

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( '/stock/' ) ) );
			exit;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Your account cannot take stock.', 'bikesense-stock' ), esc_html__( 'Stock', 'bikesense-stock' ), array( 'response' => 403 ) );
		}

		$css  = BS_STOCK_URL . 'assets/stock.css?ver=' . BS_STOCK_VERSION;
		$js   = BS_STOCK_URL . 'assets/stock.js?ver=' . BS_STOCK_VERSION;
		$jobs = BS_STOCK_URL . 'assets/jobs.js?ver=' . BS_STOCK_VERSION;
		$scan = BS_STOCK_URL . 'assets/html5-qrcode.min.js?ver=2.3.8';
		$icon = BS_STOCK_URL . 'assets/icon-192.png';
		$user = wp_get_current_user();

		$config = array(
			'root'   => esc_url_raw( rest_url( BS_Stock_API::NAMESPACE . '/' ) ),
			'nonce'  => wp_create_nonce( 'wp_rest' ),
			'user'   => $user->display_name,
			'userId' => (int) $user->ID,
			'today'  => current_time( 'Y-m-d' ),
			'logout' => wp_logout_url( home_url( '/stock/' ) ),
		);

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex, nofollow">
	<meta name="theme-color" content="#141414">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="Stock">
	<title>Stock · Bike Sense</title>
	<link rel="manifest" href="<?php echo esc_url( BS_STOCK_URL . 'assets/manifest.webmanifest' ); ?>">
	<link rel="icon" href="<?php echo esc_url( $icon ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $icon ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $css ); ?>">
</head>
<body>
	<header class="top">
		<div class="brand">
			<p class="eyebrow">Bike Sense</p>
			<h1>Stock</h1>
		</div>
		<div class="who">
			<span><?php echo esc_html( $user->display_name ); ?></span>
			<a href="<?php echo esc_url( $config['logout'] ); ?>">Log out</a>
		</div>
	</header>
	<nav class="nav" id="nav" aria-label="Stock"></nav>
	<main id="view" class="view">
		<p class="muted">Loading stock…</p>
	</main>
	<div id="toast" class="toast" role="status" hidden></div>
	<noscript><p class="noscript">Stock needs JavaScript so the page can save counts.</p></noscript>
	<script>window.BS_STOCK = <?php echo wp_json_encode( $config ); ?>;</script>
	<script src="<?php echo esc_url( $scan ); ?>" onerror="this.onerror=null;this.src='https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';"></script>
	<script src="<?php echo esc_url( $js ); ?>"></script>
	<script src="<?php echo esc_url( $jobs ); ?>"></script>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * @return bool
	 */
	private static function is_stock_request() {
		return '1' === (string) get_query_var( 'bs_stock' );
	}

	private static function nocache() {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		nocache_headers();
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
	}
}
