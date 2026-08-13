<?php
/**
 * Plugin Name:       Coywolf Video Manager
 * Plugin URI:        https://coywolf.com/notes/coywolf-video-manager/
 * Description:        Manage, embed, and upload Cloudflare Stream videos from the WordPress admin — a searchable video block, play and like tracking, schema markup, captions, and a video XML sitemap.
 * Version:           1.0.59
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Coywolf
 * Author URI:        https://coywolf.com/jon-henshaw/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coywolf-video-manager
 * Update URI:        https://github.com/coywolf-llc/coywolf-video-manager
 *
 * @package CoywolfVideoManager
 *
 * Coywolf Video Manager
 * Copyright (C) 2026 Coywolf LLC
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License, version 2, as published
 * by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COYWOLF_CVM_FILE', __FILE__ );
define( 'COYWOLF_CVM_PATH', plugin_dir_path( __FILE__ ) );
define( 'COYWOLF_CVM_URL', plugin_dir_url( __FILE__ ) );

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
require_once __DIR__ . '/includes/class-github-updater.php';
// Flags this as the GitHub distribution (stripped from the WordPress.org build).
define( 'COYWOLF_CVM_GITHUB_BUILD', true );
/* wporg-strip:end */
require_once __DIR__ . '/includes/class-cvm-cloudflare.php';
require_once __DIR__ . '/includes/class-cvm-stats.php';
require_once __DIR__ . '/includes/class-cvm-index.php';
require_once __DIR__ . '/includes/class-cvm-settings.php';
require_once __DIR__ . '/includes/class-cvm-captions.php';
require_once __DIR__ . '/includes/class-cvm-rest.php';
require_once __DIR__ . '/includes/class-cvm-block.php';
require_once __DIR__ . '/includes/class-cvm-docs.php';
require_once __DIR__ . '/includes/class-cvm-admin.php';
require_once __DIR__ . '/includes/class-cvm-sitemap.php';
require_once __DIR__ . '/includes/class-coywolf-video-manager.php';

register_activation_hook( __FILE__, array( 'Coywolf_Video_Manager', 'on_activate' ) );
register_deactivation_hook( __FILE__, array( 'Coywolf_Video_Manager', 'on_deactivate' ) );

Coywolf_Video_Manager::instance();

/* wporg-strip:start — GitHub self-updater (removed from the WordPress.org build) */
// Wire in the GitHub self-updater so releases show up on Dashboard → Updates.
( new Coywolf_CVM_GitHub_Updater( __FILE__, Coywolf_Video_Manager::VERSION ) )->init();
/* wporg-strip:end */
