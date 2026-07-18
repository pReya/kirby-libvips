<?php

use Kirby\Cms\App;
use Kirby\Image\Darkroom;
use Bitbetter\Libvips\Vips;

if (class_exists(Vips::class) === false) {
	require_once __DIR__ . '/classes/Vips.php';
}

// register the darkroom driver for `thumbs.driver => 'vips'`
// ('vipsthumbnail' is kept as a legacy alias)
Darkroom::$types['vips'] ??= Vips::class;
Darkroom::$types['vipsthumbnail'] ??= Vips::class;

App::plugin('bitbetter/kirby-libvips', []);
