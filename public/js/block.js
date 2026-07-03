/**
 * Smart Assistant — Gutenberg block (dynamic).
 * Frontend çıktısı PHP render_callback'ten gelir; editörde placeholder gösterilir.
 */
( function ( blocks, element ) {
	'use strict';

	blocks.registerBlockType( 'smart-assistant/chat', {
		title: 'Smart Assistant Sohbet',
		description: 'Sayfa içine gömülü AI sohbet kutusu.',
		icon: 'format-chat',
		category: 'widgets',
		supports: { html: false, multiple: false },

		edit: function () {
			return element.createElement(
				'div',
				{
					style: {
						border: '1px dashed #94a3b8',
						borderRadius: '10px',
						padding: '28px 16px',
						textAlign: 'center',
						color: '#475569',
						background: '#f8fafc',
					},
				},
				'💬 Smart Assistant sohbet kutusu — sayfada burada görünecek'
			);
		},

		save: function () {
			return null; // Dynamic block: çıktı PHP'den.
		},
	} );
} )( window.wp.blocks, window.wp.element );
