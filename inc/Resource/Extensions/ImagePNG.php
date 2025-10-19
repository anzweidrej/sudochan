<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImagePNG extends ImageBase
{
    /**
     * Create an image resource from a PNG file.
     *
     * @return void
     */
    public function from(): void
    {
        $this->image = @imagecreatefrompng($this->src);
    }

    /**
     * Save the current image resource to a PNG file.
     *
     * @param string $src Path to save the PNG file to.
     * @return void
     */
    public function to(string $src): void
    {
        imagepng($this->image, $src);
    }

    /**
     * Resize the image preserving alpha transparency.
     *
     * @return void
     */
    public function resize(): void
    {
        $this->GD_create();
        imagecolortransparent($this->image, imagecolorallocatealpha($this->image, 0, 0, 0, 0));
        imagesavealpha($this->image, true);
        imagealphablending($this->image, false);
        $this->GD_copyresampled();
    }
}
