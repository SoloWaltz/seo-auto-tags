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

		// 屏蔽词：多行文本，保留换行，别的内容按行清洗。
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

		// 顺手校验接口地址，别等用户去文章里点了才发现填错。
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

		// API Key 留空时保留原值，避免用户只改别的选项就把密钥清掉。
		$key = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		if ( '' === $key ) {
			$old             = self::get( 'api_key' );
			$out['api_key']  = $old;
		} else {
			$out['api_key'] = sanitize_text_field( $key );
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

		$o         = self::get();
		$providers = self::providers();
		$has_key   = '' !== (string) $o['api_key'];
		?>
		<div class="wrap seo-auto-tags-wrap">
			<h1>SEO 自动标签</h1>
			<p class="seo-auto-tags-lead">
				写文章时点一下按钮，插件会读正文内容，按 SEO 思路给出候选标签，<strong>你勾选哪几个，才会用哪几个</strong>。
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'seo_auto_tags_group' ); ?>

				<h2 class="title">基础</h2>
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
							<p class="description" style="margin-left:24px">
								靠中文词组频次 + 标题加权 + 已有标签匹配来挑词。够用，但选词的「判断力」不如 AI。
							</p>
							<label class="seo-auto-tags-radio">
								<input type="radio" name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[mode]" value="ai"
									data-seo-auto-tags-mode="ai" <?php checked( 'ai', $o['mode'] ); ?> />
								<strong>AI 智能</strong> —— 需要填一个 API Key，效果好很多
							</label>
							<p class="description" style="margin-left:24px">
								让大模型读完整篇文章，按 SEO 价值挑词。一次调用成本约几厘钱。
							</p>
						</td>
					</tr>
				</table>

				<div class="seo-auto-tags-ai-block" data-seo-auto-tags-ai-block>
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
								<input type="password" class="regular-text" id="seo-auto-tags-key"
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[api_key]"
									value="" autocomplete="new-password"
									placeholder="<?php echo $has_key ? '已保存（留空则不改动）' : '粘贴你的 API Key'; ?>" />
								<button type="button" class="button button-secondary" data-seo-auto-tags-toggle-key>显示</button>
								<p class="description">
									<?php if ( $has_key ) : ?>
										<span class="seo-auto-tags-ok">已保存。</span>留空表示不修改。
									<?php else : ?>
										还没填。填了才能用 AI 模式。
									<?php endif; ?>
								</p>
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
						<tr>
							<th scope="row">调用限速</th>
							<td>
								<input type="number" min="0" max="1000" class="small-text"
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[rate_limit]"
									value="<?php echo esc_attr( (int) $o['rate_limit'] ); ?>" />
								次 / 小时 / 每人
								<p class="description">
									作者、编辑都能用这个插件。限速能防止有人手滑连点把 API 额度刷光。
									填 <code>0</code> 表示不限速。
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">结果缓存</th>
							<td>
								<input type="number" min="0" max="720" class="small-text"
									name="<?php echo esc_attr( SEO_AUTO_TAGS_OPTION ); ?>[cache_ttl]"
									value="<?php echo esc_attr( (int) $o['cache_ttl'] ); ?>" />
								小时
								<p class="description">
									同一篇文章内容不变时，重复点「生成」会直接读缓存，不再花钱调 API。
									填 <code>0</code> 关闭缓存。
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row">连通性测试</th>
							<td>
								<button type="button" class="button" data-seo-auto-tags-test>测试连接</button>
								<span class="seo-auto-tags-test-result" data-seo-auto-tags-test-result></span>
								<p class="description">
									先点这个确认能通，再去文章里用。测试用的是已保存的配置。<br>
									每次测试会消耗极少量 token（约 20 个），已限制一分钟最多 5 次。
								</p>
							</td>
						</tr>
					</table>

					<div class="seo-auto-tags-consume">
						<?php $usage = SEO_Auto_Tags_Generator::get_usage(); ?>
						<p class="seo-auto-tags-usage">
							<strong>本月 AI 调用：<?php echo (int) $usage['count']; ?> 次</strong>
							<span class="description">
								（统计月份 <?php echo esc_html( $usage['month'] ); ?>，每月自动归零；只统计成功拿到结果的调用）
							</span>
						</p>

						<p><strong>会花在哪 —— 消耗清单</strong></p>
						<ul>
							<li><b>编辑器点「生成标签」</b>：一次调用。同一篇文章内容不变时读缓存，不重复计费。</li>
							<li><b>打开编辑器自动生成</b>（若开启）：同上。关掉它就完全不会自动花钱。</li>
							<li><b>文章列表页「一键生成」</b>：每篇一次调用。</li>
							<li><b>「测试连接」</b>：每次约 20 token，已限制一分钟最多 5 次。</li>
						</ul>
						<p class="description">
							所有生成入口都受上面的「调用限速」约束。超限时<b>自动改用本地算法</b>并在界面上说明原因，
							不会报错中断，也不会偷偷继续花钱。<br>
							调用失败时会把<b>具体原因</b>显示出来（Key 无效 / 余额不足 / 被限流 / 地址写错），
							而不是笼统地说「失败了」。
						</p>
					</div>
				</div>

				<?php submit_button( '保存设置' ); ?>
			</form>

			<div class="seo-auto-tags-footer">
				<p><strong>怎么用：</strong>打开任意文章的编辑页 → 右侧「SEO 自动标签」面板 → 点「生成标签」→ 勾选想要的 → 点「应用选中标签」。</p>
				<p class="description">标签只是「候选」，不点应用就不会写进文章。</p>
				<p class="seo-auto-tags-credit">
					作者：西瓜烧鱼　·　网站：<a href="https://www.rrshare.com/" target="_blank" rel="noopener noreferrer">www.rrshare.com</a>
				</p>
			</div>

			<?php self::render_health(); ?>
		</div>
		<?php
	}

	/**
	 * 标签健康度统计。
	 *
	 * @return array
	 */
	public static function get_stats() {
		// 这个函数要扫全量标签 + 跑一次 NOT EXISTS 查询，文章多了会明显变慢。
		// 设置页每次加载都会调它，所以缓存 5 分钟。
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
