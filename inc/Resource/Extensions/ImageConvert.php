<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageConvert extends ImageBase
{
    public int $width = 0;
    public int $height = 0;
    public string|false $temp = false;
    public bool $gm = false;
    public bool $gifsicle = false;

    public function init(): void
    {
        global $config;

        if ($config['thumb_method'] === 'gm' || $config['thumb_method'] === 'gm+gifsicle') {
            $this->gm = true;
        }
        if ($config['thumb_method'] === 'convert+gifsicle' || $config['thumb_method'] === 'gm+gifsicle') {
            $this->gifsicle = true;
        }

        $this->temp = false;
    }

    public function get_size(string $src, bool $try_gd_first = true): array|false
    {
        if ($try_gd_first) {
            if ($size = @getimagesize($src)) {
                return $size;
            }
        }
        $size = shell_exec_error(($this->gm ? 'gm ' : '') . 'identify -format "%w %h" ' . escapeshellarg($src . '[0]'));
        if (preg_match('/^(\d+) (\d+)$/', $size, $m)) {
            return [$m[1], $m[2]];
        }
        return false;
    }

    public function from(): void
    {
        if ($this->width > 0 && $this->height > 0) {
            $this->image = true;
            return;
        }
        $size = $this->get_size($this->src, false);
        if ($size) {
            $this->width = $size[0];
            $this->height = $size[1];

            $this->image = true;
        } else {
            // mark as invalid
            $this->image = false;
        }
    }

    public function to(string $src): void
    {
        global $config;

        if (!$this->temp) {
            if ($config['strip_exif']) {
                if ($error = shell_exec_error(($this->gm ? 'gm ' : '') . 'convert ' .
                    escapeshellarg($this->src) . ' -auto-orient -strip ' . escapeshellarg($src))) {
                    $this->destroy();
                    error(_('Failed to redraw image!'), null, $error);
                }
            } else {
                if ($error = shell_exec_error(($this->gm ? 'gm ' : '') . 'convert ' .
                    escapeshellarg($this->src) . ' -auto-orient ' . escapeshellarg($src))) {
                    $this->destroy();
                    error(_('Failed to redraw image!'), null, $error);
                }
            }
        } else {
            rename($this->temp, $src);
            chmod($src, 0664);
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function destroy(): void
    {
        @unlink($this->temp);
        $this->temp = false;
    }

    public function resize(): void
    {
        global $config;

        if ($this->temp) {
            // remove old
            $this->destroy();
        }

        $this->temp = tempnam($config['tmp'], 'convert');

        $config['thumb_keep_animation_frames'] = (int) $config['thumb_keep_animation_frames'];

        if ($this->format === 'gif' && ($config['thumb_ext'] === 'gif' || $config['thumb_ext'] === '') && $config['thumb_keep_animation_frames'] > 1) {
            if ($this->gifsicle) {
                if (($error = shell_exec("gifsicle -w --unoptimize -O2 --resize {$this->width}x{$this->height} < " .
                    escapeshellarg($this->src . '') . " \"#0-{$config['thumb_keep_animation_frames']}\" -o " .
                    escapeshellarg($this->temp))) || !file_exists($this->temp)) {
                    $this->destroy();
                    error(_('Failed to resize image!'), null, $error);
                }
            } else {
                if ($config['convert_manual_orient'] && ($this->format === 'jpg' || $this->format === 'jpeg')) {
                    $convert_args = str_replace('-auto-orient', ImageConvert::jpeg_exif_orientation($this->src), $config['convert_args']);
                } elseif ($config['convert_manual_orient']) {
                    $convert_args = str_replace('-auto-orient', '', $config['convert_args']);
                } else {
                    $convert_args = $config['convert_args'];
                }
                if (($error = shell_exec_error(($this->gm ? 'gm ' : '') . 'convert ' .
                    sprintf(
                        $convert_args,
                        $this->width,
                        $this->height,
                        escapeshellarg($this->src),
                        $this->width,
                        $this->height,
                        escapeshellarg($this->temp),
                    ))) || !file_exists($this->temp)) {
                    $this->destroy();
                    error('Failed to resize image!', null, $error);
                }
                if ($size = $this->get_size($this->temp)) {
                    $this->width = $size[0];
                    $this->height = $size[1];
                }
            }
        } else {
            if ($config['convert_manual_orient'] && ($this->format === 'jpg' || $this->format === 'jpeg')) {
                $convert_args = str_replace('-auto-orient', ImageConvert::jpeg_exif_orientation($this->src), $config['convert_args']);
            } elseif ($config['convert_manual_orient']) {
                $convert_args = str_replace('-auto-orient', '', $config['convert_args']);
            } else {
                $convert_args = $config['convert_args'];
            }
            if (($error = shell_exec_error(($this->gm ? 'gm ' : '') . 'convert ' .
                sprintf(
                    $convert_args,
                    $this->width,
                    $this->height,
                    escapeshellarg($this->src . '[0]'),
                    $this->width,
                    $this->height,
                    escapeshellarg($this->temp),
                ))) || !file_exists($this->temp)) {
                $this->destroy();
                error('Failed to resize image!', null, $error);
            }
            if ($size = $this->get_size($this->temp)) {
                $this->width = $size[0];
                $this->height = $size[1];
            }
        }
    }

    // For when -auto-orient doesn't exist (older versions)
    public static function jpeg_exif_orientation(string $src, array|false $exif = false): string|false
    {
        if (!$exif) {
            $exif = @exif_read_data($src);
            if (!isset($exif['Orientation'])) {
                return false;
            }
        }
        switch ($exif['Orientation']) {
            case 1:
                // Normal
                return false;
            case 2:
                // 888888
                //     88
                //   8888
                //     88
                //     88
                return '-flop';
            case 3:
                //     88
                //     88
                //   8888
                //     88
                // 888888
                return '-flip -flop';
            case 4:
                // 88
                // 88
                // 8888
                // 88
                // 888888
                return '-flip';
            case 5:
                // 8888888888
                // 88  88
                // 88
                return '-rotate 90 -flop';
            case 6:
                // 88
                // 88  88
                // 8888888888
                return '-rotate 90';
            case 7:
                //         88
                //     88  88
                // 8888888888
                return '-rotate "-90" -flop';
            case 8:
                // 8888888888
                //     88  88
                //         88
                return '-rotate "-90"';
        }
        return false;
    }
}
