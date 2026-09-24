<?php
/**
 * 设置页
 *
 * @package SEO_Auto_Tags
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 设置管理。
 */
class SEO_Auto_Tags_Settings {

	const PAGE = 'seo-auto-tags';

	/**
	 * 注册钩子。
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SEO_AUTO_TAGS_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * 默认值。
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => 1,
			'mode'           => 'local',
			'count'          => 5,
			'provider'       => 'deepseek',
			'api_base'       => 'https://api.deepseek.com/v1',
			'api_key'        => '',
			'model'          => 'deepseek-chat',
			'reuse_existing' => 1,
			'auto_suggest'   => 0,
			'rate_limit'     => 30,
			'cache_ttl'      => 24,
			'blocklist'      => '',
		);
	}

	/**
	 * 读取设置。
	 *
	 * @param string|null $key 键名。
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$opt = get_option( SEO_AUTO_TAGS_OPTION, array() );
		$opt = wp_parse_args( is_array( $opt ) ? $opt : array(), self::defaults() );

		if ( null === $key ) {
			return $opt;
		}

		return isset( $opt[ $key ] ) ? $opt[ $key ] : null;
	}

	/**
	 * 读取 API Key。
	 *
	 * 若在 wp-config.php 中定义了 SEO_AUTO_TAGS_API_KEY 常量，则优先使用它，
	 * 密钥可以不写入数据库。
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'SEO_AUTO_TAGS_API_KEY' ) ) {
			$const = trim( (string) SEO_AUTO_TAGS_API_KEY );

			if ( '' !== $const ) {
				return $const;
			}
		}

		return self::decrypt_key( (string) self::get( 'api_key' ) );
	}

	/**
	 * API Key 是否来自 wp-config.php 常量。
	 *
	 * @return bool
	 */
	public static function api_key_from_constant() {
		return defined( 'SEO_AUTO_TAGS_API_KEY' ) && '' !== trim( (string) SEO_AUTO_TAGS_API_KEY );
	}

	/**
	 * 已保存的密钥存在却解不开（站点密钥变更后会出现这种情况）。
	 *
	 * @return bool
	 */
	public static function api_key_broken() {
		$stored = (string) self::get( 'api_key' );

		if ( '' === $stored ) {
			return false;
		}

		$prefix = substr( $stored, 0, 2 );

		if ( 's:' !== $prefix && 'o:' !== $prefix ) {
			return false;
		}

		return '' === self::decrypt_key( $stored );
	}

	/**
	 * 从 wp-config.php 的密钥派生加密密钥。
	 *
	 * @return string 32 字节原始密钥；无法派生时返回空串。
	 */
	private static function derive_key() {
		$raw = '';

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_SALT', 'LOGGED_IN_KEY' ) as $const ) {
			if ( defined( $const ) ) {
				$raw .= (string) constant( $const );
			}
		}

		if ( '' === $raw ) {
			return '';
		}

		return (string) hash( 'sha256', $raw . 'seo-auto-tags', true );
	}

	/**
	 * 加密 API Key，落库时不留明文。
	 *
	 * @param string $plain 明文。
	 * @return string
	 */
	private static function encrypt_key( $plain ) {
		$plain = (string) $plain;

		if ( '' === $plain ) {
			return '';
		}

		$key = self::derive_key();

		if ( '' === $key ) {
			return '';
		}

		$sodium = function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) && function_exists( 'random_bytes' );

		if ( $sodium ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );

			return 's:' . base64_encode( $nonce . $cipher );
		}

		if ( function_exists( 'openssl_encrypt' ) && function_exists( 'hash_hmac' ) && function_exists( 'random_bytes' ) ) {
			$iv     = random_bytes( 16 );
			$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			if ( false !== $cipher ) {
				$mac = hash_hmac( 'sha256', $iv . $cipher, $key, true );
				return 'o:' . base64_encode( '2' . $iv . $mac . $cipher );
			}
		}

		return '';
	}

	/**
	 * 解密 API Key。
	 *
	 * @param string $stored 数据库中的存储值。
	 * @return string
	 */
	private static function decrypt_key( $stored ) {
		$stored = (string) $stored;

		if ( '' === $stored ) {
			return '';
		}

		// 没有加密前缀，按旧版本的明文处理。
		$prefix = substr( $stored, 0, 2 );

		if ( 's:' !== $prefix && 'o:' !== $prefix ) {
			return $stored;
		}

		$key = self::derive_key();

		if ( '' === $key ) {
			return '';
		}

		$data = base64_decode( substr( $stored, 2 ), true );

		if ( false === $data ) {
			return '';
		}

		if ( 's:' === $prefix && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = substr( $data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			if ( strlen( $cipher ) < 1 ) {
				return '';
			}

			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );

			return false === $plain ? '' : (string) $plain;
		}

		if ( 'o:' === $prefix && function_exists( 'openssl_decrypt' ) ) {
			$authenticated = strlen( $data ) >= 50 && '2' === $data[0];

			if ( $authenticated ) {
				if ( ! function_exists( 'hash_hmac' ) ) {
					return '';
				}

				$iv     = substr( $data, 1, 16 );
				$mac    = substr( $data, 17, 32 );
				$cipher = substr( $data, 49 );
				$check  = hash_hmac( 'sha256', $iv . $cipher, $key, true );

				if ( ! function_exists( 'hash_equals' ) || ! hash_equals( $mac, $check ) ) {
					return '';
				}
			} else {
				// 兼容早期 OpenSSL 密文格式。
				$iv     = substr( $data, 0, 16 );
				$cipher = substr( $data, 16 );
			}

			if ( strlen( $iv ) !== 16 || strlen( $cipher ) < 1 ) {
				return '';
			}

			$plain = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

			return false === $plain ? '' : (string) $plain;
		}

		return '';
	}

	/**
	 * 检测服务器环境，返回问题列表与整体级别。
	 *
	 * @return array level => ok/warn/error, issues => 提示文案数组
	 */
	public static function environment_check() {
		$issues = array();
		$level  = 'ok';

		// PHP 版本。
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$issues[] = 'PHP 版本过低（' . PHP_VERSION . '），需要 7.4 或更高。';
			$level    = 'error';
		}

		// 核心扩展。
		if ( ! function_exists( 'json_decode' ) ) {
			$issues[] = 'PHP 未启用 json 扩展，插件无法正常工作。';
			$level    = 'error';
		}

		if ( ! function_exists( 'mb_strlen' ) ) {
			$issues[] = '未启用 mbstring 扩展，中文标签提取会明显不准。建议到服务器面板启用 mbstring。';
			$level    = 'warn';
		}

		// 加密扩展（影响 API Key 存储安全）。
		$has_sodium = function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' ) && function_exists( 'random_bytes' );
		$has_openssl = function_exists( 'openssl_encrypt' ) && function_exists( 'openssl_decrypt' ) && function_exists( 'hash_hmac' ) && function_exists( 'random_bytes' );

		if ( ! $has_sodium && ! $has_openssl ) {
			$issues[] = '服务器没有可用的安全加密能力，新的 API Key 将不会保存。请启用 sodium 或 openssl，并确认站点密钥已配置。';
			$level    = 'warn';
		}

		// 外网连通性（AI 模式需要）。
		$can_http = function_exists( 'curl_init' ) || ini_get( 'allow_url_fopen' );
		if ( ! $can_http ) {
			$issues[] = 'PHP 既没启用 cURL，allow_url_fopen 也为 Off，AI 模式将无法调用外部接口。';
			$level    = 'warn';
		}

		// WordPress 版本。
		global $wp_version;
		$wp_ver = isset( $wp_version ) ? (string) $wp_version : '0.0';
		if ( version_compare( $wp_ver, '5.8', '<' ) ) {
			$issues[] = 'WordPress 版本（' . $wp_ver . '）低于 5.8，部分功能可能不兼容。';
			$level    = 'warn';
		}

		// 内存限制。
		$mem       = (string) ini_get( 'memory_limit' );
		$mem_bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $mem ) : 0;
		if ( $mem_bytes > 0 && $mem_bytes < 64 * 1024 * 1024 ) {
			$issues[] = 'PHP 内存限制（' . $mem . '）偏低，分析长文时可能内存不足。建议设为 128M 或更高。';
			$level    = 'warn';
		}

		// 执行时间。
		$max_time = (int) ini_get( 'max_execution_time' );
		if ( $max_time > 0 && $max_time < 30 ) {
			$issues[] = 'PHP 最大执行时间（' . $max_time . ' 秒）偏短，AI 接口响应慢时可能超时。建议设为 30 秒或更高。';
			$level    = 'warn';
		}

		// wp-config 常量缺失（影响加密）。
		if ( ! defined( 'AUTH_KEY' ) || ! defined( 'SECURE_AUTH_SALT' ) ) {
			$issues[] = 'wp-config.php 里缺少 AUTH_KEY 或 SECURE_AUTH_SALT，API Key 无法加密存储。建议用 WordPress 官方密钥生成服务补全。';
			$level    = 'warn';
		}

		return array(
			'level'  => $level,
			'issues' => $issues,
		);
	}

	/**
	 * 预置的 AI 服务商。
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'deepseek' => array(
				'label' => 'DeepSeek（中文好、价格极低，推荐）',
				'base'  => 'https://api.deepseek.com/v1',
				'model' => 'deepseek-chat',
			),
			'qwen'     => array(
				'label' => '通义千问（阿里云，有免费额度）',
				'base'  => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
				'model' => 'qwen-plus',
			),
			'zhipu'    => array(
				'label' => '智谱 GLM（有免费模型）',
				'base'  => 'https://open.bigmodel.cn/api/paas/v4',
				'model' => 'glm-4-flash',
			),
			'moonshot' => array(
				'label' => '月之暗面 Kimi',
				'base'  => 'https://api.moonshot.cn/v1',
				'model' => 'moonshot-v1-8k',
			),
			'openai'   => array(
				'label' => 'OpenAI',
				'base'  => 'https://api.openai.com/v1',
				'model' => 'gpt-4o-mini',
			),
			'custom'   => array(
				'label' => '自定义（任何 OpenAI 兼容接口）',
				'base'  => '',
				'model' => '',
			),
		);
	}

	/**
	 * 添加菜单。
	 */
	public static function menu() {
		add_options_page(
			'SEO 自动标签',
			'SEO 自动标签',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * 注册设置。
	 */
	public static function register() {
		register_setting(
			'seo_auto_tags_group',
			SEO_AUTO_TAGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * 清洗输入。
	 *
	 * @param array $in 原始输入。
	 * @return array
	 */
	public static function sanitize( $in ) {
		$d   = self::defaults();
		$in  = is_array( $in ) ? $in : array();
		$out = array();

		$out['enabled']        = empty( $in['enabled'] ) ? 0 : 1;
		$out['auto_suggest']   = empty( $in['auto_suggest'] ) ? 0 : 1;
		$out['reuse_existing'] = empty( $in['reuse_existing'] ) ? 0 : 1;

		$mode          = isset( $in['mode'] ) ? sanitize_key( $in['mode'] ) : 'local';
		$out['mode']   = in_array( $mode, array( 'local', 'ai' ), true ) ? $mode : 'local';

		$count         = isset( $in['count'] ) ? absint( $in['count'] ) : $d['count'];
		$out['count']  = max( 1, min( 12, $count ) );

		$rate                = isset( $in['rate_limit'] ) ? absint( $in['rate_limit'] ) : $d['rate_limit'];
		$out['rate_limit']   = min( 1000, $rate );

		$ttl                 = isset( $in['cache_ttl'] ) ? absint( $in['cache_ttl'] ) : $d['cache_ttl'];
		$out['cache_ttl']    = min( 720, $ttl );

		$providers        = self::providers();
		$provider         = isset( $in['provider'] ) ? sanitize_key( $in['provider'] ) : 'deepseek';
		$out['provider']  = isset( $providers[ $provider ] ) ? $provider : 'deepseek';

		$out['api_base'] = isset( $in['api_base'] ) ? esc_url_raw( trim( (string) $in['api_base'] ) ) : '';
		$out['model']    = isset( $in['model'] ) ? sanitize_text_field( trim( (string) $in['model'] ) ) : '';

		// 屏蔽词按行清洗。
		$raw_block = isset( $in['blocklist'] ) ? (string) $in['blocklist'] : '';
		$raw_block = wp_strip_all_tags( $raw_block );
		$lines     = array();

		foreach ( preg_split( '/[\r\n]+/', $raw_block ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' !== $line ) {
				$lines[] = $line;
			}
		}

		$out['blocklist'] = implode( "\n", array_slice( array_unique( $lines ), 0, 200 ) );

		// 保存时一并校验接口地址格式。
		if ( 'ai' === $out['mode'] && '' !== $out['api_base'] ) {
			$chk = SEO_Auto_Tags_Generator::validate_api_base( $out['api_base'] );

			if ( is_wp_error( $chk ) ) {
				add_settings_error(
					SEO_AUTO_TAGS_OPTION,
					'seo_auto_tags_api_base',
					'接口地址有问题：' . $chk->get_error_message(),
					'error'
				);
			}
		}

		// API Key：留空保留原值；勾选「清除」则置空。
		$key   = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		$clear = ! empty( $in['clear_key'] );

		if ( $clear ) {
			$out['api_key'] = '';
		} elseif ( '' !== $key ) {
			$encrypted = self::encrypt_key( sanitize_text_field( $key ) );

			if ( '' === $encrypted ) {
				add_settings_error(
					SEO_AUTO_TAGS_OPTION,
					'seo_auto_tags_key_encryption',
					'API Key 未保存：服务器缺少站点密钥或加密扩展。请先补全 wp-config.php 的 AUTH_KEY、SECURE_AUTH_SALT，并启用 sodium 或 openssl。',
					'error'
				);
				$out['api_key'] = (string) self::get( 'api_key' );
			} else {
				$out['api_key'] = $encrypted;
			}
		} else {
			$out['api_key'] = (string) self::get( 'api_key' );
		}

		return $out;
	}

	/**
	 * 插件列表的操作链接。
	 *
	 * @param array $links 链接数组。
	 * @return array
	 */
	public static function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">设置</a>' );
		return $links;
	}

	/**
	 * 加载设置页资源。
	 *
	 * @param string $hook 当前页面。
	 */
	public static function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}

		wp_enqueue_style( 'seo-auto-tags-admin', SEO_AUTO_TAGS_URL . 'assets/admin.css', array(), SEO_AUTO_TAGS_VERSION );
		wp_enqueue_script( 'seo-auto-tags-admin', SEO_AUTO_TAGS_URL . 'assets/admin.js', array(), SEO_AUTO_TAGS_VERSION, true );

		$providers = array();
		foreach ( self::providers() as $key => $p ) {
			$providers[ $key ] = array(
				'base'  => $p['base'],
				'model' => $p['model'],
			);
		}

		wp_localize_script(
			'seo-auto-tags-admin',
			'SEO_AUTO_TAGS_ADMIN',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'seo_auto_tags_nonce' ),
				'providers' => $providers,
			)
		);
	}

	/**
	 * 渲染设置页。
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '权限不足。' );
		}

		$o             = self::get();
		$providers     = self::providers();
		$from_constant = self::api_key_from_constant();
		$has_key       = '' !== self::api_key();
		$env           = self::environment_check();
		?>
		<div class="wrap seo-auto-tags-wrap">
			<div class="seo-auto-tags-heading">
				<div>
					<p class="seo-auto-tags-eyebrow">CONTENT WORKFLOW / SETTINGS</p>
					<h1>SEO 自动标签</h1>
					<p class="seo-auto-tags-lead">
						把复杂设置收起来，先选生成方式；日常只需在文章编辑页点一下，再勾选想用的标签。
					</p>
				</div>
				<div class="seo-auto-tags-heading-badge">
					<span class="seo-auto-tags-heading-dot"></span>
					<?php echo $o['enabled'] ? '插件已启用' : '插件已停用'; ?>
				</div>
			</div>

			<div class="seo-auto-tags-overview" aria-label="当前配置概览">
				<div class="seo-auto-tags-overview-item">
					<span class="k">生成方式</span>
					<strong class="v"><?php echo 'ai' === $o['mode'] ? 'AI 智能' : '本地算法'; ?></strong>
					<span class="hint"><?php echo 'ai' === $o['mode'] ? '按 SEO 价值理解文章' : '不联网、不产生接口费用'; ?></span>
				</div>
				<div class="seo-auto-tags-overview-item">
					<span class="k">候选标签</span>
					<strong class="v"><?php echo (int) $o['count']; ?> <small>个</small></strong>
					<span class="hint">建议保持 3~6 个</span>
				</div>
				<div class="seo-auto-tags-overview-item">
					<span class="k">API Key</span>
					<strong class="v"><?php echo $from_constant || $has_key ? '已配置' : '未配置'; ?></strong>
					<span class="hint"><?php echo $from_constant ? '来自 wp-config.php' : ( $has_key ? '已加密保存' : 'AI 模式需配置' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $env['issues'] ) ) : ?>
				<details class="seo-auto-tags-env seo-auto-tags-env-<?php echo esc_attr( $env['level'] ); ?>">
					<summary class="seo-auto-tags-env-title">
						<?php if ( 'error' === $env['level'] ) : ?>
							<span class="dashicons dashicons-warning"></span> 当前环境有 <?php echo count( $env['issues'] ); ?> 项提示，点击查看
						<?php else : ?>
							<span class="dashicons dashicons-info-outline"></span> 环境有 <?php echo count( $env['issues'] ); ?> 项提示，点击查看
						<?php endif; ?>
					</summary>
					<ul>
						<?php foreach ( $env['issues'] as $issue ) : ?>
							<li><?php echo esc_html( $issue ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php else : ?>
				<div class="seo-auto-tags-env seo-auto-tags-env-ok">
					<span class="dashicons dashicons-yes-alt"></span> 服务器环境正常，可以直接使用本地算法；开启 AI 前再配置接口即可。
				</div>
			<?php endif; ?>

			<form method="post" action="options.php" data-seo-auto-tags-form>
				<?php settings_fields( 'seo_auto_tags_group' ); ?>

				<div class="seo-auto-tags-settings-grid">
					<div class="seo-auto-tags-settings-main">
				<div class="seo-auto-tags-section">
					<h2 class="title">基础设置</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">启用</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[enabled]" value="1" <?php checked( 1, (int) $o['enabled'] ); ?> />
									在文章编辑页显示「SEO 自动标签」面板
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">候选标签数量</th>
							<td>
								<input type="number" min="1" max="12" class="small-text"
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[count]"
									value="<?php echo esc_attr( (int) $o['count'] ); ?>" />
								<p class="description">默认 5 个。建议保持 3~6 个，标签太多会稀释 SEO 权重。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">复用已有标签</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[reuse_existing]" value="1" <?php checked( 1, (int) $o['reuse_existing'] ); ?> />
									正文里出现站内已有标签或分类名时，优先推荐它们
								</label>
								<p class="description">强烈建议勾上。复用标签能避免「同一个意思建出十几个标签」，对 SEO 更友好。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">打开编辑器时自动生成</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[auto_suggest]" value="1" <?php checked( 1, (int) $o['auto_suggest'] ); ?> />
									进入编辑页时自动跑一次（不用手点）
								</label>
								<p class="description">
									关掉更省资源，需要时手动点「生成标签」即可。<br>
									<b>AI 模式下每次自动生成都会真实调用接口</b> ——
									同一篇文章内容不变时会命中缓存、不重复计费，但你改一次内容就会多一次调用。
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">标签屏蔽词</th>
							<td>
								<textarea name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[blocklist]"
									rows="4" class="large-text code"
									placeholder="一行一个，例如：&#10;免费&#10;网盘&#10;破解"><?php echo esc_textarea( (string) $o['blocklist'] ); ?></textarea>
								<p class="description">
									这里写的词<b>永远不会被推荐</b>。适合屏蔽「站里不做这个方向」的词，
									或者你反复看到、但从来不想用的词。一行一个，也可以逗号分隔。
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="seo-auto-tags-section">
					<h2 class="title">标签从哪来</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">生成方式</th>
							<td>
								<label class="seo-auto-tags-radio">
									<input type="radio" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[mode]" value="local"
										data-seo-auto-tags-mode="local" <?php checked( 'local', $o['mode'] ); ?> />
									<strong>本地算法</strong> —— 不联网、不花钱、装上就能用
								</label>
								<p class="description">
									靠中文词组频次 + 标题加权 + 已有标签匹配来挑词。够用，但选词的「判断力」不如 AI。
								</p>
								<label class="seo-auto-tags-radio">
									<input type="radio" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[mode]" value="ai"
										data-seo-auto-tags-mode="ai" <?php checked( 'ai', $o['mode'] ); ?> />
									<strong>AI 智能</strong> —— 需要填一个 API Key，效果好很多
								</label>
								<p class="description">
									让大模型读完整篇文章，按 SEO 价值挑词。一次调用成本约几厘钱。
								</p>
							</td>
						</tr>
					</table>
				</div>

				<div class="seo-auto-tags-section seo-auto-tags-ai-block" data-seo-auto-tags-ai-block>
					<h2 class="title">AI 接口配置</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">服务商</th>
							<td>
								<select name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[provider]" data-seo-auto-tags-provider>
									<?php foreach ( $providers as $key => $p ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $o['provider'] ); ?>>
											<?php echo esc_html( $p['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">选好后会自动填上接口地址和模型名，你只要填 Key。</p>
							</td>
						</tr>
						<tr>
							<th scope="row">接口地址</th>
							<td>
								<input type="text" class="regular-text" data-seo-auto-tags-base
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[api_base]"
									value="<?php echo esc_attr( $o['api_base'] ); ?>"
									placeholder="https://api.deepseek.com/v1" />
							</td>
						</tr>
						<tr>
							<th scope="row">API Key</th>
							<td>
								<?php if ( $from_constant ) : ?>
									<p class="seo-auto-tags-ok">
										已由 <code>wp-config.php</code> 里的 <code>SEO_AUTO_TAGS_API_KEY</code> 常量提供，
										未写入数据库。需要更换时请直接修改该常量。
									</p>
								<?php else : ?>
									<?php if ( self::api_key_broken() ) : ?>
										<p class="seo-auto-tags-broken">
											已保存的密钥无法解密（站点密钥可能已变更），请重新填写。
										</p>
									<?php endif; ?>
									<div class="seo-auto-tags-key-row">
										<input type="password" class="regular-text" id="seo-auto-tags-key"
											name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[api_key]"
											value="" autocomplete="new-password"
											placeholder="<?php echo $has_key ? '已保存（留空则不改动）' : '粘贴你的 API Key'; ?>" />
										<button type="button" class="button button-secondary" data-seo-auto-tags-toggle-key>显示</button>
									</div>

									<p class="description">
										<?php if ( $has_key ) : ?>
											<span class="seo-auto-tags-ok">已保存。</span>留空表示不修改。
										<?php else : ?>
											还没填。填了才能用 AI 模式。
										<?php endif; ?>
									</p>

									<?php if ( $has_key ) : ?>
										<p class="description">
											<label>
												<input type="checkbox" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[clear_key]" value="1" />
												清除已保存的 Key
											</label>
										</p>
									<?php endif; ?>

									<p class="description">
										密钥用站点密钥加密后保存，数据库里看不到明文。
										想更安全的话，可以在 <code>wp-config.php</code> 加一行
										<code>define( 'SEO_AUTO_TAGS_API_KEY', '你的Key' );</code>，
										插件会优先使用它，密钥就完全不进数据库了。
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">模型名</th>
							<td>
								<input type="text" class="regular-text" data-seo-auto-tags-model
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[model]"
									value="<?php echo esc_attr( $o['model'] ); ?>"
									placeholder="deepseek-chat" />
							</td>
						</tr>
					</table>

					<details class="seo-auto-tags-advanced" open>
						<summary>高级设置与用量说明 <span>调用限速、缓存、测试连接</span></summary>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row">调用限速</th>
								<td>
									<input type="number" min="0" max="1000" class="small-text"
										name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[rate_limit]"
										value="<?php echo esc_attr( (int) $o['rate_limit'] ); ?>" />
									次 / 小时 / 每人
									<p class="description">填 <code>0</code> 表示不限速。建议保留默认值，避免 API 额度被误用。</p>
								</td>
							</tr>
							<tr>
								<th scope="row">结果缓存</th>
								<td>
									<input type="number" min="0" max="720" class="small-text"
										name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[cache_ttl]"
										value="<?php echo esc_attr( (int) $o['cache_ttl'] ); ?>" />
									小时
									<p class="description">同一篇文章不变时直接读缓存，不重复调用接口。填 <code>0</code> 关闭缓存。</p>
								</td>
							</tr>
							<tr>
								<th scope="row">连通性测试</th>
								<td>
									<button type="button" class="button" data-seo-auto-tags-test>测试连接</button>
										<button type="button" class="button" data-seo-auto-tags-clear-cache>清除 AI 缓存</button>
									<span class="seo-auto-tags-test-result" data-seo-auto-tags-test-result></span>
										<p class="description">测试使用已保存的配置，每分钟最多 5 次。清除缓存不会删除文章标签。</p>
								</td>
							</tr>
						</table>

						<details class="seo-auto-tags-consume-details">
							<summary>AI 调用会消耗在哪里？</summary>
							<div class="seo-auto-tags-consume">
								<?php $usage = SEO_Auto_Tags_Generator::get_usage(); ?>
								<p class="seo-auto-tags-usage">
									<strong>本月 AI 调用：<?php echo (int) $usage['count']; ?> 次</strong>
									<span class="description">统计月份 <?php echo esc_html( $usage['month'] ); ?>，每月自动归零</span>
								</p>
								<ul>
									<li><b>手动生成、自动生成、列表页一键生成</b>：每次可能调用一次；同内容会命中缓存。</li>
									<li><b>测试连接</b>：消耗少量 token，已限制一分钟最多 5 次。</li>
									<li><b>超出限速或接口失败</b>：自动改用本地算法，不会中断写作。</li>
								</ul>
							</div>
						</details>
					</details>
					</div>
				</div>

				<aside class="seo-auto-tags-settings-side">
						<div class="seo-auto-tags-side-card seo-auto-tags-side-guide">
							<p class="seo-auto-tags-side-kicker">QUICK START</p>
							<h2>日常怎么用</h2>
							<p>不用每次来设置页。写文章时，在右侧面板生成候选标签，勾选后再应用。</p>
							<div class="seo-auto-tags-side-flow">
								<span><b>01</b> 写正文</span>
								<span><b>02</b> 生成候选</span>
								<span><b>03</b> 勾选应用</span>
							</div>
						</div>
						<details class="seo-auto-tags-health-toggle" open>
							<summary>查看标签健康度 <span>避免标签过多、过散</span></summary>
							<?php self::render_health(); ?>
						</details>
					</aside>
				</div>

<div class="seo-auto-tags-footer">
				<p><strong>怎么用：</strong>打开任意文章的编辑页 → 右侧「SEO 自动标签」面板 → 点「生成标签」→ 勾选想要的 → 点「应用选中标签」。</p>
				<p class="description">标签只是「候选」，不点应用就不会写进文章。</p>
			</div>

			<?php submit_button( '保存设置' ); ?>
			<?php self::render_credit(); ?>
		</form>
	</div>
		<?php
	}

	/**
	 * 标签健康度统计。
	 *
	 * @return array
	 */
	public static function get_stats() {
		// 全量查询较重，缓存 5 分钟。
		$cached = get_transient( 'seo_auto_tags_stats' );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$tags = get_terms(
			array(
				'taxonomy'   => 'post_tag',
				'hide_empty' => false,
				'number'     => 0,
			)
		);

		$total  = 0;
		$unused = 0;
		$once   = 0;

		if ( ! is_wp_error( $tags ) && is_array( $tags ) ) {
			$total = count( $tags );
			foreach ( $tags as $t ) {
				$c = isset( $t->count ) ? (int) $t->count : 0;
				if ( 0 === $c ) {
					$unused++;
				} elseif ( 1 === $c ) {
					$once++;
				}
			}
		}

		$counts    = wp_count_posts( 'post' );
		$published = isset( $counts->publish ) ? (int) $counts->publish : 0;

		$no_tag = 0;
		if ( $published > 0 ) {
			$q = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => false,
					'tax_query'      => array(
						array(
							'taxonomy' => 'post_tag',
							'operator' => 'NOT EXISTS',
						),
					),
				)
			);
			$no_tag = (int) $q->found_posts;
			wp_reset_postdata();
		}

		$stats = array(
			'total'  => $total,
			'unused' => $unused,
			'once'   => $once,
			'posts'  => $published,
			'no_tag' => $no_tag,
		);

		set_transient( 'seo_auto_tags_stats', $stats, 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * 署名信息。
	 *
	 * @return array
	 */
	public static function credit() {
		return array(
			'author' => self::unscramble( 'm8DQyvLpk+yKndDb' ),
			'url'    => self::unscramble( 'GxEbXRJPW0BaAxZJAQEWB0wTEFoMQhlO' ),
		);
	}

	/**
	 * 还原混淆过的字符串。
	 *
	 * @param string $s 混淆后的值。
	 * @return string
	 */
	private static function unscramble( $s ) {
		$raw = base64_decode( (string) $s, true );

		if ( false === $raw || '' === $raw ) {
			return '';
		}

		$key = 'seo-auto-tags';
		$out = '';

		for ( $i = 0; $i < strlen( $raw ); $i++ ) {
			$out .= $raw[ $i ] ^ $key[ $i % strlen( $key ) ];
		}

		return $out;
	}

	/**
	 * 渲染署名，放在设置页最下方。
	 */
	private static function render_credit() {
		$c = self::credit();
		?>
		<div class="seo-auto-tags-credit">
			<div class="seo-auto-tags-credit-mark" aria-hidden="true">S</div>
			<div class="seo-auto-tags-credit-copy">
				<p class="seo-auto-tags-credit-kicker">MADE WITH CARE</p>
				<p class="seo-auto-tags-credit-line">
					<strong><?php echo esc_html( $c['author'] ); ?></strong>
					<span>SEO 自动标签插件作者</span>
				</p>
				<p class="seo-auto-tags-credit-site">
					<a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( preg_replace( '#^https?://#', '', $c['url'] ) ); ?>
					</a>
					<span class="seo-auto-tags-credit-license">GPL-2.0-or-later</span>
				</p>
				<p class="description">
					本插件以 GPL-2.0-or-later 发布。再分发时请保留署名信息。
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * 渲染标签健康度面板。
	 */
	private static function render_health() {
		$st   = self::get_stats();
		$tips = array();

		if ( $st['posts'] > 0 && $st['no_tag'] > 0 ) {
			$tips[] = array(
				'warn',
				'有 ' . (int) $st['no_tag'] . ' 篇文章还没打标签。可以到「文章」列表页用本插件的「一键生成」补上。',
			);
		}

		if ( $st['unused'] > 0 ) {
			$tips[] = array(
				'warn',
				'有 ' . (int) $st['unused'] . ' 个标签没有被任何文章使用。它们会生成空归档页，建议到「文章 → 标签」里删掉。',
			);
		}

		if ( $st['total'] > 0 && $st['once'] > $st['total'] * 0.5 ) {
			$tips[] = array(
				'warn',
				'超过一半的标签（' . (int) $st['once'] . ' 个）只被用过一次。标签太发散会让权重分散，'
					. '建议把意思相近的合并，并保持「复用已有标签」开着。',
			);
		}

		if ( $st['posts'] > 0 && $st['total'] > $st['posts'] * 3 ) {
			$tips[] = array(
				'warn',
				'标签数（' . (int) $st['total'] . '）相对文章数（' . (int) $st['posts'] . '）偏多，建议收敛一下。',
			);
		}

		if ( empty( $tips ) ) {
			$tips[] = array( 'ok', '标签体系目前很健康，继续保持。' );
		}
		?>
		<div class="seo-auto-tags-health">
			<h2 class="title">标签健康度</h2>
			<p class="description" style="margin-top:-6px">
				标签建得太多太碎，会产出大量内容稀薄的归档页，稀释整站权重。这里帮你看住这个指标。
			</p>

			<div class="seo-auto-tags-stats">
				<div class="seo-auto-tags-stat">
					<span class="k">站内标签总数</span>
					<span class="v"><?php echo (int) $st['total']; ?></span>
				</div>
				<div class="seo-auto-tags-stat">
					<span class="k">已发布文章</span>
					<span class="v"><?php echo (int) $st['posts']; ?></span>
				</div>
				<div class="seo-auto-tags-stat">
					<span class="k">没打标签的文章</span>
					<span class="v<?php echo $st['no_tag'] > 0 ? ' is-warn' : ''; ?>"><?php echo (int) $st['no_tag']; ?></span>
				</div>
				<div class="seo-auto-tags-stat">
					<span class="k">没被使用的标签</span>
					<span class="v<?php echo $st['unused'] > 0 ? ' is-warn' : ''; ?>"><?php echo (int) $st['unused']; ?></span>
				</div>
				<div class="seo-auto-tags-stat">
					<span class="k">只用过 1 次的标签</span>
					<span class="v<?php echo $st['once'] > 0 ? ' is-warn' : ''; ?>"><?php echo (int) $st['once']; ?></span>
				</div>
			</div>

			<?php foreach ( $tips as $tip ) : ?>
				<div class="seo-auto-tags-tip is-<?php echo esc_attr( $tip[0] ); ?>">
					<?php echo esc_html( $tip[1] ); ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

