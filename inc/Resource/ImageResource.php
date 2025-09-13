<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource;

defined('TINYBOARD') or exit;

use Sudochan\Manager\FileManager;
use Sudochan\Resource\ImageBase;

class ImageResource
{
    public string $src;
    public string $format;
    public $image;
    public object $size;

    public function __construct(string $src, string|false $format = false, array|false $size = false)
    {
        global $config;

        $this->src = $src;
        $this->format = $format;

        if ($config['thumb_method'] === 'imagick') {
            $classname = 'ImageImagick';
        } elseif (in_array($config['thumb_method'], ['convert', 'convert+gifsicle', 'gm', 'gm+gifsicle'], true)) {
            $classname = 'ImageConvert';
        } else {
            $classname = 'Image' . strtoupper((string) $this->format);
            if (!class_exists($classname)) {
                error(_('Unsupported file format: ') . $this->format);
            }
        }

        $this->image = new $classname($this, $size);

        if (!$this->image->valid()) {
            $this->delete();
            error($config['error']['invalidimg']);
        }

        $this->size = (object) [
            'width' => $this->image->_width(),
            'height' => $this->image->_height(),
        ];
        if ($this->size->width < 1 || $this->size->height < 1) {
            $this->delete();
            error($config['error']['invalidimg']);
        }
    }

    public function resize(string $extension, int $max_width, int $max_height): ImageBase
    {
        global $config;

        $classname = null;
        $gifsicle = false;
        $gm = false;

        switch ($config['thumb_method']) {
            case 'imagick':
                $classname = 'ImageImagick';
                break;
            case 'convert':
                $classname = 'ImageConvert';
                break;
            case 'convert+gifsicle':
                $classname = 'ImageConvert';
                $gifsicle = true;
                break;
            case 'gm':
                $classname = 'ImageConvert';
                $gm = true;
                break;
            case 'gm+gifsicle':
                $classname = 'ImageConvert';
                $gm = true;
                $gifsicle = true;
                break;
            default:
                $classname = 'Image' . strtoupper((string) $extension);
                if (!class_exists($classname)) {
                    error(_('Unsupported file format: ') . $extension);
                }
        }

        $thumb = new $classname(false);
        $thumb->src = $this->src;
        $thumb->format = $this->format;
        $thumb->original_width = $this->size->width;
        $thumb->original_height = $this->size->height;

        $x_ratio = $max_width / $this->size->width;
        $y_ratio = $max_height / $this->size->height;

        if ($this->size->width <= $max_width && $this->size->height <= $max_height) {
            $width = $this->size->width;
            $height = $this->size->height;
        } elseif (($x_ratio * $this->size->height) < $max_height) {
            $height = (int) ceil($x_ratio * $this->size->height);
            $width = $max_width;
        } else {
            $width = (int) ceil($y_ratio * $this->size->width);
            $height = $max_height;
        }

        $thumb->_resize($this->image->image, $width, $height);

        return $thumb;
    }

    public function to(string $dst): void
    {
        $this->image->to($dst);
    }

    public function delete(): void
    {
        FileManager::file_unlink($this->src);
    }

    public function destroy(): void
    {
        $this->image->_destroy();
    }
}
