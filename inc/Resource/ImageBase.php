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

    public function valid(): bool
    {
        return (bool) $this->image;
    }

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

    // fallback method to satisfy static analysis
    public function from(): void {}

    public function _width(): int
    {
        if (method_exists($this, 'width')) {
            return $this->width();
        }
        // use default GD functions
        return imagesx($this->image);
    }

    public function _height(): int
    {
        if (method_exists($this, 'height')) {
            return $this->height();
        }
        // use default GD functions
        return imagesy($this->image);
    }

    public function _destroy(): bool
    {
        if (method_exists($this, 'destroy')) {
            return $this->destroy();
        }
        // use default GD functions
        return imagedestroy($this->image);
    }

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
