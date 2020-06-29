<?php

namespace Elementor\Modules\Screenshots;

use Elementor\Plugin;
use Elementor\Frontend;
use Elementor\Core\Files\CSS\Post_Preview;
use Elementor\Core\Base\Module as BaseModule;
use Elementor\User;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Module extends BaseModule {

	public function get_name() {
		return 'screenshots';
	}

	public function screenshot_proxy() {
		if ( ! wp_verify_nonce( $_GET['nonce'], 'screenshot_proxy' ) ) {
			echo '';
		}

		if ( ! empty( $_GET['href'] ) ) {
			$response = wp_remote_get( utf8_decode( $_GET['href'] ) );
			$body = wp_remote_retrieve_body( $response );
			if ( $body ) {
				echo $body;
			}
		}
	}

	public function ajax_save( $data ) {
		if ( empty( $data['screenshot'] ) ) {
			return false;
		}

		$post_id = $data['post_id'];

		$file_content = substr( $data['screenshot'], strlen( 'data:image/png;base64,' ) );
		$file_name = 'Elementor Post Screenshot ' . $post_id . '.png';
		$over_write_file_name_callback = function () use ( $file_name ) {
			return $file_name;
		};

		add_filter( 'wp_unique_filename', $over_write_file_name_callback );

		$upload = wp_upload_bits(
			$file_name,
			null,
			base64_decode( $file_content )
		);

		remove_filter( 'wp_unique_filename', $over_write_file_name_callback );

		$attachment_data = get_post_meta( $post_id, '_elementor_screenshot', true );

		if ( $attachment_data ) {
			return $attachment_data['url'];
		}

		$post = [
			'post_title' => $file_name,
			'guid' => $upload['url'],
		];

		$info = wp_check_filetype( $upload['file'] );

		if ( $info ) {
			$post['post_mime_type'] = $info['type'];
		}

		$attachment_id = wp_insert_attachment( $post, $upload['file'] );

		$attachment_data = [
			'id' => $attachment_id,
			'url' => $upload['url'],
		];

		update_post_meta( $post_id, '_elementor_screenshot', $attachment_data );

		wp_update_attachment_metadata(
			$attachment_id,
			wp_generate_attachment_metadata( $attachment_id, $upload['file'] )
		);

		return $upload['url'];
	}

	public function enqueue_scripts() {
		if ( ! $this->is_in_screenshot_mode() || ! User::is_current_user_can_edit() ) {
			return;
		}

		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG || defined( 'ELEMENTOR_TESTS' ) && ELEMENTOR_TESTS ) ? '' : '.min';

		wp_enqueue_script(
			'dom-to-image',
			ELEMENTOR_ASSETS_URL . "/lib/dom-to-image/js/dom-to-image{$suffix}.js",
			[],
			'2.6.0',
			true
		);

		wp_enqueue_script(
			'elementor-screenshot',
			ELEMENTOR_URL . "modules/screenshots/assets/js/preview/screenshot{$suffix}.js",
			[ 'dom-to-image' ],
			ELEMENTOR_VERSION,
			true
		);

		$post_id = get_queried_object_id();

		$config = [
			'selector' => '.elementor-' . $post_id,
			'nonce' => wp_create_nonce( 'screenshot_proxy' ),
			'home_url' => home_url(),
			'post_id' => $post_id,
			'debug' => SCRIPT_DEBUG,
		];

		wp_add_inline_script( 'elementor-screenshot', 'var ElementorScreenshotConfig = ' . wp_json_encode( $config ) . ';' );

		$css = Post_Preview::create( $post_id );
		$css->enqueue();
	}

	/**
	 * @param \Elementor\Core\Common\Modules\Ajax\Module $ajax_manager
	 */
	public function register_ajax_actions( $ajax_manager ) {
		$ajax_manager->register_ajax_action( 'screenshot_save', [ $this, 'ajax_save' ] );
	}

	/**
	 * Extends document config with screenshot URL.
	 *
	 * @param $config
	 *
	 * @return array
	 */
	public function extend_document_config( $config ) {
		$post_id = get_queried_object_id();

		add_filter( 'pre_option_permalink_structure', '__return_empty_string' );

		$url = set_url_scheme( add_query_arg( [
			'elementor-screenshot' => $post_id,
			'ver' => time(),
		], get_permalink( $post_id ) ) );

		remove_filter( 'pre_option_permalink_structure', '__return_empty_string' );

		return array_replace_recursive( $config, [
			'urls' => [
				'screenshot' => $url,
			],
		] );
	}

	/**
	 * @return bool
	 */
	protected function is_in_screenshot_mode() {
		return isset( $_REQUEST['elementor-screenshot'] );
	}

	public function __construct() {
		if ( isset( $_REQUEST['screenshot_proxy'] ) ) {
			$this->screenshot_proxy();
			die;
		}

		if ( $this->is_in_screenshot_mode() ) {
			show_admin_bar( false );

			Plugin::$instance->frontend->set_render_mode( Frontend::RENDER_MODE_STATIC );
		}

		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ], 1000 );

		add_action( 'elementor/ajax/register_actions', [ $this, 'register_ajax_actions' ] );

		add_filter( 'elementor/document/config', [ $this, 'extend_document_config' ] );
	}
}
