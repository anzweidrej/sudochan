<?php

$theme = [];

// Theme name
$theme['name'] = 'Categories';
// Description (you can use Tinyboard markup here)
$theme['description'] = 'Group-ordered, category-aware modification of the Frameset theme, with removable sidebar frame. Requires $config[\'categories\'].';
$theme['version'] = 'v0.3';

// Theme configuration
$theme['config'] = [];

$theme['config'][] = [
    'title' => 'Site title',
    'name' => 'title',
    'type' => 'text',
];

$theme['config'][] = [
    'title' => 'Slogan',
    'name' => 'subtitle',
    'type' => 'text',
    'comment' => '(optional)',
];

$theme['config'][] = [
    'title' => 'Main HTML file',
    'name' => 'file_main',
    'type' => 'text',
    'default' => $config['file_index'],
    'comment' => '(eg. "index.html")',
];

$theme['config'][] = [
    'title' => 'Sidebar file',
    'name' => 'file_sidebar',
    'type' => 'text',
    'default' => 'sidebar.html',
    'comment' => '(eg. "sidebar.html")',
];

$theme['config'][] = [
    'title' => 'News file',
    'name' => 'file_news',
    'type' => 'text',
    'default' => 'news.html',
    'comment' => '(eg. "news.html")',
];

// Unique function name for building everything
$theme['build_function'] = 'categories_build';
$theme['install_callback'] = 'categories_install';

if (!function_exists('categories_install')) {
    /**
     * @param array<string, mixed> $settings
     * @return array{0: bool, 1: string}|null Returns [false, message] on failure, null on success
     */
    function categories_install(array $settings): ?array
    {
        global $config;

        if (!isset($config['categories'])) {
            return [false, '<h2>Prerequisites not met!</h2>' .
                'This theme requires $config[\'boards\'] and $config[\'categories\'] to be set.'];
        }

        return null;
    }
}
