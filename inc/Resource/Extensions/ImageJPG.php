<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Resource\Extensions;

use Sudochan\Resource\ImageBase;

class ImageJPG extends ImageBase
{
    public function from(): void
    {
        $this->image = @imagecreatefromjpeg($this->src);
    }

    public function to(string $src): void
    {
        imagejpeg($this->image, $src);
    }
}
