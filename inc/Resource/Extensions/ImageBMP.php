<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageBMP extends ImageBase
{
    /**
     * Create an image resource from a BMP file.
     *
     * @return void
     */
    public function from(): void
    {
        $this->image = @imagecreatefrombmp($this->src);
    }

    /**
     * Save the current image resource to a BMP file.
     *
     * @param string $src Path to save the BMP file to.
     * @return void
     */
    public function to(string $src): void
    {
        imagebmp($this->image, $src);
    }
}
