<?php
/**
 * 编辑器面板（兼容区块编辑器与经典编辑器）。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 在文章编辑页右侧挂一个面板。
 */
class SEO_Auto_Tags_Metabox {

	/**
	 * 一篇文章的标签超过这个数量就提示「偏多」。
	 */
	const TOO_MANY = 8;

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * 注册 metabox（只挂在支持标签的文章类型上）。
	 */
	public static function add() {
		if ( ! SEO_Auto_Tags_Settings::get( 'enabled' ) ) {
			return;
		}

		$types = get_post_types( array( 'public' => true ), 'names' );

		foreach ( (array) $types as $type ) {
			if ( ! is_object_in_taxonomy( $type, 'post_tag' ) ) {
				continue;
			}

			add_meta_box(
				'seo-auto-tags-box',
				'SEO 自动标签',
				array( __CLASS__, 'render' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * 加载编辑器资源。
	 *
	 * @param string $hook 当前页面。
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! SEO_Auto_Tags_Settings::get( 'enabled' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && isset( $screen->post_type ) && ! is_object_in_taxonomy( $screen->post_type, 'post_tag' ) ) {
			return;
		}

		wp_enqueue_style(
			'seo-auto-tags-editor',
			SEO_AUTO_TAGS_URL . 'assets/editor.css',
			array(),
			SEO_AUTO_TAGS_VERSION
		);

		wp_enqueue_script(
			'seo-auto-tags-editor',
			SEO_AUTO_TAGS_URL . 'assets/editor.js',
			array(),
			SEO_AUTO_TAGS_VERSION,
			true
		);

		$opt = SEO_Auto_Tags_Settings::get();

		wp_localize_script(
			'seo-auto-tags-editor',
			'SEO_AUTO_TAGS_EDITOR',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'seo_auto_tags_nonce' ),
				'autoSuggest' => ! empty( $opt['auto_suggest'] ) ? 1 : 0,
				'mode'        => $opt['mode'],
			)
		);
	}

	/**
	 * 渲染面板。
	 *
	 * @param WP_Post $post 当前文章。
	 */
	public static function render( $post ) {
		$current = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		if ( is_wp_error( $current ) ) {
			$current = array();
		}

		$current_text = empty( $current ) ? '无' : implode( '、', $current );
		?>
		<div class="seo-auto-tags-panel"
			data-seo-auto-tags-panel
			data-post-id="<?php echo esc_attr( (int) $post->ID ); ?>">

			<p class="seo-auto-tags-hint">
				读正文内容，按 SEO 思路给候选标签。<strong>不勾选就不会写进文章。</strong>
			</p>

			<p class="seo-auto-tags-row">
				<button type="button" class="button button-primary seo-auto-tags-btn" data-seo-auto-tags-generate>
					生成标签
				</button>
				<button type="button" class="button button-secondary seo-auto-tags-btn" data-seo-auto-tags-copy hidden>
					复制结果
				</button>
			</p>

			<div class="seo-auto-tags-status" data-seo-auto-tags-status aria-live="polite"></div>

			<div class="seo-auto-tags-list" data-seo-auto-tags-list></div>

			<div class="seo-auto-tags-apply" data-seo-auto-tags-apply hidden>
				<p class="seo-auto-tags-row">
					<button type="button" class="button button-secondary seo-auto-tags-btn" data-seo-auto-tags-apply-btn>
						应用选中标签
					</button>
				</p>
				<p class="seo-auto-tags-row seo-auto-tags-links">
					<button type="button" class="button-link" data-seo-auto-tags-select-all>全选</button>
					<span class="seo-auto-tags-sep">·</span>
					<button type="button" class="button-link" data-seo-auto-tags-select-none>全不选</button>
				</p>
			</div>

			<div class="seo-auto-tags-current">
				<span class="seo-auto-tags-current-label">
					当前标签
					<span class="seo-auto-tags-count-badge" data-seo-auto-tags-current-count><?php echo (int) count( $current ); ?> 个</span>
				</span>
				<span class="seo-auto-tags-current-value" data-seo-auto-tags-current-value><?php echo esc_html( $current_text ); ?></span>
				<span class="seo-auto-tags-toomany" data-seo-auto-tags-toomany<?php echo count( $current ) > self::TOO_MANY ? '' : ' hidden'; ?>>
					标签偏多。每个标签都会生成一个归档页，太多会稀释整站权重，建议精简。
				</span>
			</div>
		</div>
		<?php
	}
}

