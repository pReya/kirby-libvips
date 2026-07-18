<?php

namespace Bitbetterde\Libvips;

use Exception;
use Kirby\Filesystem\F;
use Kirby\Image\Darkroom;
use Kirby\Image\Focus;
use Kirby\Image\Image;

/**
 * libvips Darkroom driver
 *
 * Generates thumbnails by shelling out to the `vipsthumbnail`
 * (and, for focus crops, the `vips`) CLI that ships with libvips.
 * Requires libvips >= 8.15.
 */
class Vips extends Darkroom
{
	/**
	 * Returns the default thumb settings,
	 * on top of Kirby's darkroom defaults
	 */
	protected function defaults(): array
	{
		return parent::defaults() + [
			// auto-rotate based on EXIF orientation (vips default)
			'autoOrient' => true,
			// 'ffi' (in-process php-vips), 'cli' (vipsthumbnail binary)
			// or 'auto' to use FFI whenever it is available
			'backend'    => 'auto',
			// path to the vipsthumbnail binary
			'bin'        => 'vipsthumbnail',
			// generate interlaced (progressive) JPEGs/PNGs
			'interlace'  => false,
			// ICC output profile ('srgb' recommended, false to disable)
			'profile'    => 'srgb',
			// content-aware cropping strategy for plain center crops:
			// false (deterministic center crop), 'attention' or 'entropy'
			'smartcrop'  => false,
			// strip all metadata from the thumbnail
			'strip'      => true,
			// number of threads vips may use
			'threads'    => 1,
			// path to the vips binary (used for exact focus crops)
			'vipsBin'    => 'vips',
		];
	}

	/**
	 * Creates and runs the vips command(s) to process the image
	 *
	 * @throws \Exception
	 */
	public function process(string $file, array $options = []): array
	{
		$options = $this->preprocess($file, $options);

		// generate into a temp file next to the target so a failed
		// run never leaves broken output at the final path
		$tmp   = $this->tmpRoot($file);
		$temps = [$tmp];

		try {
			$crop  = $options['crop'];
			$focus = $crop !== false ? Focus::coords(
				$crop,
				$options['sourceWidth'],
				$options['sourceHeight'],
				$options['width'],
				$options['height']
			) : null;

			$strategy = $focus !== null ? $this->smartcrop($crop, $options) : null;

			if ($this->backend($options) === 'ffi') {
				$this->processFfi($file, $tmp, $focus, $strategy, $options);
			} else {
				$this->processCli($file, $tmp, $focus, $strategy, $options, $temps);
			}

			// move the finished thumb over the target file
			F::move($tmp, $file, true);
		} finally {
			foreach ($temps as $temp) {
				F::remove($temp);
			}
		}

		return $options;
	}

	/**
	 * Resolves which backend to use for this job
	 */
	protected function backend(array $options): string
	{
		return match ($options['backend']) {
			'ffi', 'cli' => $options['backend'],
			default      => static::ffiSupported() ? 'ffi' : 'cli'
		};
	}

	/**
	 * Checks once whether in-process php-vips (FFI) is usable
	 */
	public static function ffiSupported(): bool
	{
		static $supported = null;

		if ($supported === null) {
			try {
				$supported =
					class_exists(\Jcupitt\Vips\Config::class) === true &&
					is_string(\Jcupitt\Vips\Config::version()) === true;
			} catch (\Throwable) {
				$supported = false;
			}
		}

		return $supported;
	}

	/**
	 * Generates the thumb via the vipsthumbnail/vips CLI binaries
	 *
	 * @throws \Exception
	 */
	protected function processCli(
		string $file,
		string $tmp,
		array|null $focus,
		string|null $strategy,
		array $options,
		array &$temps
	): void {
		$input = $file;

		if ($focus !== null && $strategy === null) {
			// exact crop rectangle (focal point or non-center position):
			// auto-rotate and crop first, then shrink the cropped region
			$input = $this->cropExact($file, $focus, $options, $temps);
		}

		$chain = $this->effects($options);

		if ($chain === []) {
			$this->exec($this->thumbnailCommand($input, $tmp, $options, $strategy));
			return;
		}

		// shrink into an uncompressed intermediate, run the
		// effects chain on it and encode in the very last step
		$temps[] = $work = $this->tmpRoot($file, 'v');
		$this->exec($this->thumbnailCommand($input, $work, $options, $strategy));

		$lastIndex = array_key_last($chain);

		foreach ($chain as $index => [$op, $args]) {
			if ($index === $lastIndex) {
				$save = $this->saveOptions(F::extension($tmp), $options);
				$dst  = $save === '' ? $tmp : $tmp . '[' . $save . ']';
			} else {
				$temps[] = $dst = $this->tmpRoot($file, 'v');
			}

			$this->exec($this->vipsCommand($op, $work, $dst, $args, $options));
			$work = $dst;
		}
	}

	/**
	 * Generates the thumb in-process via php-vips (FFI);
	 * the whole pipeline fuses in memory without any
	 * process spawns or intermediate files
	 */
	protected function processFfi(
		string $file,
		string $tmp,
		array|null $focus,
		string|null $strategy,
		array $options
	): void {
		$params = ['height' => $options['height']];

		if (is_string($options['profile']) === true) {
			$params['output-profile'] = $options['profile'];
		}

		if ($focus !== null && $strategy === null) {
			// exact crop rectangle: rotate upright, crop in display
			// space, then shrink the cropped region
			$image = \Jcupitt\Vips\Image::newFromFile($file);

			if ($options['autoOrient'] === true) {
				$image = $image->autorot();
			}

			$image = $image
				->crop($focus['x1'], $focus['y1'], $focus['width'], $focus['height'])
				->thumbnail_image($options['width'], $params);
		} else {
			if ($strategy !== null) {
				$params['crop'] = $strategy;
			}

			if ($options['autoOrient'] === false) {
				$params['no-rotate'] = true;
			}

			$image = \Jcupitt\Vips\Image::thumbnail($file, $options['width'], $params);
		}

		// effects
		if ($options['grayscale'] === true) {
			$image = $image->colourspace('b-w');
		}

		if ($options['blur'] !== false) {
			$image = $image->gaussblur((int)$options['blur']);
		}

		if (is_int($options['sharpen']) === true) {
			$amount = max(1, min(100, $options['sharpen'])) / 100;
			$image  = $image->sharpen(['sigma' => $amount]);
		}

		$image->writeToFile($tmp, $this->ffiSaveParams(F::extension($tmp), $options));
	}

	/**
	 * Save parameters for php-vips' writeToFile,
	 * mirroring the CLI save option string
	 */
	protected function ffiSaveParams(string $extension, array $options): array
	{
		$extension = strtolower($extension);
		$params    = [];

		if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'tif', 'tiff'], true) === true) {
			$params['Q'] = $options['quality'];
		}

		if ($options['strip'] === true) {
			$params['keep'] = 'none';
		}

		if (
			$options['interlace'] === true &&
			in_array($extension, ['jpg', 'jpeg', 'png'], true) === true
		) {
			$params['interlace'] = true;
		}

		return $params;
	}

	/**
	 * Auto-rotates (if needed) and crops the source to the exact
	 * focus rectangle; returns the root of the cropped temp file
	 */
	protected function cropExact(
		string $file,
		array $focus,
		array $options,
		array &$temps
	): string {
		$input = $file;

		// EXIF orientation is applied by vipsthumbnail after cropping,
		// but the focus coordinates refer to the rotated image;
		// bake the rotation in first so the crop happens in display space
		if (
			$options['autoOrient'] === true &&
			$this->needsAutoRotation($file) === true
		) {
			$temps[] = $rotated = $this->tmpRoot($file, 'v');
			$this->exec($this->vipsCommand('autorot', $file, $rotated, [], $options));
			$input = $rotated;
		}

		$temps[] = $cropped = $this->tmpRoot($file, 'v');
		$this->exec($this->vipsCommand('crop', $input, $cropped, [
			(string)$focus['x1'],
			(string)$focus['y1'],
			(string)$focus['width'],
			(string)$focus['height'],
		], $options));

		return $cropped;
	}

	/**
	 * Builds a command for a single vips operation
	 */
	protected function vipsCommand(
		string $operation,
		string $src,
		string $dst,
		array $args,
		array $options
	): string {
		$command = sprintf(
			'%s %s %s %s',
			escapeshellarg($options['vipsBin']),
			escapeshellarg($operation),
			escapeshellarg($src),
			escapeshellarg($dst)
		);

		foreach ($args as $arg) {
			$command .= ' ' . escapeshellarg($arg);
		}

		return $command . ' --vips-concurrency=' . escapeshellarg($options['threads']);
	}

	/**
	 * Returns the chain of vips operations for the
	 * requested blur/grayscale/sharpen effects
	 */
	protected function effects(array $options): array
	{
		$chain = [];

		if ($options['grayscale'] === true) {
			$chain[] = ['colourspace', ['b-w']];
		}

		if ($options['blur'] !== false) {
			// same sigma the ImageMagick driver uses (-blur 0xN)
			$chain[] = ['gaussblur', [(string)(int)$options['blur']]];
		}

		if (is_int($options['sharpen']) === true) {
			// match the ImageMagick driver: -sharpen 0x(amount/100)
			$amount  = max(1, min(100, $options['sharpen'])) / 100;
			$chain[] = ['sharpen', ['--sigma', (string)$amount]];
		}

		return $chain;
	}

	/**
	 * Runs a command and throws with the
	 * captured output if it failed
	 *
	 * @throws \Exception
	 */
	protected function exec(string $command): void
	{
		exec($command . ' 2>&1', $output, $return);

		if ($return !== 0) {
			throw new Exception(
				'The vips command could not be executed: ' . $command .
				' (' . trim(implode("\n", $output)) . ')'
			);
		}
	}

	/**
	 * Checks if the source image carries an EXIF
	 * orientation that vips would auto-rotate
	 */
	protected function needsAutoRotation(string $file): bool
	{
		try {
			$orientation = (new Image($file))->exif()->orientation();
			return is_int($orientation) === true && $orientation > 1;
		} catch (Exception) {
			return false;
		}
	}

	/**
	 * Builds the save option string for the output file
	 * depending on the target format
	 */
	protected function saveOptions(string $extension, array $options): string
	{
		$extension = strtolower($extension);
		$save      = [];

		// uncompressed intermediates don't take save options
		if ($extension === 'v') {
			return '';
		}

		// quality for formats with lossy (or quantized) encoders
		if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'tif', 'tiff'], true) === true) {
			$save[] = 'Q=' . $options['quality'];
		}

		if ($options['strip'] === true) {
			$save[] = 'keep=none';
		}

		if (
			$options['interlace'] === true &&
			in_array($extension, ['jpg', 'jpeg', 'png'], true) === true
		) {
			$save[] = 'interlace';
		}

		return implode(',', $save);
	}

	/**
	 * Returns the smartcrop strategy for a single-pass
	 * crop or null if an exact crop is required
	 */
	protected function smartcrop(mixed $crop, array $options): string|null
	{
		// explicit focal points ('42% 31%') always win over smartcrop
		if (is_string($crop) === true && Focus::isFocalPoint($crop) === true) {
			return null;
		}

		// content-aware cropping for plain center crops if configured
		if ($crop === 'center') {
			return match ($options['smartcrop']) {
				'attention' => 'attention',
				'entropy'   => 'entropy',
				default     => 'centre'
			};
		}

		// positional crops (top, bottom right, …) need an exact crop
		return null;
	}

	/**
	 * Builds the vipsthumbnail command
	 */
	protected function thumbnailCommand(
		string $src,
		string $dst,
		array $options,
		string|null $smartcrop = null
	): string {
		$command = sprintf(
			'%s %s --size %sx%s --vips-concurrency=%s',
			escapeshellarg($options['bin']),
			escapeshellarg($src),
			$options['width'],
			$options['height'],
			escapeshellarg($options['threads'])
		);

		if ($smartcrop !== null) {
			$command .= ' --smartcrop ' . escapeshellarg($smartcrop);
		}

		if ($options['autoOrient'] === false) {
			$command .= ' --no-rotate';
		}

		if (is_string($options['profile']) === true) {
			$command .= ' --output-profile ' . escapeshellarg($options['profile']);
		}

		$save = $this->saveOptions(F::extension($dst), $options);

		return $command . ' -o ' . escapeshellarg($save === '' ? $dst : $dst . '[' . $save . ']');
	}

	/**
	 * Returns a unique temp file root next to the given file
	 */
	protected function tmpRoot(string $file, string|null $extension = null): string
	{
		return F::dirname($file) . '/' .
			F::name($file) . '.vips-' . uniqid() . '.' .
			($extension ?? F::extension($file));
	}
}
