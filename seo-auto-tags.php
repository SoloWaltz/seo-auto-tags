<?php
/**
 * Plugin Name:       SEO 自动标签
 * Plugin URI:        https://www.rrshare.com/
 * Description:       写文章时自动分析正文，按 SEO 思路生成候选标签，逐条勾选后再决定是否采用。
 * Version:           1.3.11
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            西瓜烧鱼
 * Author URI:        https://www.rrshare.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-auto-tags
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEO_AUTO_TAGS_VERSION', '1.3.11' );
define( 'SEO_AUTO_TAGS_FILE', __FILE__ );
define( 'SEO_AUTO_TAGS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEO_AUTO_TAGS_URL', plugin_dir_url( __FILE__ ) );
define( 'SEO_AUTO_TAGS_OPTION', 'seo_auto_tags_options' );

require_once SEO_AUTO_TAGS_DIR . 'includes/class-settings.php';
require_once SEO_AUTO_TAGS_DIR . 'includes/class-generator.php';
require_once SEO_AUTO_TAGS_DIR . 'includes/class-ajax.php';
require_once SEO_AUTO_TAGS_DIR . 'includes/class-metabox.php';
require_once SEO_AUTO_TAGS_DIR . 'includes/class-columns.php';

SEO_Auto_Tags_Settings::init();
SEO_Auto_Tags_Ajax::init();
SEO_Auto_Tags_Metabox::init();
SEO_Auto_Tags_Columns::init();

/**
 * 激活时检查运行环境，版本不足则拦截并说明原因。
 */
function seo_auto_tags_activate() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		deactivate_plugins( plugin_basename( SEO_AUTO_TAGS_FILE ) );

		wp_die(
			'<h1>无法启用</h1><p>SEO 自动标签需要 <strong>PHP 7.4 或更高版本</strong>，'
			. '当前服务器是 PHP ' . esc_html( PHP_VERSION ) . '。插件已自动停用。</p>'
			. '<p>请先到服务器面板把 PHP 版本升上去，再重新启用。</p>',
			'插件无法启用',
			array( 'back_link' => true )
		);
	}
}

register_activation_hook( SEO_AUTO_TAGS_FILE, 'seo_auto_tags_activate' );

/**
 * 缺少 mbstring 时给出提示。
 *
 * 代码有降级处理，不阻止使用，但中文长度判断会失准。
 */
function seo_auto_tags_mbstring_notice() {
	if ( function_exists( 'mb_strlen' ) ) {
		return;
	}

	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p><strong>SEO 自动标签：</strong>'
		. '当前 PHP 没有启用 <code>mbstring</code> 扩展，中文标签提取会明显不准。'
		. '请到「宝塔面板 → 软件商店 → PHP 设置 → 安装扩展」启用 mbstring，然后重启 PHP。</p></div>';
}

add_action( 'admin_notices', 'seo_auto_tags_mbstring_notice' );

