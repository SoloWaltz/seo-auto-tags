<?php
/**
 * AJAX 接口。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 处理编辑器的异步请求。
 */
class SEO_Auto_Tags_Ajax {

	/**
	 * 单次最多创建多少个标签（防滥用）。
	 */
	const MAX_APPLY = 12;

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_action( 'wp_ajax_seo_auto_tags_generate', array( __CLASS__, 'generate' ) );
		add_action( 'wp_ajax_seo_auto_tags_apply', array( __CLASS__, 'apply' ) );
		add_action( 'wp_ajax_seo_auto_tags_list_generate', array( __CLASS__, 'list_generate' ) );
		add_action( 'wp_ajax_seo_auto_tags_list_apply', array( __CLASS__, 'list_apply' ) );
		add_action( 'wp_ajax_seo_auto_tags_test', array( __CLASS__, 'test' ) );
	}

	/**
	 * 统一的安全检查。
	 *
	 * @param string $capability 需要的权限。
	 */
	private static function guard( $capability ) {
		$nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'seo_auto_tags_nonce' ) ) {
			wp_send_json_error( array( 'message' => '安全校验失败，请刷新页面重试。' ), 403 );
		}

		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( array( 'message' => '当前账号没有这个权限。' ), 403 );
		}
	}

	/**
	 * 取 POST 里的文章 ID 并校验编辑权限。
	 *
	 * @return int
	 */
	private static function checked_post_id() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => '缺少文章 ID。' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '你没有编辑这篇文章的权限。' ), 403 );
		}

		return $post_id;
	}

	/**
	 * 把前端传来的 JSON 标签数组清洗成干净的名字列表。
	 *
	 * @param string $raw JSON 字符串。
	 * @return array
	 */
	private static function clean_names( $raw ) {
		$list = json_decode( (string) $raw, true );

		if ( ! is_array( $list ) || empty( $list ) ) {
			return array();
		}

		$out  = array();
		$seen = array();

		foreach ( array_slice( $list, 0, self::MAX_APPLY ) as $name ) {
			$name = trim( sanitize_text_field( (string) $name ) );

			if ( '' === $name ) {
				continue;
			}

			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $name, 'UTF-8' ) : strlen( $name );

			if ( $len < 2 || $len > 40 ) {
				continue;
			}

			$key = strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$out[]        = $name;
		}

		return $out;
	}

	/**
	 * 按字符数截断字符串（多字节安全）。
	 *
	 * @param string $s   原始字符串。
	 * @param int    $max 最大字符数。
	 * @return string
	 */
	private static function clip( $s, $max ) {
		$s = (string) $s;
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );

		if ( $len <= $max ) {
			return $s;
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max, 'UTF-8' ) : substr( $s, 0, $max );
	}

	/**
	 * 标记哪些候选标签是站内已有的。
	 *
	 * @param array $tags 标签名列表。
	 * @return array
	 */
	private static function mark_existing( $tags ) {
		$out = array();

		foreach ( (array) $tags as $name ) {
			$out[ $name ] = (bool) term_exists( $name, 'post_tag' );
		}

		return $out;
	}

	/**
	 * 生成候选标签（编辑器面板用）。
	 */
	public static function generate() {
		self::guard( 'edit_posts' );

		$title   = isset( $_POST['title'] ) ? wp_unslash( (string) $_POST['title'] ) : '';
		$content = isset( $_POST['content'] ) ? wp_unslash( (string) $_POST['content'] ) : '';

		// 限制请求体，避免异常输入占用过多内存。按字符（而非字节）截断，避免切到多字节字符中间产生乱码。
		$title   = self::clip( $title, 5000 );
		$content = self::clip( $content, 500000 );

		$res = SEO_Auto_Tags_Generator::generate( $title, $content, SEO_Auto_Tags_Settings::get( 'count' ) );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'tags'       => $res['tags'],
				'source'     => $res['source'],
				'fallback'   => $res['fallback'],
				'from_cache' => ! empty( $res['from_cache'] ),
				'note'       => isset( $res['note'] ) ? $res['note'] : '',
				'existing'   => self::mark_existing( $res['tags'] ),
			)
		);
	}

	/**
	 * 应用选中的标签：创建（或复用）term，返回 term_id 供编辑器写回。
	 */
	public static function apply() {
		self::guard( 'edit_posts' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => '缺少文章 ID。' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => '你没有编辑这篇文章的权限。' ), 403 );
		}

		$names = self::clean_names( isset( $_POST['tags'] ) ? wp_unslash( (string) $_POST['tags'] ) : '' );

		if ( empty( $names ) ) {
			wp_send_json_error( array( 'message' => '没有选中任何合法标签。' ) );
		}

		$out     = array();
		$created = false;

		foreach ( $names as $name ) {
			$exists = term_exists( $name, 'post_tag' );

			if ( $exists ) {
				$term_id = is_array( $exists ) ? (int) $exists['term_id'] : (int) $exists;
			} else {
				$new = wp_insert_term( $name, 'post_tag' );

				if ( is_wp_error( $new ) ) {
					// 并发下可能刚好被别人建了，再查一次。
					$again = term_exists( $name, 'post_tag' );

					if ( ! $again ) {
						continue;
					}

					$term_id = is_array( $again ) ? (int) $again['term_id'] : (int) $again;
				} else {
					$term_id = (int) $new['term_id'];
					$created = true;
				}
			}

			if ( $term_id > 0 ) {
				$out[] = array(
					'id'   => $term_id,
					'name' => $name,
				);
			}
		}

		if ( empty( $out ) ) {
			wp_send_json_error( array( 'message' => '标签创建失败，请检查标签名是否合法。' ) );
		}

		if ( $created ) {
			SEO_Auto_Tags_Generator::flush_terms_cache();
		}

		wp_send_json_success( array( 'terms' => $out ) );
	}

	/**
	 * 文章列表页：读取库里的正文并生成候选标签。
	 */
	public static function list_generate() {
		self::guard( 'edit_posts' );

		$post_id = self::checked_post_id();
		$post    = get_post( $post_id );

		if ( ! $post ) {
			wp_send_json_error( array( 'message' => '文章不存在。' ) );
		}

		$res = SEO_Auto_Tags_Generator::generate(
			$post->post_title,
			$post->post_content,
			SEO_Auto_Tags_Settings::get( 'count' )
		);

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'tags'       => $res['tags'],
				'source'     => $res['source'],
				'from_cache' => ! empty( $res['from_cache'] ),
				'note'       => isset( $res['note'] ) ? $res['note'] : '',
			)
		);
	}

	/**
	 * 文章列表页：直接把标签写进文章（追加，不覆盖原有标签）。
	 */
	public static function list_apply() {
		self::guard( 'edit_posts' );

		$post_id = self::checked_post_id();
		$names   = self::clean_names( isset( $_POST['tags'] ) ? wp_unslash( (string) $_POST['tags'] ) : '' );

		if ( empty( $names ) ) {
			wp_send_json_error( array( 'message' => '没有选中任何合法标签。' ) );
		}

		$res = wp_set_post_tags( $post_id, $names, true );

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		SEO_Auto_Tags_Generator::flush_terms_cache();

		$terms = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		wp_send_json_success(
			array(
				'applied' => $names,
				'total'   => is_wp_error( $terms ) ? 0 : count( $terms ),
			)
		);
	}

	/**
	 * 测试 AI 接口连通性。
	 */
	public static function test() {
		self::guard( 'manage_options' );

		// 测试会消耗少量 token，限制连点。
		$throttle = SEO_Auto_Tags_Generator::check_test_throttle();

		if ( is_wp_error( $throttle ) ) {
			wp_send_json_error( array( 'message' => $throttle->get_error_message() ) );
		}

		$res = SEO_Auto_Tags_Generator::test_connection();

		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}

		wp_send_json_success( array( 'message' => '连接正常，模型回复：' . $res['reply'] ) );
	}
}

