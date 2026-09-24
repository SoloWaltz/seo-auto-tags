/**
 * SEO 自动标签 · 设置页交互
 */
( function () {
	'use strict';

	var cfg = window.SEO_AUTO_TAGS_ADMIN || {};

	function q( sel ) {
		return document.querySelector( sel );
	}

	function qa( sel ) {
		return Array.prototype.slice.call( document.querySelectorAll( sel ) );
	}

	/* 服务商联动：自动填接口地址与模型名 */
	function bindProvider() {
		var sel = q( '[data-seo-auto-tags-provider]' );
		var base = q( '[data-seo-auto-tags-base]' );
		var model = q( '[data-seo-auto-tags-model]' );

		if ( ! sel ) {
			return;
		}

		sel.addEventListener( 'change', function () {
			var p = ( cfg.providers || {} )[ sel.value ];

			if ( ! p ) {
				return;
			}
			if ( base && p.base ) {
				base.value = p.base;
			}
			if ( model && p.model ) {
				model.value = p.model;
			}
		} );
	}

	/* 显示 / 隐藏 API Key */
	function bindKeyToggle() {
		var btn = q( '[data-seo-auto-tags-toggle-key]' );
		var input = q( '#seo-auto-tags-key' );

		if ( ! btn || ! input ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			if ( input.type === 'password' ) {
				input.type = 'text';
				btn.textContent = '隐藏';
			} else {
				input.type = 'password';
				btn.textContent = '显示';
			}
		} );
	}

	/* 模式切换：只在 AI 模式下显示接口配置 */
	function bindMode() {
		var radios = qa( '[data-seo-auto-tags-mode]' );
		var block = q( '[data-seo-auto-tags-ai-block]' );

		if ( ! radios.length || ! block ) {
			return;
		}

		function sync() {
			var mode = 'local';

			radios.forEach( function ( r ) {
				if ( r.checked ) {
					mode = r.value;
				}
			} );

			block.style.display = mode === 'ai' ? '' : 'none';
		}

		radios.forEach( function ( r ) {
			r.addEventListener( 'change', sync );
		} );

		sync();
	}

	/* 测试连通性 */
	function bindTest() {
		var btn = q( '[data-seo-auto-tags-test]' );
		var out = q( '[data-seo-auto-tags-test-result]' );

		if ( ! btn || ! out ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			out.textContent = ' 测试中…';
			out.className = 'seo-auto-tags-test-result';

			var body = new URLSearchParams();
			body.append( 'action', 'seo_auto_tags_test' );
			body.append( 'nonce', cfg.nonce || '' );

			fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( res ) {
					if ( res && res.success ) {
						out.textContent = ' ' + res.data.message;
						out.className = 'seo-auto-tags-test-result is-ok';
					} else {
						out.textContent = ' ' + ( ( res && res.data && res.data.message ) || '测试失败。' );
						out.className = 'seo-auto-tags-test-result is-error';
					}
				} )
				.catch( function () {
					out.textContent = ' 请求失败，请刷新页面重试。';
					out.className = 'seo-auto-tags-test-result is-error';
				} )
				.then( function () {
					btn.disabled = false;
				} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			bindProvider();
			bindKeyToggle();
			bindMode();
			bindTest();
		} );
	} else {
		bindProvider();
		bindKeyToggle();
		bindMode();
		bindTest();
	}
} )();
