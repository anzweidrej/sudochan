<?php

$theme = [];

// Theme name
$theme['name'] = 'RecentPosts';
// Description (you can use Tinyboard markup here)
$theme['description'] = 'Show recent posts and images, like 4chan.';
$theme['version'] = 'v1.0';

// Theme configuration
$theme['config'] = [];

$theme['config'][] = [
    'title' => 'Title',
    'name' => 'title',
    'type' => 'text',
    'default' => 'Recent Posts',
];

$theme['config'][] = [
    'title' => 'Excluded boards',
    'name' => 'exclude',
    'type' => 'text',
    'comment' => '(space seperated)',
];

$theme['config'][] = [
    'title' => '# of recent images',
    'name' => 'limit_images',
    'type' => 'text',
    'default' => '3',
    'comment' => '(maximum images to display)',
];

$theme['config'][] = [
    'title' => '# of recent posts',
    'name' => 'limit_posts',
    'type' => 'text',
    'default' => '30',
    'comment' => '(maximum posts to display)',
];

$theme['config'][] = [
    'title' => 'HTML file',
    'name' => 'html',
    'type' => 'text',
    'default' => 'recent.html',
    'comment' => '(eg. "recent.html")',
];

$theme['config'][] = [
    'title' => 'CSS file',
    'name' => 'css',
    'type' => 'text',
    'default' => 'recent.css',
    'comment' => '(eg. "recent.css")',
];

$theme['config'][] = [
    'title' => 'CSS stylesheet name',
    'name' => 'basecss',
    'type' => 'text',
    'default' => 'recent.css',
    'comment' => '(eg. "recent.css" - see templates/themes/recent for details)',
];

// Unique function name for building everything
$theme['build_function'] = 'recentposts_build';
$theme['install_callback'] = 'recentposts_install';

if (!function_exists('recentposts_install')) {
    /**
     * @param array<string, mixed> $settings
     * @return array{0: bool, 1: string}|null Returns [false, message] on failure, null on success
     */
    function recentposts_install(array $settings): ?array
    {
        if (!is_numeric($settings['limit_images']) || $settings['limit_images'] < 0) {
            return [false, '<strong>' . utf8tohtml($settings['limit_images']) . '</strong> is not a non-negative integer.'];
        }
        if (!is_numeric($settings['limit_posts']) || $settings['limit_posts'] < 0) {
            return [false, '<strong>' . utf8tohtml($settings['limit_posts']) . '</strong> is not a non-negative integer.'];
        }

        return null;
    }
}
