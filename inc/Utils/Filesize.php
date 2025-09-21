<?php

namespace Sudochan\Utils;

class Filesize
{
    /**
     * Format bytes into a human-readable string (B, KB, MB, ...).
     *
     * @param int|float $size Size in bytes
     * @return string Formatted size with unit
     */
    public static function format_bytes(int|float $size): string
    {
        $units = [' B', ' KB', ' MB', ' GB', ' TB'];
        for ($i = 0; $size >= 1024 && $i < 4; $i++) {
            $size /= 1024;
        }
        return round($size, 2) . $units[$i];
    }
}
