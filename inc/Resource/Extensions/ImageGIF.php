<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageGIF extends ImageBase
{
    /**
     * Create an image resource from a GIF file.
     *
     * @return void
     */
    public function from(): void
    {
        $this->image = @imagecreatefromgif($this->src);
    }

    /**
     * Save the current image resource to a GIF file.
     *
     * @param string $src Path to save the GIF file to.
     * @return void
     */
    public function to(string $src): void
    {
        imagegif($this->image, $src);
    }

    /**
     * Resize the image preserving GIF transparency.
     *
     * @return void
     */
    public function resize(): void
    {
        $this->GD_create();
        imagecolortransparent($this->image, imagecolorallocatealpha($this->image, 0, 0, 0, 0));
        imagesavealpha($this->image, true);
        $this->GD_copyresampled();
    }
}
