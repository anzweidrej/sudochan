<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageGIF extends ImageBase
{
    public function from(): void
    {
        $this->image = @imagecreatefromgif($this->src);
    }

    public function to(string $src): void
    {
        imagegif($this->image, $src);
    }

    public function resize(): void
    {
        $this->GD_create();
        imagecolortransparent($this->image, imagecolorallocatealpha($this->image, 0, 0, 0, 0));
        imagesavealpha($this->image, true);
        $this->GD_copyresampled();
    }
}
