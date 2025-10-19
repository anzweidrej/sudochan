<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource;

use Sudochan\Resource\ImageResource as Image;
use Sudochan\Resource\Extensions\ImageGD;

class ImageBase extends ImageGD
{
    public $image;
    public string $src;
    public string $format;
    public $original;
    public int $original_width;
    public int $original_height;
    public int $width;
    public int $height;

    /**
     * Check whether the image was successfully loaded.
     *
     * @return bool True if image resource is valid.
     */
    public function valid(): bool
    {
        return (bool) $this->image;
    }

    /**
     * Construct the image wrapper.
     *
     * @param Image|false $img ImageResource or false.
     * @param array|false $size Optional [width, height].
     */
    public function __construct(Image|false $img, array|false $size = false)
    {
        if (method_exists($this, 'init')) {
            $this->init();
        }

        if ($size && $size[0] > 0 && $size[1] > 0) {
            $this->width = $size[0];
            $this->height = $size[1];
        }

        if ($img !== false) {
            $this->src = $img->src;
            $this->from();
        }
    }

    /**
     * Fallback loader method for static analysis.
     *
     * @return void
     */
    public function from(): void {}

    /**
     * Get current image width using backend-specific method when available.
     *
     * @return int Width in pixels.
     */
    public function _width(): int
    {
        if (method_exists($this, 'width')) {
            return $this->width();
        }
        // use default GD functions
        return imagesx($this->image);
    }

    /**
     * Get current image height using backend-specific method when available.
     *
     * @return int Height in pixels.
     */
    public function _height(): int
    {
        if (method_exists($this, 'height')) {
            return $this->height();
        }
        // use default GD functions
        return imagesy($this->image);
    }

    /**
     * Destroy/free the image resource using backend-specific method when available.
     *
     * @return bool True on success.
     */
    public function _destroy(): bool
    {
        if (method_exists($this, 'destroy')) {
            return $this->destroy();
        }
        // use default GD functions
        return imagedestroy($this->image);
    }

    /**
     * Resize the provided original into this instance using backend-specific method.
     *
     * @param mixed $original Backend-specific original image resource.
     * @param int $width Target width.
     * @param int $height Target height.
     * @return void
     */
    public function _resize(mixed $original, int $width, int $height): void
    {
        $this->original = &$original;
        $this->width = $width;
        $this->height = $height;

        if (method_exists($this, 'resize')) {
            $this->resize();
        } else {
            // use default GD functions
            $this->GD_resize();
        }
    }
}
