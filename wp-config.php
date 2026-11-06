<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'ani-sms' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', 'j8RYR]&58i!tDO]4' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '%-RL1bx5`jhC7AUK0Y_NoC)7*-U0bn{S~|5y}#Jed;:}s-wu=jSrsU,c+w^~p&0V' );
define( 'SECURE_AUTH_KEY',  'zc{^Na?<]Z!i2/fh=|cBzJ8XIRZ^jyxPx2EO=G~3d;B^I0d#tO`KN{kfR(IC1uI.' );
define( 'LOGGED_IN_KEY',    '/6Q^|f6D<$V:fbs(ON}l*A)`K>)7w|V$n].(CVRf~aL<&9 OXG ctxOb|v<0JQzy' );
define( 'NONCE_KEY',        '_z4V!0`hu&:ATyPoGz5jFVc%!i.H;~RB[+>|6``_Di_BdH4G]#CjHp~-iz/IP0,@' );
define( 'AUTH_SALT',        'niagoU7n~5pOW & j3Y8y+deD20<H}?03_V(Xh.`z_XtH[MydPTU;.7jb]TJeuZm' );
define( 'SECURE_AUTH_SALT', 'hQTC/Fb7WxA1)=-z!?qu7U4{wl2:m?5yb1hNK{5-.S;/b/Y3%&nE{VzSM]%MPYyp' );
define( 'LOGGED_IN_SALT',   'r^t~CGw=!7XW w?t|x.bP%?<5*k?ePkyNLEpVOT%F&Jm^`FN5(n+]aB,e6!p5w&;' );
define( 'NONCE_SALT',       '%2orh *(JCxj`+}Li)e}|YHgit_Gt:#|v^11[W~y*FT:U?X-E63/MrO{(a2R61;V' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'anisms_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
