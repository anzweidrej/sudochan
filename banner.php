<?php

$banner_dir = __DIR__ . '/static/banners/';

// Get all files in the banner directory
$files = scandir($banner_dir);

// Filter out '.' and '..' entries
$banners = array_values(array_filter($files, function ($f) use ($banner_dir) {
    // Only allow files with image extensions
    return $f !== '.' && $f !== '..'
        && is_file($banner_dir . $f)
        && preg_match('/\.(png|jpe?g|gif|webp)$/i', $f);
}));

// Pick a random banner
$banner_file = $banners[array_rand($banners)];
$banner_path = $banner_dir . $banner_file;

// Detect the MIME type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $banner_path);
finfo_close($finfo);

// Set headers to prevent caching
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Output the image file
header('Content-Type: ' . $mime);
readfile($banner_path);
