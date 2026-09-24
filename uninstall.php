<?php
/**
 * 卸载插件时清理设置与缓存。
 *
 * 注意：只删除插件自己的设置项和临时缓存，
 * 不会碰任何已创建的标签（那是网站内容）。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'seo_auto_tags_options' );
delete_option( 'seo_auto_tags_usage' );

// 清掉本插件产生的所有 transient（术语缓存、AI 结果缓存、限速计数）。
global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_seo_auto_tags_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_seo_auto_tags_' ) . '%'
	)
);
