<?php

$theme = [
    'name'        => 'Sitemap Generator',
    'description' => 'Generates an XML sitemap to help search engines find all threads and pages.',
    'version'     => 'v1.0',
    'config'      => [],
];

$theme['config'][] = [
    'title'   => 'Sitemap Path',
    'name'    => 'path',
    'type'    => 'text',
    'default' => 'sitemap.xml',
    'size'    => '20',
];

$theme['config'][] = [
    'title'   => 'URL prefix',
    'name'    => 'url',
    'type'    => 'text',
    'comment' => '(with trailing slash)',
    'default' => (isset($_SERVER['HTTP_HOST']) ? 'http://' . $_SERVER['HTTP_HOST'] . $config['root'] : ''),
    'size'    => '20',
];

$theme['config'][] = [
    'title'   => 'Thread change frequency',
    'name'    => 'changefreq',
    'type'    => 'text',
    'comment' => '(eg. "hourly", "daily", etc.)',
    'default' => 'hourly',
    'size'    => '20',
];

$theme['config'][] = [
    'title'   => 'Minimum time between regenerating',
    'name'    => 'regen_time',
    'type'    => 'text',
    'comment' => '(in seconds)',
    'default' => '0',
    'size'    => '8',
];

$__boards = listBoards();
$__default_boards = [];
foreach ($__boards as $__board) {
    $__default_boards[] = $__board['uri'];
}

$theme['config'][] = [
    'title'   => 'Boards',
    'name'    => 'boards',
    'type'    => 'text',
    'comment' => '(boards to include; space separated)',
    'size'    => 24,
    'default' => implode(' ', $__default_boards),
];

$theme['build_function'] = 'sitemap_build';
