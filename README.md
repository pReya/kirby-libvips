# Kirby libvips thumbnail driver

A thumbnail ([Darkroom](https://github.com/getkirby/kirby/tree/main/src/Image/Darkroom)) driver for [Kirby CMS](https://getkirby.com) that generates thumbs with [libvips](https://www.libvips.org) — in-process via php-vips (FFI) or by shelling out to the `vipsthumbnail` CLI. Compared to GD or ImageMagick, libvips is faster and uses a fraction of the memory — especially noticeable with large source images or bulk thumb generation. Resize, crop (center, positional, panel focus points, smartcrop), `blur`, `grayscale` and `sharpen` are all supported.

Requires **Kirby 4 or 5**, **PHP ≥ 8.1** and **libvips ≥ 8.15** installed on the server (`apt install libvips-tools`, `brew install vips`, …). Out of the box the plugin shells out to the `vipsthumbnail` and `vips` binaries — no PHP extension needed. If [php-vips](https://github.com/libvips/php-vips) is installed (`composer require jcupitt/vips`), the plugin automatically switches to in-process generation via FFI, which eliminates the ~60 ms process-spawn cost per thumb and makes small thumbs faster than GD.

## Installation

```
composer require preya/kirby-libvips
```

Or copy this repository to `/site/plugins/kirby-libvips`.

For the fast in-process backend (recommended), additionally install php-vips:

```
composer require jcupitt/vips
```

The plugin picks it up automatically — no configuration needed. Without it, the plugin uses the `vipsthumbnail`/`vips` binaries.

## Usage

```php
// site/config/config.php
return [
    'thumbs' => [
        'driver' => 'vips',
    ],
];
```

That's it. The plugin registers itself as a proper Darkroom driver, so Kirby's whole thumb pipeline (media jobs, `srcset()`, focus cropping, format conversion via `thumbs.format`) works unchanged.

## Options

All options go into the `thumbs` config array:

| Option | Default | Description |
|---|---|---|
| `backend` | `auto` | `ffi` (in-process php-vips), `cli` (binaries), or `auto` to pick FFI when available |
| `bin` | `vipsthumbnail` | Path to the `vipsthumbnail` binary (CLI backend) |
| `vipsBin` | `vips` | Path to the `vips` binary (used for exact focus crops) |
| `quality` | `90` | Output quality (JPEG/WebP/AVIF/…) |
| `interlace` | `false` | Progressive JPEGs / interlaced PNGs |
| `strip` | `true` | Strip all metadata (EXIF, ICC, XMP) from thumbs |
| `profile` | `'srgb'` | Convert to this ICC profile before stripping (`false` to disable) |
| `smartcrop` | `false` | Content-aware cropping for plain center crops: `'attention'` or `'entropy'` |
| `autoOrient` | `true` | Auto-rotate according to EXIF orientation |
| `threads` | `1` | Number of threads vips may use per job |

```php
return [
    'thumbs' => [
        'driver'    => 'vips',
        'quality'   => 85,
        'interlace' => true,
        'smartcrop' => 'attention',
        'bin'       => '/usr/local/bin/vipsthumbnail',
    ],
];
```

## Cropping behavior

- `$file->crop(300, 200)` — center crop (or content-aware, if `smartcrop` is configured)
- Positional crops (`'top left'`, `'bottom'`, …) and **panel focus points** (`'45% 30%'`) are honored exactly, matching the behavior of Kirby's built-in drivers. EXIF-rotated images are handled correctly (the crop is applied in display space).
- With `smartcrop: 'attention'`, plain center crops use libvips' [smartcrop](https://www.libvips.org/API/current/libvips-conversion.html#vips-smartcrop) to find the most interesting region — explicit focus points still win.

## Effects

`blur`, `grayscale` and `sharpen` are fully supported:

```php
$file->blur(10);
$file->grayscale();   // also: ->bw()
$file->sharpen(50);
$file->thumb(['width' => 480, 'grayscale' => true, 'blur' => 4, 'sharpen' => 50]); // combined in one job
```

They are parameter-matched to Kirby's ImageMagick driver (`gaussblur` with the same sigma, `colourspace b-w`, `sharpen --sigma amount/100`), so switching drivers doesn't change the look. Note that GD's blur is much weaker than IM/vips at the same value — that inconsistency exists between Kirby's built-in drivers too.

With the FFI backend the whole pipeline (shrink, crop, effects, encode) fuses in memory — no subprocesses, no intermediate files. With the CLI backend, effects run as additional `vips` operations on the already-shrunk intermediate, so each adds one process spawn but little actual work.

## Backends

- **`auto`** (default): uses php-vips (FFI) when `jcupitt/vips` is installed and PHP's FFI extension is usable, otherwise the CLI binaries. Detection runs once per request and fails safe to CLI.
- **`ffi`**: forces php-vips. ~5–15 ms per thumb warm; the one-time FFI/libvips initialization (~150 ms) is paid once per PHP-FPM worker, not per thumb. If FFI is blocked on your setup, set `ffi.enable = true` in php.ini.
- **`cli`**: forces the `vipsthumbnail`/`vips` binaries. No PHP requirements at all, but each thumb pays a fixed process-spawn cost (~10–70 ms depending on platform).

Both backends produce identically sized, visually identical output — `backend` only changes how fast you get it.

## Performance

All numbers measured through Kirby's real thumb pipeline (`Darkroom->process()` on a copied file, exactly what the media route does) with Kirby 5.5.2, libvips 8.18.4, ImageMagick 7.1.2, PHP 8.5 on Apple Silicon; fastest of 3 runs, warm. Your absolute numbers will differ — the ratios are the point.

### Speed

| | GD | ImageMagick | vips CLI | vips FFI |
|---|---:|---:|---:|---:|
| 1000 px source → resize 480 px | 10.5 ms | 24.1 ms | 91.4 ms | **7.8 ms** |
| 1000 px source → focus crop | 19.1 ms | 25.5 ms | 158.3 ms | **3.7 ms** |
| 1000 px source → gray + blur + sharpen | 35.1 ms | 30.6 ms | 313.4 ms | **12.6 ms** |
| 6000 px source → resize 480 px | 204.7 ms | 154.7 ms | 121.1 ms | **37.9 ms** |
| 6000 px source → srcset (4 widths) | 887.6 ms | 1320.4 ms | 735.3 ms | **219.8 ms** |

What that means in practice:

- **A gallery page requesting 50 thumbs** of fresh 17 MP camera uploads: ~1.9 s of CPU with the FFI backend vs. ~10 s with GD — and if each image also gets a 4-width `srcset()`, ~11 s vs. ~44 s.
- **Small, already-downscaled sources** (CMS re-uploads, screenshots): FFI is still the fastest option; the CLI backend is the *slowest* here because every job pays a fixed ~60–80 ms process spawn regardless of image size. If you can't use FFI and your sources are small, GD is legitimately fine.
- Thumbs are generated once and cached in `/media`, so these costs apply on first generation (uploads, cache busts, deploys with cleared media) — which is exactly when dozens of jobs hit PHP at once.

### Memory

Peak RSS generating one 480 px thumb from the 6000 px source:

| GD | ImageMagick | vips CLI | vips FFI |
|---:|---:|---:|---:|
| 106.5 MB | 240.9 MB | **32.4 MB** | **~46 MB** on top of the PHP worker¹ |

¹ 74 MB total for a one-shot `php` process incl. the 28 MB PHP baseline; in PHP-FPM the runtime is already resident, so the marginal cost per worker is the ~46 MB libvips share, reused across all jobs of that worker.

- **GD and ImageMagick decode the full bitmap first** — memory scales with source *megapixels*, not thumb size. The 6000 × 2862 source costs GD ~68 MB for the raw bitmap before any resizing happens. libvips streams via shrink-on-load and stays near-flat regardless of source size.
- **GD actually crashes on this workload:** `$file->crop(240, 240)` on the 6000 px source aborts with `Allowed memory size of 134217728 bytes exhausted` at PHP's default 128 MB `memory_limit`. The same job needs no config changes with libvips.
- **Concurrency is the multiplier:** a panel page or gallery triggers many parallel media requests. Eight PHP-FPM workers generating simultaneously peak around ~850 MB with GD and ~1.9 GB with ImageMagick — vs. ~260 MB with the CLI backend or ~370 MB with FFI. On small servers this is the difference between finishing and OOM-killing.

## Migrating from kirby3-vipsthumbnail

- `thumbs.driver => 'vipsthumbnail'` still works as an alias for `'vips'`.
- `blur`, `grayscale` and `sharpen` were previously ignored — they are now applied.
- Panel focus points and positional crops were previously flattened to smartcrop-attention — they are now honored exactly; content-aware cropping is opt-in via the `smartcrop` option.
- The `log`/`logdir` options were removed; errors now surface as exceptions including the vips error output.
- `autoOrient` now defaults to `true` (matching libvips' own default and Kirby's other drivers) and no longer breaks on libvips ≥ 8.8.

## LLM disclosure

The Kirby 4/5 rewrite of this plugin — the Darkroom driver, the FFI backend, the effects support, this README and the benchmarks — was written in large part by an LLM (Claude Opus 4.8 via Claude Code), directed and reviewed by a human maintainer.

What that means for you:

- **Nothing here is unverified LLM output.** Every code path was exercised against a real Kirby 5.5.2 installation with an 18-case test harness: output dimensions are checked against Kirby's GD driver, EXIF-rotated sources, panel focus points, format conversion (WebP/AVIF/PNG), metadata stripping, quality settings, and paths with spaces are all covered, for both backends.
- **The benchmark numbers are real measurements** from that setup (hardware and versions stated above), not estimates or vendor claims.
- The plugin still ships human-auditable, dependency-light code: two PHP files you can read in ten minutes.

If you find behavior that differs from Kirby's built-in drivers beyond what's documented here, that's a bug — please open an issue.

## Credits

Originally based on [kirby3-vipsthumbnail](https://github.com/floriankarsten/kirby3-vipsthumbnail) by Florian Karsten. Rewritten for Kirby 4/5's Darkroom API and current libvips.

## License

MIT
