<?php
/**
 * Main plugin loader for Coywolf Video Manager.
 *
 * Composition root: instantiates every module once, wires the shared
 * activation/deactivation lifecycle, and exposes the module singletons so
 * modules (and the REST layer) can reach one another.
 *
 * @package CoywolfVideoManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap singleton.
 */
final class Coywolf_Video_Manager {

	/**
	 * Plugin version. Kept in sync with the main-file "Version:" header by the
	 * release workflow (it bumps both).
	 */
	const VERSION = '1.0.60';

	/**
	 * Capability that gates every admin screen and admin REST route.
	 */
	const CAPABILITY = 'coywolf_cvm_manage';

	/**
	 * Hourly cron hook that refreshes the cached Cloudflare video list.
	 */
	const CRON_RECONCILE = 'coywolf_cvm_reconcile';

	/**
	 * Singleton instance.
	 *
	 * @var Coywolf_Video_Manager|null
	 */
	private static $instance = null;

	/**
	 * Cloudflare Stream API client.
	 *
	 * @var Coywolf_CVM_Cloudflare
	 */
	private $cloudflare;

	/**
	 * Settings module.
	 *
	 * @var Coywolf_CVM_Settings
	 */
	private $settings;

	/**
	 * Local play/like statistics store.
	 *
	 * @var Coywolf_CVM_Stats
	 */
	private $stats;

	/**
	 * Post ↔ video usage index.
	 *
	 * @var Coywolf_CVM_Index
	 */
	private $index;

	/**
	 * Caption schema cache (transcripts + downloadable VTT tracks).
	 *
	 * @var Coywolf_CVM_Captions
	 */
	private $captions;

	/**
	 * REST controller.
	 *
	 * @var Coywolf_CVM_REST
	 */
	private $rest;

	/**
	 * Editor block.
	 *
	 * @var Coywolf_CVM_Block
	 */
	private $block;

	/**
	 * Admin screens.
	 *
	 * @var Coywolf_CVM_Admin
	 */
	private $admin;

	/**
	 * Video XML sitemap.
	 *
	 * @var Coywolf_CVM_Sitemap
	 */
	private $sitemap;

	/**
	 * Get the singleton instance.
	 *
	 * @return Coywolf_Video_Manager
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire the modules together and register shared hooks.
	 */
	private function __construct() {
		$this->cloudflare = new Coywolf_CVM_Cloudflare();
		$this->stats      = new Coywolf_CVM_Stats();
		$this->index      = new Coywolf_CVM_Index();
		$this->settings   = new Coywolf_CVM_Settings( $this->cloudflare );
		$this->captions   = new Coywolf_CVM_Captions( $this->cloudflare );
		$this->rest       = new Coywolf_CVM_REST( $this->cloudflare, $this->stats, $this->index, $this->captions );
		$this->block      = new Coywolf_CVM_Block( $this->cloudflare, $this->settings, $this->stats, $this->captions );
		$this->admin      = new Coywolf_CVM_Admin( $this->cloudflare, $this->stats, $this->index, $this->settings );
		$this->sitemap    = new Coywolf_CVM_Sitemap( $this->cloudflare, $this->index, $this->settings );

		add_action( self::CRON_RECONCILE, array( $this, 'reconcile' ) );
	}

	/**
	 * Hourly cron: drop the cached video list so the next admin view re-fetches
	 * fresh data from Cloudflare, and let the index prune stale rows.
	 */
	public function reconcile() {
		$this->cloudflare->flush_list_cache();
		$this->index->prune();
	}

	/**
	 * Cloudflare Stream API client.
	 *
	 * @return Coywolf_CVM_Cloudflare
	 */
	public function cloudflare() {
		return $this->cloudflare;
	}

	/**
	 * Settings module.
	 *
	 * @return Coywolf_CVM_Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Play/like statistics store.
	 *
	 * @return Coywolf_CVM_Stats
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Post ↔ video usage index.
	 *
	 * @return Coywolf_CVM_Index
	 */
	public function index() {
		return $this->index;
	}

	/**
	 * Caption schema cache.
	 *
	 * @return Coywolf_CVM_Captions
	 */
	public function captions() {
		return $this->captions;
	}

	/**
	 * Purge all local traces of a deleted video: strip its block from any
	 * post/page, and drop its stats, poster, and cached metadata.
	 *
	 * @param string $uid Video UID.
	 */
	public function purge_video( $uid ) {
		$this->index->remove_video( $uid );
		$this->stats->delete_uid( $uid );
		$this->captions->purge( $uid );

		foreach ( array( 'coywolf_cvm_posters', 'coywolf_cvm_descriptions', 'coywolf_cvm_downloads' ) as $store ) {
			$all = get_option( $store, array() );
			if ( is_array( $all ) && isset( $all[ $uid ] ) ) {
				unset( $all[ $uid ] );
				update_option( $store, $all, false );
			}
		}
		delete_transient( 'coywolf_cvm_meta_' . md5( $uid ) );
	}

	/**
	 * Activation: create tables, grant the capability, register defaults and the
	 * sitemap rewrite, then flush so the rule takes effect, and schedule the
	 * reconcile cron.
	 */
	public static function on_activate() {
		Coywolf_CVM_Stats::create_tables();
		Coywolf_CVM_Index::create_tables();
		Coywolf_CVM_Settings::seed_defaults();

		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( self::CAPABILITY ) ) {
			$role->add_cap( self::CAPABILITY );
		}

		Coywolf_CVM_Sitemap::register_rewrite();
		flush_rewrite_rules();

		if ( ! wp_next_scheduled( self::CRON_RECONCILE ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_RECONCILE );
		}
	}

	/**
	 * Deactivation: flush rewrites and unschedule the cron. The capability and
	 * stored data are left in place until uninstall.
	 */
	public static function on_deactivate() {
		flush_rewrite_rules();

		$timestamp = wp_next_scheduled( self::CRON_RECONCILE );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_RECONCILE );
		}

		wp_unschedule_hook( Coywolf_CVM_Captions::CRON_HOOK );
	}
}
