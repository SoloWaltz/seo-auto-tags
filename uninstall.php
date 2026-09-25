<?php
/**
 * 卸载时清理设置与缓存。
 *
 * 只删除本插件的设置项与临时缓存，不涉及已创建的标签。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seo_auto_tags_options' );
delete_option( 'seo_auto_tags_usage' );

// 清除本插件的 transient。
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_seo_auto_tags_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_seo_auto_tags_' ) . '%'
	)
);
