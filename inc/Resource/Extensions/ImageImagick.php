<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageImagick extends ImageBase
{
    public function init(): void
    {
        $this->image = new \Imagick();
        $this->image->setBackgroundColor(new \ImagickPixel('transparent'));
    }

    public function from(): void
    {
        try {
            $this->image->readImage($this->src);
        } catch (\ImagickException $e) {
            // invalid image
            $this->image = false;
        }
    }

    public function to(string $src): void
    {
        global $config;
        if ($config['strip_exif']) {
            $this->image->stripImage();
        }
        if (preg_match('/\.gif$/i', $src)) {
            $this->image->writeImages($src, true);
        } else {
            $this->image->writeImage($src);
        }
    }

    public function width(): int
    {
        return $this->image->getImageWidth();
    }

    public function height(): int
    {
        return $this->image->getImageHeight();
    }

    public function destroy(): bool
    {
        return $this->image->destroy();
    }

    public function resize(): void
    {
        global $config;

        if ($this->format === 'gif' && ($config['thumb_ext'] === 'gif' || $config['thumb_ext'] === '')) {
            $this->image = new \Imagick();
            $this->image->setFormat('gif');

            $keep_frames = [];
            $num_frames = $this->original->getNumberImages();
            for ($i = 0; $i < $num_frames; $i += (int) floor($num_frames / $config['thumb_keep_animation_frames'])) {
                $keep_frames[] = $i;
            }

            $i = 0;
            $delay = 0;
            foreach ($this->original as $frame) {
                $delay += $frame->getImageDelay();

                if (in_array($i, $keep_frames, true)) {
                    // $frame->scaleImage($this->width, $this->height, false);
                    $frame->sampleImage($this->width, $this->height);
                    $frame->setImagePage($this->width, $this->height, 0, 0);
                    $frame->setImageDelay($delay);
                    $delay = 0;

                    $this->image->addImage($frame->getImage());
                }
                $i++;
            }
        } else {
            $this->image = clone $this->original;
            $this->image->scaleImage($this->width, $this->height, false);
        }
    }
}
