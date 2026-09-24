<?php
/**
 * 标签生成器：本地算法 + AI 双模式。
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 生成候选标签。
 */
class SEO_Auto_Tags_Generator {

	/**
	 * 单次分析的最大字符数。
	 *
	 * n-gram 的候选数量与正文长度成正比，超长正文会显著抬高峰值内存。
	 * 超出部分按段落均匀采样，只取这个量。
	 */
	const MAX_ANALYZE_CHARS = 30000;

	/**
	 * 发给 AI 的正文预算（字符）。
	 *
	 * 约 2400 token，足够判断主题。超出部分做头尾采样。
	 */
	const AI_INPUT_CHARS = 4000;

	/**
	 * 生成入口。
	 *
	 * @param string $title   文章标题。
	 * @param string $content 文章正文。
	 * @param int    $count   需要几个。
	 * @return array|WP_Error
	 */
	public static function generate( $title, $content, $count = 5 ) {
		$opt     = SEO_Auto_Tags_Settings::get();
		$count   = max( 1, min( 12, absint( $count ) ) );
		$title   = self::clean( $title );
		$content = self::trim_for_analysis( self::clean( $content ) );

		if ( self::len( $content ) < 40 ) {
			return new WP_Error( 'seo_auto_tags_too_short', '正文太短（不足 40 字），没法分析。先写点内容再试。' );
		}

		$ai_mode    = ( 'ai' === $opt['mode'] && '' !== SEO_Auto_Tags_Settings::api_key() );
		$tags       = array();
		$used_ai    = false;
		$from_cache = false;
		$note       = '';

		if ( $ai_mode ) {
			$ttl  = isset( $opt['cache_ttl'] ) ? absint( $opt['cache_ttl'] ) : 24;
			$ckey = self::ai_cache_key( $title, $content, $count );

			// 先查缓存。
			if ( $ttl > 0 ) {
				$cached = get_transient( $ckey );
				if ( is_array( $cached ) && ! empty( $cached ) ) {
					$tags       = $cached;
					$used_ai    = true;
					$from_cache = true;
				}
			}

			if ( empty( $tags ) ) {
				$rl = self::check_rate_limit( $opt );

				if ( is_wp_error( $rl ) ) {
					$note = $rl->get_error_message();
				} else {
					$res = self::via_ai( $title, $content, $count, $opt );

					if ( is_wp_error( $res ) ) {
						// 保留失败原因，供界面展示。
						$note = 'AI 调用失败：' . $res->get_error_message();
					} else {
						$tags    = $res;
						$used_ai = true;

						if ( $ttl > 0 ) {
							set_transient( $ckey, $tags, $ttl * HOUR_IN_SECONDS );
						}
					}
				}
			}
		}

		if ( empty( $tags ) ) {
			$tags = self::via_local( $title, $content, $count, $opt, 0 );
		}

		$tags = self::finalize( $tags, $count );

		// 候选不足时按级别放宽补齐，严格结果优先。
		if ( ! $used_ai && count( $tags ) < $count ) {
			$levels = array( 1 );

			// 连 3 个都凑不出时才允许泛用词兜底，宁可少给也不要塞垃圾词。
			if ( count( $tags ) < 3 ) {
				$levels[] = 2;
			}

			foreach ( $levels as $level ) {
				if ( count( $tags ) >= $count ) {
					break;
				}

				$extra = self::finalize( self::via_local( $title, $content, $count, $opt, $level ), $count );

				foreach ( $extra as $t ) {
					if ( count( $tags ) >= $count ) {
						break;
					}
					if ( ! in_array( $t, $tags, true ) ) {
						$tags[] = $t;
					}
				}
			}
		}

		if ( empty( $tags ) ) {
			return new WP_Error( 'seo_auto_tags_empty', '没能提取出合适的标签，可能是这篇文章的有效信息太少。' );
		}

		return array(
			'tags'       => $tags,
			'source'     => $used_ai ? 'ai' : 'local',
			'fallback'   => ( $ai_mode && ! $used_ai ),
			'from_cache' => $from_cache,
			'note'       => $note,
		);
	}

	/**
	 * AI 结果的缓存键：按「标题 + 正文 + 接口配置」指纹。
	 *
	 * @param string $title   标题。
	 * @param string $content 正文。
	 * @return string
	 */
	private static function ai_cache_key( $title, $content, $count = 0 ) {
		$opt     = SEO_Auto_Tags_Settings::get();
		$context = implode(
			'|',
			array(
				$title,
				$content,
				(string) $count,
				(string) $opt['api_base'],
				(string) $opt['model'],
				(string) $opt['provider'],
			)
		);

		return 'seo_auto_tags_ai_' . md5( $context );
	}

	/**
	 * AI 调用频率限制（按用户、按小时）。
	 *
	 * 作者与编辑角色均可使用本插件，不限速会持续消耗 API 额度。
	 *
	 * @param array $opt 设置。
	 * @return true|WP_Error
	 */
	private static function check_rate_limit( $opt ) {
		$limit = isset( $opt['rate_limit'] ) ? absint( $opt['rate_limit'] ) : 30;

		if ( $limit <= 0 ) {
			return true; // 0 表示不限速。
		}

		// 用小时时间片做键，构成固定窗口。
		// 若直接对同一个键续期，过期时间会不断后推，计数无法复位。
		$slot = (int) floor( time() / HOUR_IN_SECONDS );
		$key  = 'seo_auto_tags_rl_' . get_current_user_id() . '_' . $slot;

		$used = (int) get_transient( $key );

		if ( $used >= $limit ) {
			return new WP_Error(
				'seo_auto_tags_rate_limited',
				sprintf( 'AI 调用已达上限（每小时 %d 次），已自动改用本地算法。', $limit )
			);
		}

		// TTL 比窗口略长，保证整个窗口内计数不会提前丢。
		set_transient( $key, $used + 1, HOUR_IN_SECONDS + 300 );

		return true;
	}

	/**
	 * 「测试连接」的节流。
	 *
	 * 测试会消耗少量 token，且附带 DNS 解析，需限制调用频率。
	 *
	 * @return true|WP_Error
	 */
	public static function check_test_throttle() {
		$key  = 'seo_auto_tags_test_rl_' . get_current_user_id();
		$used = (int) get_transient( $key );

		if ( $used >= 5 ) {
			return new WP_Error( 'seo_auto_tags_test_throttled', '测试太频繁了，请等一分钟再试。' );
		}

		set_transient( $key, $used + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * 清洗文本：去短代码、脚本、HTML、网址。
	 *
	 * 保留段落换行，供后续采样按段落切分。
	 *
	 * @param string $text 原始文本。
	 * @return string
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		$text = strip_shortcodes( $text );
		$text = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text );

		// 块级标签先换成换行，保住段落结构。
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = preg_replace( '#</(p|div|li|h[1-6]|tr|section|article|blockquote)\s*>#i', "\n", $text );
		$text = preg_replace( '#<(p|div|li|h[1-6]|tr|section|article|blockquote)\b[^>]*>#i', "\n", $text );

		$text = wp_strip_all_tags( $text, false );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '#https?://\S+#i', ' ', $text );

		// 折叠空格与多余空行，但保留单个换行。
		$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/\n[ \t]*/', "\n", $text );
		$text = preg_replace( '/\n{2,}/', "\n", $text );

		return trim( (string) $text );
	}

	/**
	 * 超长正文按段落跳段采样，压到预算以内。
	 *
	 * 关键词常分散在全篇，逐段取样比截断前半段覆盖更全。
	 *
	 * @param string $text 已清洗的正文。
	 * @return string
	 */
	private static function trim_for_analysis( $text ) {
		if ( self::len( $text ) <= self::MAX_ANALYZE_CHARS ) {
			return $text;
		}

		$paras = preg_split( '/\n+/u', $text );
		$paras = array_values(
			array_filter(
				(array) $paras,
				function ( $p ) {
					return '' !== trim( (string) $p );
				}
			)
		);

		$total = count( $paras );

		// 没有段落结构，只能按字符截断。
		if ( $total < 3 ) {
			return self::sub( $text, 0, self::MAX_ANALYZE_CHARS );
		}

		$picked = array();
		$used   = 0;
		$step   = max( 1, (int) ceil( $total / 80 ) );

		// 均匀跳段，保证全文都被覆盖。
		for ( $i = 0; $i < $total; $i += $step ) {
			$l = self::len( $paras[ $i ] );
			if ( $used + $l > self::MAX_ANALYZE_CHARS ) {
				continue;
			}
			$picked[] = $paras[ $i ];
			$used    += $l;
		}

		// 预算还有剩余，补回跳过的段落。
		if ( $used < self::MAX_ANALYZE_CHARS ) {
			for ( $i = 0; $i < $total; $i++ ) {
				if ( 0 === $i % $step ) {
					continue;
				}
				$l = self::len( $paras[ $i ] );
				if ( $used + $l > self::MAX_ANALYZE_CHARS ) {
					continue;
				}
				$picked[] = $paras[ $i ];
				$used    += $l;
			}
		}

		return implode( "\n", $picked );
	}

	/**
	 * 给 AI 的正文做头尾采样，控制在字符预算内。
	 *
	 * 首尾信息密度高于中段，同预算下覆盖更全。
	 *
	 * @param string $content 已清洗的正文。
	 * @param int    $budget  字符预算。
	 * @return string
	 */
	private static function sample_for_ai( $content, $budget = 4000 ) {
		if ( self::len( $content ) <= $budget ) {
			return $content;
		}

		$paras = preg_split( '/\n+/u', $content );
		$paras = array_values(
			array_filter(
				(array) $paras,
				function ( $p ) {
					return '' !== trim( (string) $p );
				}
			)
		);

		$total = count( $paras );

		if ( $total < 3 ) {
			return self::sub( $content, 0, $budget );
		}

		$half = (int) floor( $budget / 2 );

		$head    = array();
		$used    = 0;
		$head_to = -1;

		for ( $i = 0; $i < $total; $i++ ) {
			$l = self::len( $paras[ $i ] );
			if ( $used + $l > $half ) {
				break;
			}
			$head[]   = $paras[ $i ];
			$used    += $l;
			$head_to  = $i;
		}

		// 从尾取另一半。
		$tail = array();
		$used = 0;

		for ( $i = $total - 1; $i > $head_to; $i-- ) {
			$l = self::len( $paras[ $i ] );
			if ( $used + $l > $budget - $half ) {
				break;
			}
			array_unshift( $tail, $paras[ $i ] );
			$used += $l;
		}

		if ( empty( $head ) && empty( $tail ) ) {
			return self::sub( $content, 0, $budget );
		}

		$out = implode( "\n", $head );

		if ( ! empty( $tail ) ) {
			$out .= "\n（中间内容省略）\n" . implode( "\n", $tail );
		}

		return $out;
	}

	/**
	 * 取站内已有标签与分类名，用于优先复用。
	 *
	 * 结果缓存 10 分钟。
	 *
	 * @return array
	 */
	public static function existing_terms() {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$cached = get_transient( 'seo_auto_tags_terms_cache' );
		if ( is_array( $cached ) ) {
			$cache = $cached;
			return $cache;
		}

		$cache = array();
		$terms = get_terms(
			array(
				'taxonomy'   => array( 'post_tag', 'category' ),
				'hide_empty' => false,
				'number'     => 2000,
			)
		);

		if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( isset( $t->name ) && '' !== trim( (string) $t->name ) ) {
					$cache[] = trim( (string) $t->name );
				}
			}
		}

		set_transient( 'seo_auto_tags_terms_cache', $cache, 10 * MINUTE_IN_SECONDS );

		return $cache;
	}

	/**
	 * 清除术语与统计缓存。
	 */
	public static function flush_terms_cache() {
		delete_transient( 'seo_auto_tags_terms_cache' );
		delete_transient( 'seo_auto_tags_stats' );
	}

	/* ---------------------------------------------------------------------
	 * 本地算法
	 * ------------------------------------------------------------------ */

	/**
	 * 本地关键词提取。
	 *
	 * @param string $title   标题。
	 * @param string $content 正文。
	 * @param int    $count   需要几个。
	 * @param array  $opt     设置。
	 * @param int    $level   过滤级别：0 严格 / 1 允许不贴边界 / 2 再允许泛用词。
	 * @return array
	 */
	private static function via_local( $title, $content, $count, $opt, $level = 0 ) {
		$hay    = $title . ' ' . $content;
		$stop   = self::stopwords();
		$scores = array();

		// 已有标签 / 分类名命中，优先级最高。
		if ( ! empty( $opt['reuse_existing'] ) ) {
			foreach ( self::existing_terms() as $name ) {
				$len = self::len( $name );
				if ( $len < 2 || $len > 12 ) {
					continue;
				}
				if ( false === self::pos( $hay, $name ) ) {
					continue;
				}
				$hits  = self::count_occurrences( $hay, $name );
				$bonus = 50 + $hits * 8;
				if ( false !== self::pos( $title, $name ) ) {
					$bonus += 30;
				}
				$scores[ $name ] = isset( $scores[ $name ] ) ? $scores[ $name ] + $bonus : $bonus;
			}
		}

		// 中文 n-gram 词频统计，同时标记出现在段边界的词。
		$freq     = array();
		$boundary = array();

		if ( preg_match_all( '/[\x{4e00}-\x{9fa5}]+/u', $hay, $m ) ) {
			foreach ( $m[0] as $seg ) {
				$len = self::len( $seg );
				if ( $len < 2 ) {
					continue;
				}
				for ( $n = 2; $n <= 4; $n++ ) {
					if ( $len < $n ) {
						continue;
					}
					for ( $i = 0; $i + $n <= $len; $i++ ) {
						$w = self::sub( $seg, $i, $n );
						if ( isset( $stop[ $w ] ) ) {
							continue;
						}
						$freq[ $w ] = isset( $freq[ $w ] ) ? $freq[ $w ] + 1 : 1;

						if ( 0 === $i || ( $i + $n ) === $len ) {
							$boundary[ $w ] = 1;
						}
					}
				}
			}
		}

		foreach ( $freq as $w => $f ) {
			// 只出现一次的多半是碎片。
			if ( $f < 2 ) {
				continue;
			}
			// 词头或词尾是虚词，判为碎片。
			if ( self::is_fragment( $w, $stop ) ) {
				continue;
			}
			// 泛用词只在最宽松的级别放行。
			if ( $level < 2 && isset( self::generic_words()[ $w ] ) ) {
				continue;
			}

			// 2 字中文词太容易从词组里切出来（如「这款插件」切出「这款」），
			// 要求出现次数更多，否则一律当作碎片。英文缩写不受此限。
			if ( $f < 3 && self::len( $w ) <= 2 && self::cjk_count( $w ) >= 1 ) {
				continue;
			}

			$score = $f * self::length_weight( $w );

			// 从未贴过边界的词判为碎片：严格级别丢弃，放宽级别降权。
			if ( ! isset( $boundary[ $w ] ) ) {
				if ( 0 === $level ) {
					continue;
				}
				$score *= 0.35;
			}
			if ( false !== self::pos( $title, $w ) ) {
				$score *= 4.0;
			}
			$scores[ $w ] = isset( $scores[ $w ] ) ? $scores[ $w ] + $score : $score;
		}

		// 英文与型号词。
		$efreq = array();
		if ( preg_match_all( '/[A-Za-z][A-Za-z0-9\.\+\-]{2,24}/', $hay, $me ) ) {
			foreach ( $me[0] as $w ) {
				if ( isset( $stop[ strtolower( $w ) ] ) ) {
					continue;
				}
				$efreq[ $w ] = isset( $efreq[ $w ] ) ? $efreq[ $w ] + 1 : 1;
			}
		}

		foreach ( $efreq as $w => $f ) {
			$in_title = ( false !== self::pos( $title, $w ) );

			// 英文词门槛高一点，避免把普通单词当成标签。
			if ( ! $in_title && $f < 3 ) {
				continue;
			}

			$score = $f * 2.5;
			if ( $in_title ) {
				$score *= 4.0;
			}
			$scores[ $w ] = isset( $scores[ $w ] ) ? $scores[ $w ] + $score : $score;
		}

		if ( empty( $scores ) ) {
			return array();
		}

		// 去掉被更高分长词包含的短词。
		$keys = array_keys( $scores );
		foreach ( $keys as $a ) {
			if ( ! isset( $scores[ $a ] ) ) {
				continue;
			}
			$la = self::len( $a );
			foreach ( $keys as $b ) {
				if ( $a === $b || ! isset( $scores[ $b ] ) ) {
					continue;
				}
				if ( self::len( $b ) <= $la ) {
					continue;
				}
				if ( false === self::pos( $b, $a ) ) {
					continue;
				}
				if ( $scores[ $a ] <= $scores[ $b ] * 1.3 ) {
					unset( $scores[ $a ] );
					break;
				}
			}
		}

		arsort( $scores );

		return array_keys( $scores );
	}

	/* ---------------------------------------------------------------------
	 * AI 模式
	 * ------------------------------------------------------------------ */

	/**
	 * 校验 AI 接口地址（SSRF 防护）：必须 https，禁止内网与带凭据的 URL。
	 *
	 * 需连接内网自建模型时，用 seo_auto_tags_allow_private_api 过滤器放开。
	 *
	 * @param string $url 待校验的地址。
	 * @return true|WP_Error
	 */
	public static function validate_api_base( $url ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return new WP_Error( 'seo_auto_tags_bad_url', '接口地址是空的。' );
		}

		$parts = wp_parse_url( $url );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error( 'seo_auto_tags_bad_url', '接口地址格式不对，应该形如 https://api.example.com/v1' );
		}

		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return new WP_Error(
				'seo_auto_tags_https_only',
				'接口地址必须用 https —— API Key 会放在请求头里，走 http 等于明文泄露。'
			);
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error( 'seo_auto_tags_bad_url', '接口地址里不要带用户名密码。' );
		}

		if ( apply_filters( 'seo_auto_tags_allow_private_api', false ) ) {
			return true;
		}

		$host = strtolower( trim( (string) $parts['host'], '[]' ) );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1', '0.0.0.0' ), true ) ) {
			return new WP_Error( 'seo_auto_tags_private', '接口地址不能指向本机。' );
		}

		if ( preg_match( '/\.(local|internal|localhost)$/i', $host ) ) {
			return new WP_Error( 'seo_auto_tags_private', '接口地址不能指向内网域名。' );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP ) && self::is_restricted_ip( $host ) ) {
			return new WP_Error( 'seo_auto_tags_private', '接口地址不能指向内网或保留 IP。' );
		}

		return true;
	}

	/**
	 * 判断 IP 是否属于内网、回环、保留或组播范围。
	 *
	 * @param string $ip IP 地址。
	 * @return bool
	 */
	private static function is_restricted_ip( $ip ) {
		$ip = trim( (string) $ip, '[]' );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			foreach ( self::private_ip_patterns() as $pattern ) {
				if ( preg_match( $pattern, $ip ) ) {
					return true;
				}
			}
		}

		return false === filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	/**
	 * 内网 / 保留 IPv4 网段的正则表。
	 *
	 * @return array
	 */
	private static function private_ip_patterns() {
		return array(
			'/^10\./',
			'/^127\./',
			'/^169\.254\./',                                        // 云元数据服务
			'/^0\./',
			'/^192\.168\./',
			'/^172\.(1[6-9]|2[0-9]|3[01])\./',
			'/^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./',      // 运营商级 NAT
			'/^198\.1[89]\./',
			'/^22[4-9]\./',                                          // 组播
			'/^2[3-5][0-9]\./',                                      // 组播 + 保留
		);
	}

	/**
	 * 解析域名后检查真实 IP 是否落在内网。
	 *
	 * 域名字符串校验挡不住解析到内网的情况，因此请求前会做 DNS 查询。
	 *
	 * @param string $url 接口地址。
	 * @return true|WP_Error
	 */
	public static function validate_api_host_ip( $url ) {
		if ( apply_filters( 'seo_auto_tags_allow_private_api', false ) ) {
			return true;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! $host ) {
			return true; // 格式问题交给 validate_api_base 报。
		}

		$host = trim( (string) $host, '[]' );

		// 直接 IP 已由 validate_api_base() 检查，这里只负责域名解析。
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_A' ) && defined( 'DNS_AAAA' ) ) {
			$records = dns_get_record( $host, DNS_A | DNS_AAAA );

			if ( is_array( $records ) && ! empty( $records ) ) {
				foreach ( $records as $record ) {
					$ip = isset( $record['ip'] ) ? $record['ip'] : ( isset( $record['ipv6'] ) ? $record['ipv6'] : '' );
					if ( '' !== $ip && self::is_restricted_ip( $ip ) ) {
						return new WP_Error(
							'seo_auto_tags_private_dns',
							'这个域名解析到了内网地址（' . $ip . '），已拦截。'
						);
					}
				}

				return true;
			}
		}

		$ip = gethostbyname( $host );

		// 解析失败时 gethostbyname 会把域名原样返回。
		if ( $ip === $host || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		if ( self::is_restricted_ip( $ip ) ) {
			return new WP_Error(
				'seo_auto_tags_private_dns',
				'这个域名解析到了内网地址（' . $ip . '），已拦截。'
			);
		}

		return true;
	}

	/**
	 * 调用 OpenAI 兼容接口生成标签。
	 *
	 * 失败时返回带具体原因的 WP_Error，供界面提示。
	 *
	 * @param string $title   标题。
	 * @param string $content 正文。
	 * @param int    $count   需要几个。
	 * @param array  $opt     设置。
	 * @return array|WP_Error
	 */
	private static function via_ai( $title, $content, $count, $opt ) {
		$base = trim( (string) $opt['api_base'] );
		$key  = SEO_Auto_Tags_Settings::api_key();
		$mdl  = trim( (string) $opt['model'] );

		if ( '' === $base || '' === $key || '' === $mdl ) {
			return new WP_Error( 'seo_auto_tags_ai_cfg', '接口地址、API Key、模型名没有填齐。' );
		}

		$check = self::validate_api_base( $base );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$dns = self::validate_api_host_ip( $base );
		if ( is_wp_error( $dns ) ) {
			return $dns;
		}

		$payload = array(
			'model'       => $mdl,
			'temperature' => 0.3,
			'max_tokens'  => 300,
			'stream'      => false,
			'messages'    => array(
				array( 'role' => 'system', 'content' => self::system_prompt() ),
				array( 'role' => 'user', 'content' => self::build_prompt( $title, $content, $count ) ),
			),
		);

		$resp = wp_remote_post(
			rtrim( $base, '/' ) . '/chat/completions',
			array(
				// 超时不宜过长，避免并发慢请求占满 PHP 进程。
				'timeout'     => 25,
				'redirection' => 0,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					// 固定 UA：WordPress 默认 UA 会带上站点 URL，不必外传。
					'User-Agent'    => self::user_agent(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'seo_auto_tags_ai_transport', self::describe_transport_error( $resp ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		if ( 200 !== $code ) {
			return new WP_Error( 'seo_auto_tags_ai_status', self::describe_http_error( $code, $body ) );
		}

		$text = self::extract_content( $body );

		if ( '' === $text ) {
			return new WP_Error(
				'seo_auto_tags_ai_parse',
				'接口通了（HTTP 200），但返回内容读不懂。多半是模型名写错了，或该服务商的返回格式不兼容。'
					. self::snippet( $body )
			);
		}

		$tags = self::split_tags( $text );

		if ( empty( $tags ) ) {
			return new WP_Error(
				'seo_auto_tags_ai_empty',
				'模型回复了内容，但里面挑不出可用的标签。' . self::snippet( $text )
			);
		}

		self::bump_usage();

		return $tags;
	}

	/**
	 * 系统提示词。
	 *
	 * @return string
	 */
	private static function system_prompt() {
		return '你是资深中文网站 SEO 编辑。你的任务是从文章内容中提取最适合作为 WordPress 文章标签（post_tag）的关键词。'
			. '你只输出标签本身，用英文逗号分隔，不输出序号、解释、引号或任何多余文字。';
	}

	/**
	 * 把网络层的失败翻译成人话。
	 *
	 * @param WP_Error $err 传输层错误。
	 * @return string
	 */
	private static function describe_transport_error( $err ) {
		$msg = strtolower( (string) $err->get_error_message() );
		$raw = (string) $err->get_error_message();

		if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
			return '连接接口超时（25 秒）。可能是服务器出不了外网，或者模型响应太慢。';
		}

		if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
			return 'SSL 证书校验失败。' . self::snippet( $raw );
		}

		if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, 'name or service' ) ) {
			return '域名解析失败 —— 检查接口地址有没有写错。';
		}

		if ( false !== strpos( $msg, 'connection refused' ) ) {
			return '连接被拒绝 —— 检查接口地址和端口。';
		}

		return '请求发不出去：' . self::snippet( $raw, 120 );
	}

	/**
	 * 把 HTTP 状态码转换成可操作的提示文案。
	 *
	 * @param int    $code 状态码。
	 * @param string $body 响应体。
	 * @return string
	 */
	private static function describe_http_error( $code, $body ) {
		$hint = self::snippet( $body );
		$code = (int) $code;

		switch ( $code ) {
			case 400:
				return '接口返回 400（请求格式不对）—— 多半是模型名写错了。' . $hint;
			case 401:
				return '接口返回 401 —— API Key 无效或已过期，重新复制粘贴一次。' . $hint;
			case 402:
				return '接口返回 402 —— 账户余额不足，去服务商后台充值。' . $hint;
			case 403:
				return '接口返回 403 —— 没有权限。可能 Key 被限制了，或该模型没开通。' . $hint;
			case 404:
				return '接口返回 404 —— 接口地址不对。多数服务商要求以 /v1 结尾，检查一下。' . $hint;
			case 413:
				return '接口返回 413 —— 发过去的内容太大了。' . $hint;
			case 429:
				return '接口返回 429 —— 被服务商限流了，等一会儿再试。' . $hint;
		}

		if ( $code >= 500 ) {
			return '接口返回 ' . $code . ' —— 服务商那边出问题了，稍后重试。' . $hint;
		}

		return '接口返回 HTTP ' . $code . '。' . $hint;
	}

	/**
	 * 从响应体里取出模型回复的文本。
	 *
	 * 各服务商返回结构不一，按常见格式依次尝试。
	 *
	 * @param string $body 原始响应体。
	 * @return string
	 */
	private static function extract_content( $body ) {
		$body = trim( (string) $body );

		if ( '' === $body ) {
			return '';
		}

		// 部分网关即使未开 stream 也会返回 SSE 分块。
		// 拼接后已是纯文本，直接返回。
		if ( 0 === strpos( $body, 'data:' ) ) {
			return self::join_sse( $body );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return '';
		}

		// 标准 OpenAI 兼容：choices[0].message.content
		if ( isset( $data['choices'][0]['message']['content'] ) && is_string( $data['choices'][0]['message']['content'] ) ) {
			return $data['choices'][0]['message']['content'];
		}

		// 多模态写法：content 是数组
		if ( isset( $data['choices'][0]['message']['content'][0]['text'] ) ) {
			return (string) $data['choices'][0]['message']['content'][0]['text'];
		}

		// 老式 completions 接口：choices[0].text
		if ( isset( $data['choices'][0]['text'] ) && is_string( $data['choices'][0]['text'] ) ) {
			return $data['choices'][0]['text'];
		}

		// 部分网关：output.text
		if ( isset( $data['output']['text'] ) && is_string( $data['output']['text'] ) ) {
			return $data['output']['text'];
		}

		// 极简格式：content
		if ( isset( $data['content'] ) && is_string( $data['content'] ) ) {
			return $data['content'];
		}

		return '';
	}

	/**
	 * 把 SSE 分块拼成完整文本。
	 *
	 * @param string $raw 原始 SSE 文本。
	 * @return string
	 */
	private static function join_sse( $raw ) {
		$out = '';

		foreach ( preg_split( '/\r?\n/', (string) $raw ) as $line ) {
			$line = trim( $line );

			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}

			$payload = trim( substr( $line, 5 ) );

			if ( '' === $payload || '[DONE]' === $payload ) {
				continue;
			}

			$json = json_decode( $payload, true );

			if ( ! is_array( $json ) ) {
				continue;
			}

			if ( isset( $json['choices'][0]['delta']['content'] ) ) {
				$out .= (string) $json['choices'][0]['delta']['content'];
			} elseif ( isset( $json['choices'][0]['message']['content'] ) ) {
				$out .= (string) $json['choices'][0]['message']['content'];
			}
		}

		return $out;
	}

	/**
	 * 清洗并截断一段文本，同时抹掉其中的密钥。
	 *
	 * @param string $text  原始文本。
	 * @param int    $limit 最多保留多少字。
	 * @return string
	 */
	private static function scrub( $text, $limit = 160 ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		// 先精确匹配已配置的 Key。
		$key = SEO_Auto_Tags_Settings::api_key();

		if ( '' !== $key && false !== strpos( $text, $key ) ) {
			$text = str_replace( $key, '***', $text );
		}

		// 再按常见格式兜底。
		$text = preg_replace( '/(Bearer\s+)[A-Za-z0-9\-_\.]{8,}/i', '$1***', $text );
		$text = preg_replace( '/\b(sk|api[_-]?key|token)[\-_:=\s]+[A-Za-z0-9\-_\.]{12,}/i', '$1=***', $text );

		$cut = self::len( $text ) > $limit;

		return self::sub( $text, 0, $limit ) . ( $cut ? '…' : '' );
	}

	/**
	 * 生成用于报错的响应片段。
	 *
	 * @param string $text  原始文本。
	 * @param int    $limit 最多保留多少字。
	 * @return string
	 */
	private static function snippet( $text, $limit = 160 ) {
		$frag = self::scrub( $text, $limit );

		return '' === $frag ? '' : ' 原始返回：' . $frag;
	}

	/**
	 * 请求头里的 User-Agent。
	 *
	 * 不用 WordPress 默认值，避免把站点地址一并发给第三方接口。
	 *
	 * @return string
	 */
	private static function user_agent() {
		return 'SEO-Auto-Tags/' . SEO_AUTO_TAGS_VERSION . '; WordPress';
	}

	/**
	 * 记录一次 AI 调用，按月重置。
	 */
	private static function bump_usage() {
		$month = current_time( 'Y-m' );
		$usage = get_option( 'seo_auto_tags_usage', array() );

		if ( ! is_array( $usage ) || ! isset( $usage['month'] ) || $month !== $usage['month'] ) {
			$usage = array(
				'month' => $month,
				'count' => 0,
			);
		}

		$usage['count'] = (int) $usage['count'] + 1;

		update_option( 'seo_auto_tags_usage', $usage, false );
	}

	/**
	 * 读取调用统计。
	 *
	 * @return array
	 */
	public static function get_usage() {
		$usage = get_option( 'seo_auto_tags_usage', array() );

		if ( ! is_array( $usage ) ) {
			$usage = array();
		}

		$month = current_time( 'Y-m' );

		if ( ! isset( $usage['month'] ) || $month !== $usage['month'] ) {
			return array(
				'month' => $month,
				'count' => 0,
			);
		}

		return array(
			'month' => $month,
			'count' => isset( $usage['count'] ) ? (int) $usage['count'] : 0,
		);
	}

	/**
	 * 构造提示词。
	 *
	 * @param string $title   标题。
	 * @param string $content 正文。
	 * @param int    $count   数量。
	 * @return string
	 */
	private static function build_prompt( $title, $content, $count ) {
		$body = self::sample_for_ai( $content, self::AI_INPUT_CHARS );

		$lines   = array();
		$lines[] = '请从下面这篇文章中提取 ' . $count . ' 个最适合做标签的关键词。';
		$lines[] = '';
		$lines[] = '要求：';
		$lines[] = '1. 长度 3~6 个字最合适，最多不超过 8 个字';
		$lines[] = '2. 长度直接影响标签的 SEO 效果：太短（如「软件」「教程」）没有区分度；'
			. '太长（如「安卓手机录屏软件推荐」）只能聚合一两篇文章，会变成内容稀薄的归档页。'
			. '3~6 个字既能说清主题，又能把同类文章聚到一起';
		$lines[] = '3. 优先选有搜索价值的具体词：软件名、资源类型、主题领域、适用人群';
		$lines[] = '4. 不要选过于宽泛的词（例如「资源」「分享」「教程」「免费」单独出现时）';
		$lines[] = '5. 各标签之间不要语义重复，不要互相包含';
		$lines[] = '6. 不要包含标点、书名号、引号、序号';
		$lines[] = '7. 只输出 ' . $count . ' 个标签，用英文逗号分隔，不要任何解释';
		$lines[] = '';
		$lines[] = '【文章标题】' . $title;
		$lines[] = '';
		$lines[] = '【文章正文】';
		$lines[] = $body;

		return implode( "\n", $lines );
	}

	/**
	 * 把模型输出切成标签数组。
	 *
	 * @param string $text 原始输出。
	 * @return array
	 */
	private static function split_tags( $text ) {
		$text = (string) $text;
		$text = preg_replace( '/```.*?```/s', ' ', $text );
		$text = preg_replace( '/^\s*(标签|关键词|关键词标签|tags?|keywords?)\s*[:：]\s*/iu', '', $text );
		$text = str_replace(
			array( '，', '、', '；', ';', '|', '｜', '/', "\r", "\n", '　', "\t" ),
			',',
			$text
		);

		$out = array();
		foreach ( explode( ',', $text ) as $p ) {
			$p = trim( $p );
			$p = preg_replace( '/^\d+\s*[\.、\)）]\s*/u', '', $p );
			$p = trim( $p, " \t\n\r\0\x0B\"'“”‘’「」『』【】[]()（）<>《》-—_·.。," );
			$p = trim( $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 收尾
	 * ------------------------------------------------------------------ */

	/**
	 * 去重、限长、截断。
	 *
	 * @param array $tags  候选标签。
	 * @param int   $count 数量上限。
	 * @return array
	 */
	private static function finalize( $tags, $count ) {
		$out   = array();
		$seen  = array();
		$block = self::blocklist();

		foreach ( (array) $tags as $t ) {
			$t = trim( (string) $t );
			$t = preg_replace( '/\s+/u', ' ', $t );
			$t = trim( (string) $t );

			$len = self::len( $t );
			$cjk = self::cjk_count( $t );

			// 中文标签控制在 2~8 字；含英文的标签总长不超过 20 字符。
			if ( $len < 2 || $len > 20 || $cjk > 8 ) {
				continue;
			}
			if ( preg_match( '/^[\d\s\p{P}\p{S}]+$/u', $t ) ) {
				continue;
			}
			if ( false !== strpos( $t, '：' ) || false !== strpos( $t, ':' ) ) {
				continue;
			}

			$key = self::lower( $t );

			// 命中屏蔽词，跳过。
			if ( ! empty( $block ) && isset( $block[ $key ] ) ) {
				continue;
			}

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = 1;
			$out[]        = $t;

			if ( count( $out ) >= $count ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * 测试 AI 接口连通性。
	 *
	 * @return array|WP_Error
	 */
	public static function test_connection() {
		$opt  = SEO_Auto_Tags_Settings::get();
		$base = trim( (string) $opt['api_base'] );
		$key  = SEO_Auto_Tags_Settings::api_key();
		$mdl  = trim( (string) $opt['model'] );

		if ( '' === $base || '' === $key || '' === $mdl ) {
			return new WP_Error( 'seo_auto_tags_cfg', '接口地址、API Key、模型名都要填齐。' );
		}

		$check = self::validate_api_base( $base );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		// 测试时附加一次 DNS 层校验；生成流程也会在实际请求前复核。
		$dns = self::validate_api_host_ip( $base );
		if ( is_wp_error( $dns ) ) {
			return $dns;
		}

		$resp = wp_remote_post(
			rtrim( $base, '/' ) . '/chat/completions',
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'User-Agent'    => self::user_agent(),
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $mdl,
						'max_tokens' => 20,
						'stream'     => false,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => '只回复两个字：正常',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'seo_auto_tags_test_transport', self::describe_transport_error( $resp ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );

		if ( 200 !== $code ) {
			return new WP_Error( 'seo_auto_tags_test_status', self::describe_http_error( $code, $body ) );
		}

		$text = self::extract_content( $body );

		if ( '' === $text ) {
			return new WP_Error(
				'seo_auto_tags_test_parse',
				'接口通了（HTTP 200），但返回内容读不懂。多半是模型名写错了，或该服务商的返回格式不兼容。'
					. self::snippet( $body )
			);
		}

		// 模型回复只截一小段用于确认连通，避免把大段内容带回前端。
		return array( 'reply' => self::scrub( $text, 40 ) );
	}

	/* ---------------------------------------------------------------------
	 * 中文停用词
	 * ------------------------------------------------------------------ */

	/**
	 * 停用词表（键为词，值为 1，便于 O(1) 查找）。
	 *
	 * @return array
	 */
	public static function stopwords() {
		static $set = null;

		if ( null !== $set ) {
			return $set;
		}

		$raw = '的 了 和 是 在 有 就 不 人 都 一 上 也 很 到 说 要 去 你 会 着 看 好 这 那 他 她 它 我 谁 把 被 让 给 从 向 对 与 及 或 但 而 则 于 之 其 此 该 等 乃 且 若 即 使 因 由 以 为 所 者 也 呀 吧 呢 吗 啊 哦 嗯 '
			. '一个 一些 一下 一直 一定 一样 一般 一边 一起 一点 一切 上述 下面 上面 不是 不能 不过 不同 不再 不会 不能 不要 不知 不管 不能 与其 专门 并且 严格 为了 为何 为什么 主要 也是 也许 于是 之后 之前 之类 之一 之类 于是 什么 今天 介绍 仍然 他们 你们 我们 他的 她的 它的 我的 你的 使得 依然 便是 值得 允许 关于 其中 其他 其它 其它 其次 几乎 出去 出来 出现 分别 分析 刚才 别的 别说 利用 到底 包括 即使 却是 可以 可能 可是 各种 各个 同时 同样 后来 后面 因为 因此 因而 在于 基于 如此 如果 存在 完全 实现 对于 就是 尽管 已经 常常 并且 开始 当然 得到 必须 怎么 总是 或者 所有 打算 把 等等 而且 而已 能够 自己 自身 虽然 要是 认为 觉得 该 说明 请问 谁 然后 然而 现在 由于 的话 目的 相对 看到 知道 确实 社会 然而 肯定 继续 而且 而 而言 例如 比如 而言 至于 虽然 被 要是 认为 许多 说明 进行 通过 那个 那些 那么 部分 都是 采用 里面 重要 针对 问题 除了 需要 首先 必须 那个 非常 预计 特别 由于 相当 相当 保证 保持 提供 支持 帮助 允许 导致 造成 产生 引起 出现 发生 存在 继续 停止 增加 减少 提高 降低 改变 调整 处理 解决 使用 应用 采用 选择 决定 考虑 注意 了解 明白 清楚 时候 时间 情况 方面 内容 方式 方法 东西 事情 地方 这里 那里 哪里 接着 最后 另外 此外 目前 以前 以后 之前 其中 之类 等等 来说 通常 往往 经常 从来 有些 很多 不少 大量 各种 每个 任何 全部 整个 别的 相同 类似 相关 有关 直接 间接 单独 分别 各自 相互 互相';

		$set = array();
		foreach ( preg_split( '/\s+/u', $raw ) as $w ) {
			$w = trim( $w );
			if ( '' !== $w ) {
				$set[ $w ] = 1;
			}
		}

		$en = 'the and for with that this from you are was were has have had will would can could should not but all any our out use used using how what when which who why your its its https http www com org net php html css js the a an of to in on at by or as is be it we they he she his her their there here more most other some such only own same so than too very just also into over after before between under above about each few other';
		foreach ( preg_split( '/\s+/', $en ) as $w ) {
			$w = strtolower( trim( $w ) );
			if ( '' !== $w ) {
				$set[ $w ] = 1;
			}
		}

		return $set;
	}

	/* ---------------------------------------------------------------------
	 * 碎片与泛用词过滤
	 * ------------------------------------------------------------------ */

	/**
	 * 判断候选词是否为跨词边界的碎片。
	 *
	 * 中文无空格，n-gram 会把「网站如何开启」切出「网站如何」这类假词。
	 * 判据：词头或词尾为虚词。
	 *
	 * @param string $w    候选词。
	 * @param array  $stop 停用词表。
	 * @return bool
	 */
	private static function is_fragment( $w, $stop ) {
		$len = self::len( $w );

		if ( $len < 2 ) {
			return false;
		}

		// 词尾 1 字 / 2 字是虚词 → 碎片（例如「款是」「网站如何」）。
		foreach ( array( 1, 2 ) as $n ) {
			if ( $len > $n ) {
				$tail = self::sub( $w, $len - $n, $n );
				if ( isset( $stop[ $tail ] ) ) {
					return true;
				}
			}
		}

		// 词头 2 字是虚词 → 碎片。
		if ( $len > 2 ) {
			$head = self::sub( $w, 0, 2 );
			if ( isset( $stop[ $head ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * 缺乏区分度的泛用词。
	 *
	 * 任何文章都可能出现，做标签对 SEO 无帮助，默认过滤；
	 * 仅在放宽级别下允许入选。
	 *
	 * @return array
	 */
	public static function generic_words() {
		static $set = null;

		if ( null !== $set ) {
			return $set;
		}

		$raw = '免费 下载 安装 配置 设置 教程 资源 分享 推荐 介绍 方法 内容 功能 使用 操作 版本 '
			. '问题 情况 时候 需要 注意 相关 支持 提供 选择 了解 保持 继续 增加 减少 提高 降低 '
			. '改变 调整 处理 解决 分析 研究 效果 方式 部分 主要 重要 简单 容易 困难 方便 '
			. '普通 一般 通常 基本 常见 特别 非常 已经 正在 可以 可能 应该 必须 网站 网页 页面 '
			. '文章 内容 信息 数据 系统 平台 服务 应用 产品 项目 技术 行业 领域 市场 管理 '
			. '发展 建设 设计 制作 经验 知识 技巧 步骤 过程 结果 原因 特点 优点 缺点 区别 '
			. '以上 以下 如下 大家 我们 你们 他们 自己 什么 怎么 为什么 如何 哪些 哪个 多少 '
			. '开启 关闭 完成 开始 结束 实现 得到 获得 成为 作为 决定 允许 导致 造成 产生 '
			. '引起 出现 发生 存在 停止 希望 尝试 作用 价值 意义 优势 需求 目标 计划 方案 '
			. '建议 说明 举例 示例 案例 场景 条件 前提 基础 核心 关键 重点 要点 总结 回顾 '
			. '工具 软件 文件 格式 类型 种类 名称 标题 描述 数量 大小 时间 位置 状态 参数 '
			. '适合 申请 第一 第二 第三 第四 第五 首先 其次 再次 最后 另外 此外 包含 包括 '
			. '具有 属于 位于 基于 针对 便于 以上 以下 如下 目前 现在 今天 大家 '
			. '中文 汉化 汉化版 绿色版 破解版 完整版 最新版 免费版 官方版 正式版';

		$set = array();
		foreach ( preg_split( '/\s+/u', $raw ) as $w ) {
			$w = trim( $w );
			if ( '' !== $w ) {
				$set[ $w ] = 1;
			}
		}

		return $set;
	}

	/**
	 * 自定义标签屏蔽词，设置页一行一个。
	 *
	 * @return array 小写词 => 1
	 */
	public static function blocklist() {
		static $set = null;

		if ( null !== $set ) {
			return $set;
		}

		$set = array();
		$raw = (string) SEO_Auto_Tags_Settings::get( 'blocklist' );

		if ( '' === trim( $raw ) ) {
			return $set;
		}

		foreach ( preg_split( '/[\r\n,，、]+/u', $raw ) as $w ) {
			$w = trim( (string) $w );
			if ( '' !== $w ) {
				$set[ self::lower( $w ) ] = 1;
			}
		}

		return $set;
	}

	/* ---------------------------------------------------------------------
	 * 多字节安全的小工具（mbstring 缺失时降级）
	 * ------------------------------------------------------------------ */

	/**
	 * 字符串长度。
	 *
	 * @param string $s 字符串。
	 * @return int
	 */
	private static function len( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strlen' ) ? (int) mb_strlen( $s, 'UTF-8' ) : (int) strlen( $s );
	}

	/**
	 * 汉字个数。
	 *
	 * @param string $s 字符串。
	 * @return int
	 */
	private static function cjk_count( $s ) {
		$n = preg_match_all( '/[\x{4e00}-\x{9fa5}]/u', (string) $s );

		return false === $n ? 0 : (int) $n;
	}

	/**
	 * 按长度算标签的权重。
	 *
	 * 汉字记 2 个宽度、英文数字记 1 个，折半后得到「等效汉字数」。
	 * 3~6 字最理想：够具体，又能聚合多篇文章。
	 * 2 字太泛没有区分度；超过 8 字往往只服务一两篇文章，容易变成薄归档页。
	 *
	 * @param string $word 候选词。
	 * @return float
	 */
	private static function length_weight( $word ) {
		$len = self::len( $word );
		$cjk = self::cjk_count( $word );
		$eq  = ( $cjk * 2 + ( $len - $cjk ) ) / 2;

		if ( $eq < 3 ) {
			return 0.55;
		}
		if ( $eq < 4 ) {
			return 0.9;
		}
		if ( $eq <= 6 ) {
			return 1.2;
		}
		if ( $eq <= 8 ) {
			return 0.95;
		}

		return 0.7;
	}

	/**
	 * 截取子串。
	 *
	 * @param string   $s     字符串。
	 * @param int      $start 起点。
	 * @param int|null $len   长度。
	 * @return string
	 */
	private static function sub( $s, $start, $len = null ) {
		$s = (string) $s;
		if ( function_exists( 'mb_substr' ) ) {
			return null === $len ? mb_substr( $s, $start, null, 'UTF-8' ) : mb_substr( $s, $start, $len, 'UTF-8' );
		}
		return null === $len ? substr( $s, $start ) : substr( $s, $start, $len );
	}

	/**
	 * 查找子串位置。
	 *
	 * @param string $hay 主串。
	 * @param string $nee 子串。
	 * @return int|false
	 */
	private static function pos( $hay, $nee ) {
		$hay = (string) $hay;
		$nee = (string) $nee;
		if ( '' === $nee ) {
			return false;
		}
		if ( function_exists( 'mb_strpos' ) ) {
			return mb_strpos( $hay, $nee, 0, 'UTF-8' );
		}
		return strpos( $hay, $nee );
	}

	/**
	 * 小写。
	 *
	 * @param string $s 字符串。
	 * @return string
	 */
	private static function lower( $s ) {
		$s = (string) $s;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	}

	/**
	 * 统计子串出现次数。
	 *
	 * @param string $hay 主串。
	 * @param string $nee 子串。
	 * @return int
	 */
	private static function count_occurrences( $hay, $nee ) {
		$hay = (string) $hay;
		$nee = (string) $nee;

		if ( '' === $nee ) {
			return 0;
		}
		if ( function_exists( 'mb_substr_count' ) ) {
			return (int) mb_substr_count( $hay, $nee );
		}

		$n = preg_match_all( '/' . preg_quote( $nee, '/' ) . '/u', $hay );
		return false === $n ? 0 : (int) $n;
	}
}

