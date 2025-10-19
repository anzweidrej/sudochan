<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageJPG extends ImageBase
{
    /**
     * Create an image resource from a JPEG file.
     *
     * @return void
     */
    public function from(): void
    {
        $this->image = @imagecreatefromjpeg($this->src);
    }

    /**
     * Save the current image resource to a JPEG file.
     *
     * @param string $src Path to save the JPEG file to.
     * @return void
     */
    public function to(string $src): void
    {
        imagejpeg($this->image, $src);
    }
}
