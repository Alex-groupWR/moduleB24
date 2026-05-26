<?php

namespace Rusgeocom\Rusgeocom\Utils;

class Page
{

	public static function onEndBufferContent(&$content)
	{
		if (!str_contains($content, 'new Image')) {
			return;
		}

		$content = preg_replace(
			'/.*<script>((\s*)((new Image\((.*?)\)(\S*);(\s*))*)(\s*))<\/script>.*/',
			'<noindex><script data-skip-moving="true">${1}</script></noindex>',
			$content
		);
	}
}