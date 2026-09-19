<?php
/**
 * Upgrader skin for the language-pack batch: reports each pack's install
 * state to the batch's progress record.
 *
 * Language_Pack_Upgrader::bulk_upgrade() removes every upgrader_pre_install
 * and upgrader_post_install filter before it runs, so the per-pack states
 * the plugin batch takes from those filters have to come from the skin.
 * WP_Upgrader::run() feeds it unpack_package, installing_package and
 * process_success / process_failed for each pack, with the pack itself on
 * $this->language_update (set by bulk_upgrade before each run).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

class Minn_Admin_Translation_Skin extends WP_Ajax_Upgrader_Skin {

	/** @var callable|null function ( object $language_update, string $state, string $error = '' ) */
	public $on_state = null;

	/** The pack whose run is in progress; bulk_upgrade sets it before each run. */
	public $language_update = null;

	private $last_error = '';

	private function report( $state, $error = '' ) {
		if ( is_callable( $this->on_state ) && ! empty( $this->language_update ) ) {
			call_user_func( $this->on_state, $this->language_update, $state, $error );
		}
	}

	public function feedback( $feedback, ...$args ) {
		if ( 'unpack_package' === $feedback ) {
			$this->report( 'unpacking' );
		} elseif ( 'installing_package' === $feedback ) {
			$this->report( 'installing' );
		} elseif ( 'process_success' === $feedback ) {
			$this->report( 'done' );
		} elseif ( 'process_failed' === $feedback ) {
			$this->report( 'failed', $this->last_error );
			$this->last_error = '';
		}
		parent::feedback( $feedback, ...$args );
	}

	public function error( $errors, ...$args ) {
		if ( is_wp_error( $errors ) ) {
			$this->last_error = $errors->get_error_message();
		} elseif ( is_string( $errors ) ) {
			$this->last_error = isset( $this->upgrader->strings[ $errors ] ) ? $this->upgrader->strings[ $errors ] : $errors;
		}
		parent::error( $errors, ...$args );
	}
}
