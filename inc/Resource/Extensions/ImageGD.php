<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

class ImageGD
{
    public $image;
    public $original;
    public int $width;
    public int $height;
    public int $original_width;
    public int $original_height;

    /**
     * Create a truecolor GD image resource with the configured width and height.
     *
     * @return void
     */
    public function GD_create(): void
    {
        $this->image = imagecreatetruecolor($this->width, $this->height);
    }

    /**
     * Copy and resample the original image into the working image resource.
     *
     * @return void
     */
    public function GD_copyresampled(): void
    {
        imagecopyresampled(
            $this->image,
            $this->original,
            0,
            0,
            0,
            0,
            $this->width,
            $this->height,
            $this->original_width,
            $this->original_height,
        );
    }

    /**
     * Convenience: create the working image and perform resampling from the original.
     *
     * @return void
     */
    public function GD_resize(): void
    {
        $this->GD_create();
        $this->GD_copyresampled();
    }
}
