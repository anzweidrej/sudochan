<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan;

defined('TINYBOARD') or exit;

class Image
{
    public string $src;
    public string $format;
    public $image;
    public object $size;

    public function __construct(string $src, string|false $format = false, array|false $size = false)
    {
        global $config;

        $this->src = $src;
        $this->format = $format;

        if ($config['thumb_method'] === 'imagick') {
            $classname = 'ImageImagick';
        } elseif (in_array($config['thumb_method'], ['convert', 'convert+gifsicle', 'gm', 'gm+gifsicle'], true)) {
            $classname = 'ImageConvert';
        } else {
            $classname = 'Image' . strtoupper((string) $this->format);
            if (!class_exists($classname)) {
                error(_('Unsupported file format: ') . $this->format);
            }
        }

        $this->image = new $classname($this, $size);

        if (!$this->image->valid()) {
            $this->delete();
            error($config['error']['invalidimg']);
        }

        $this->size = (object) [
            'width' => $this->image->_width(),
            'height' => $this->image->_height(),
        ];
        if ($this->size->width < 1 || $this->size->height < 1) {
            $this->delete();
            error($config['error']['invalidimg']);
        }
    }

    public function resize(string $extension, int $max_width, int $max_height): ImageBase
    {
        global $config;

        $classname = null;
        $gifsicle = false;
        $gm = false;

        switch ($config['thumb_method']) {
            case 'imagick':
                $classname = 'ImageImagick';
                break;
            case 'convert':
                $classname = 'ImageConvert';
                break;
            case 'convert+gifsicle':
                $classname = 'ImageConvert';
                $gifsicle = true;
                break;
            case 'gm':
                $classname = 'ImageConvert';
                $gm = true;
                break;
            case 'gm+gifsicle':
                $classname = 'ImageConvert';
                $gm = true;
                $gifsicle = true;
                break;
            default:
                $classname = 'Image' . strtoupper((string) $extension);
                if (!class_exists($classname)) {
                    error(_('Unsupported file format: ') . $extension);
                }
        }

        $thumb = new $classname(false);
        $thumb->src = $this->src;
        $thumb->format = $this->format;
        $thumb->original_width = $this->size->width;
        $thumb->original_height = $this->size->height;

        $x_ratio = $max_width / $this->size->width;
        $y_ratio = $max_height / $this->size->height;

        if ($this->size->width <= $max_width && $this->size->height <= $max_height) {
            $width = $this->size->width;
            $height = $this->size->height;
        } elseif (($x_ratio * $this->size->height) < $max_height) {
            $height = (int) ceil($x_ratio * $this->size->height);
            $width = $max_width;
        } else {
            $width = (int) ceil($y_ratio * $this->size->width);
            $height = $max_height;
        }

        $thumb->_resize($this->image->image, $width, $height);

        return $thumb;
    }

    public function to(string $dst): void
    {
        $this->image->to($dst);
    }

    public function delete(): void
    {
        file_unlink($this->src);
    }

    public function destroy(): void
    {
        $this->image->_destroy();
    }
}

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

class ImageBase extends ImageGD
{
    public $image;
    public string $src;
    public string $format;
    public $original;
    public int $original_width;
    public int $original_height;
    public int $width;
    public int $height;

    public function valid(): bool
    {
        return (bool) $this->image;
    }

    public function __construct(Image|false $img, array|false $size = false)
    {
        if (method_exists($this, 'init')) {
            $this->init();
        }

        if ($size && $size[0] > 0 && $size[1] > 0) {
            $this->width = $size[0];
            $this->height = $size[1];
        }

        if ($img !== false) {
            $this->src = $img->src;
            $this->from();
        }
    }

    // fallback method to satisfy static analysis
    public function from(): void {}

    public function _width(): int
    {
        if (method_exists($this, 'width')) {
            return $this->width();
        }
        // use default GD functions
        return imagesx($this->image);
    }

    public function _height(): int
    {
        if (method_exists($this, 'height')) {
            return $this->height();
        }
        // use default GD functions
        return imagesy($this->image);
    }

    public function _destroy(): bool
    {
        if (method_exists($this, 'destroy')) {
            return $this->destroy();
        }
        // use default GD functions
        return imagedestroy($this->image);
    }

    public function _resize(mixed $original, int $width, int $height): void
    {
        $this->original = &$original;
        $this->width = $width;
        $this->height = $height;

        if (method_exists($this, 'resize')) {
            $this->resize();
        } else {
            // use default GD functions
            $this->GD_resize();
        }
    }
}

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

class ImagePNG extends ImageBase
{
    public function from(): void
    {
        $this->image = @imagecreatefrompng($this->src);
    }

    public function to(string $src): void
    {
        imagepng($this->image, $src);
    }

    public function resize(): void
    {
        $this->GD_create();
        imagecolortransparent($this->image, imagecolorallocatealpha($this->image, 0, 0, 0, 0));
        imagesavealpha($this->image, true);
        imagealphablending($this->image, false);
        $this->GD_copyresampled();
    }
}

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

class ImageJPEG extends ImageJPG {}

class ImageBMP extends ImageBase
{
    public function from(): void
    {
        $this->image = @_imagecreatefrombmp($this->src);
    }

    public function to(string $src): void
    {
        _imagebmp($this->image, $src);
    }
}

/*********************************************/
/* Fonction: imagecreatefrombmp              */
/* Author:   DHKold                          */
/* Contact:  admin@dhkold.com                */
/* Date:     The 15th of June 2005           */
/* Version:  2.0B                            */
/*********************************************/

function _imagecreatefrombmp(string $filename): mixed
{
    if (! $f1 = fopen($filename, "rb")) {
        return false;
    }
    $FILE = unpack("vfile_type/Vfile_size/Vreserved/Vbitmap_offset", fread($f1, 14));
    if ($FILE['file_type'] != 19778) {
        return false;
    }
    $BMP = unpack('Vheader_size/Vwidth/Vheight/vplanes/vbits_per_pixel' .
                  '/Vcompression/Vsize_bitmap/Vhoriz_resolution' .
                  '/Vvert_resolution/Vcolors_used/Vcolors_important', fread($f1, 40));
    $BMP['colors'] = pow(2, $BMP['bits_per_pixel']);
    if ($BMP['size_bitmap'] == 0) {
        $BMP['size_bitmap'] = $FILE['file_size'] - $FILE['bitmap_offset'];
    }
    $BMP['bytes_per_pixel'] = $BMP['bits_per_pixel'] / 8;
    $BMP['bytes_per_pixel2'] = ceil($BMP['bytes_per_pixel']);
    $BMP['decal'] = ($BMP['width'] * $BMP['bytes_per_pixel'] / 4);
    $BMP['decal'] -= floor($BMP['width'] * $BMP['bytes_per_pixel'] / 4);
    $BMP['decal'] = 4 - (4 * $BMP['decal']);
    if ($BMP['decal'] == 4) {
        $BMP['decal'] = 0;
    }

    $PALETTE = [];
    if ($BMP['colors'] < 16777216) {
        $PALETTE = unpack('V' . $BMP['colors'], fread($f1, $BMP['colors'] * 4));
    }

    $IMG = fread($f1, $BMP['size_bitmap']);
    $VIDE = chr(0);

    $res = imagecreatetruecolor($BMP['width'], $BMP['height']);
    $P = 0;
    $Y = $BMP['height'] - 1;
    while ($Y >= 0) {
        $X = 0;
        while ($X < $BMP['width']) {
            if ($BMP['bits_per_pixel'] == 24) {
                $COLOR = unpack("V", substr($IMG, $P, 3) . $VIDE);
            } elseif ($BMP['bits_per_pixel'] == 16) {
                $COLOR = unpack("n", substr($IMG, $P, 2));
                $COLOR[1] = $PALETTE[$COLOR[1] + 1];
            } elseif ($BMP['bits_per_pixel'] == 8) {
                $COLOR = unpack("n", $VIDE . substr($IMG, $P, 1));
                $COLOR[1] = $PALETTE[$COLOR[1] + 1];
            } elseif ($BMP['bits_per_pixel'] == 4) {
                $COLOR = unpack("n", $VIDE . substr($IMG, floor($P), 1));
                if (($P * 2) % 2 == 0) {
                    $COLOR[1] = ($COLOR[1] >> 4) ;
                } else {
                    $COLOR[1] = ($COLOR[1] & 0x0F);
                }
                $COLOR[1] = $PALETTE[$COLOR[1] + 1];
            } elseif ($BMP['bits_per_pixel'] == 1) {
                $COLOR = unpack("n", $VIDE . substr($IMG, floor($P), 1));
                if (($P * 8) % 8 == 0) {
                    $COLOR[1] =  $COLOR[1]        >> 7;
                } elseif (($P * 8) % 8 == 1) {
                    $COLOR[1] = ($COLOR[1] & 0x40) >> 6;
                } elseif (($P * 8) % 8 == 2) {
                    $COLOR[1] = ($COLOR[1] & 0x20) >> 5;
                } elseif (($P * 8) % 8 == 3) {
                    $COLOR[1] = ($COLOR[1] & 0x10) >> 4;
                } elseif (($P * 8) % 8 == 4) {
                    $COLOR[1] = ($COLOR[1] & 0x8) >> 3;
                } elseif (($P * 8) % 8 == 5) {
                    $COLOR[1] = ($COLOR[1] & 0x4) >> 2;
                } elseif (($P * 8) % 8 == 6) {
                    $COLOR[1] = ($COLOR[1] & 0x2) >> 1;
                } elseif (($P * 8) % 8 == 7) {
                    $COLOR[1] = ($COLOR[1] & 0x1);
                }
                $COLOR[1] = $PALETTE[$COLOR[1] + 1];
            } else {
                return false;
            }
            imagesetpixel($res, $X, $Y, $COLOR[1]);
            $X++;
            $P += $BMP['bytes_per_pixel'];
        }
        $Y--;
        $P += $BMP['decal'];
    }
    fclose($f1);

    return $res;
}

function _imagebmp(mixed &$img, string $filename = ''): void
{
    $widthOrig = imagesx($img);
    $widthFloor = ((floor($widthOrig / 16)) * 16);
    $widthCeil = ((ceil($widthOrig / 16)) * 16);
    $height = imagesy($img);

    $size = ($widthCeil * $height * 3) + 54;

    // Bitmap File Header
    $result = 'BM';	 // header (2b)
    $result .= int_to_dword($size); // size of file (4b)
    $result .= int_to_dword(0); // reserved (4b)
    $result .= int_to_dword(54); // byte location in the file which is first byte of IMAGE (4b)
    // Bitmap Info Header
    $result .= int_to_dword(40); // Size of BITMAPINFOHEADER (4b)
    $result .= int_to_dword($widthCeil); // width of bitmap (4b)
    $result .= int_to_dword($height); // height of bitmap (4b)
    $result .= int_to_word(1);	// biPlanes = 1 (2b)
    $result .= int_to_word(24); // biBitCount = {1 (mono) or 4 (16 clr ) or 8 (256 clr) or 24 (16 Mil)} (2b
    $result .= int_to_dword(0); // RLE COMPRESSION (4b)
    $result .= int_to_dword(0); // width x height (4b)
    $result .= int_to_dword(0); // biXPelsPerMeter (4b)
    $result .= int_to_dword(0); // biYPelsPerMeter (4b)
    $result .= int_to_dword(0); // Number of palettes used (4b)
    $result .= int_to_dword(0); // Number of important colour (4b)

    // is faster than chr()
    $arrChr = [];
    for ($i = 0; $i < 256; $i++) {
        $arrChr[$i] = chr($i);
    }

    // creates image data
    $bgfillcolor = ['red' => 0, 'green' => 0, 'blue' => 0];

    // bottom to top - left to right - attention blue green red !!!
    $y = $height - 1;
    for ($y2 = 0; $y2 < $height; $y2++) {
        for ($x = 0; $x < $widthFloor;) {
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
            $rgb = imagecolorsforindex($img, imagecolorat($img, $x++, $y));
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
        }
        for ($x = $widthFloor; $x < $widthCeil; $x++) {
            $rgb = ($x < $widthOrig) ? imagecolorsforindex($img, imagecolorat($img, $x, $y)) : $bgfillcolor;
            $result .= $arrChr[$rgb['blue']] . $arrChr[$rgb['green']] . $arrChr[$rgb['red']];
        }
        $y--;
    }

    // see imagegif
    if ($filename == '') {
        echo $result;
    } else {
        $file = fopen($filename, 'wb');
        fwrite($file, $result);
        fclose($file);
    }
}

// imagebmp helpers
function int_to_dword(int $n): string
{
    return chr($n & 255) . chr(($n >> 8) & 255) . chr(($n >> 16) & 255) . chr(($n >> 24) & 255);
}

function int_to_word(int $n): string
{
    return chr($n & 255) . chr(($n >> 8) & 255);
}
