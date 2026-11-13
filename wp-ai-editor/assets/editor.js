/* WP AI Editor – "AI Editor" sidebar in the block editor (no build step). */
(function (wp) {
	'use strict';

	if (!wp || !wp.plugins || !wp.element || !wp.data) {
		return;
	}
	var config = window.wpaieEditor || {};
	var el = wp.element.createElement;
	var editorPkg = wp.editor || {};
	var editPostPkg = wp.editPost || {};
	// WordPress 6.6 moved these to wp.editor; older versions have them in wp.editPost.
	var PluginSidebar = editorPkg.PluginSidebar || editPostPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem || editPostPkg.PluginSidebarMoreMenuItem;
	if (!PluginSidebar) {
		return;
	}

	function Sidebar() {
		var postId = wp.data.useSelect(function (select) {
			return select('core/editor').getCurrentPostId();
		}, []);
		var src = config.chatUrl + '&context_post=' + encodeURIComponent(postId || '');

		return el(
			wp.element.Fragment,
			null,
			PluginSidebarMoreMenuItem ? el(PluginSidebarMoreMenuItem, { target: 'wpaie-sidebar', icon: 'superhero' }, config.title) : null,
			el(
				PluginSidebar,
				{ name: 'wpaie-sidebar', title: config.title, icon: 'superhero' },
				el('p', { style: { margin: '8px 16px', fontSize: '12px', color: '#757575' } }, config.notice),
				el('iframe', {
					src: src,
					title: config.title,
					style: { width: '100%', height: 'calc(100vh - 200px)', border: 0, display: 'block' }
				})
			)
		);
	}

	wp.plugins.registerPlugin('wp-ai-editor', { render: Sidebar, icon: 'superhero' });
})(window.wp);
