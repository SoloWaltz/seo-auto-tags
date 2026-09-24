<?php
/**
 * 文章列表页的「SEO 标签」列与一键生成。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 在文章列表里直接看到标签状态，并一键补齐。
 */
class SEO_Auto_Tags_Columns {

	/**
	 * 超过这个数量就提示「偏多」。
	 */
	const TOO_MANY = 8;

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'hooks' ) );
	}

	/**
	 * 按文章类型挂列。
	 */
	public static function hooks() {
		if ( ! SEO_Auto_Tags_Settings::get( 'enabled' ) ) {
			return;
		}

		$types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( (array) $types as $type ) {
			if ( ! is_object_in_taxonomy( $type, 'post_tag' ) ) {
				continue;
			}

			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'add_column' ), 20 );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * 在「标签」列后面插入一列。
	 *
	 * @param array $cols 现有列。
	 * @return array
	 */
	public static function add_column( $cols ) {
		$out   = array();
		$added = false;

		foreach ( (array) $cols as $key => $label ) {
			$out[ $key ] = $label;

			if ( 'tags' === $key ) {
				$out['seo_auto_tags_tags'] = 'SEO 标签';
				$added                = true;
			}
		}

		if ( ! $added ) {
			$out['seo_auto_tags_tags'] = 'SEO 标签';
		}

		return $out;
	}

	/**
	 * 渲染列内容。
	 *
	 * @param string $col     列名。
	 * @param int    $post_id 文章 ID。
	 */
	public static function render_column( $col, $post_id ) {
		if ( 'seo_auto_tags_tags' !== $col ) {
			return;
		}

		$terms = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}

		$n = count( $terms );

		echo '<span data-seo-auto-tags-count>';

		if ( 0 === $n ) {
			echo '<span class="seo-auto-tags-col-empty">未打标签</span>';
		} else {
			echo '<span class="seo-auto-tags-col-count">' . (int) $n . ' 个</span>';
			if ( $n > self::TOO_MANY ) {
				echo ' <span class="seo-auto-tags-col-empty">偏多</span>';
			}
		}

		echo '</span><br>';

		echo '<button type="button" class="seo-auto-tags-col-btn" data-seo-auto-tags-list-gen="'
			. esc_attr( (int) $post_id ) . '">一键生成</button>';
	}

	/**
	 * 加载列表页资源。
	 *
	 * @param string $hook 当前页面。
	 */
	public static function assets( $hook ) {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		if ( ! SEO_Auto_Tags_Settings::get( 'enabled' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! isset( $screen->post_type ) || ! is_object_in_taxonomy( $screen->post_type, 'post_tag' ) ) {
			return;
		}

		wp_enqueue_style( 'seo-auto-tags-admin', SEO_AUTO_TAGS_URL . 'assets/admin.css', array(), SEO_AUTO_TAGS_VERSION );

		wp_enqueue_script(
			'seo-auto-tags-columns',
			SEO_AUTO_TAGS_URL . 'assets/columns.js',
			array(),
			SEO_AUTO_TAGS_VERSION,
			true
		);

		wp_localize_script(
			'seo-auto-tags-columns',
			'SEO_AUTO_TAGS_COLUMNS',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'seo_auto_tags_nonce' ),
				'tooMany' => self::TOO_MANY,
			)
		);
	}
}
