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

    public function GD_create(): void
    {
        $this->image = imagecreatetruecolor($this->width, $this->height);
    }

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

    public function GD_resize(): void
    {
        $this->GD_create();
        $this->GD_copyresampled();
    }
}
