<?php

$theme = [
    'name' => 'Index',
    'description' => 'Extremely basic imageboard homepage. Enabling boardlinks is recommended for this theme.',
    'version' => 'v0.1',
    'config' => [
        [
            'title' => 'Icon',
            'name' => 'icon',
            'type' => 'text',
            'default' => '../templates/themes/index/',
            'size' => 50,
        ],
        [
            'title' => 'Site title',
            'name' => 'title',
            'type' => 'text',
        ],
        [
            'title' => 'Description',
            'name' => 'description',
            'type' => 'textarea',
            'comment' => '(a short description of your website)',
        ],
        [
            'title' => 'Slogan',
            'name' => 'subtitle',
            'type' => 'text',
            'comment' => '(optional)',
        ],
        [
            'title' => '# of recent entries',
            'name' => 'no_recent',
            'type' => 'text',
            'default' => 0,
            'size' => 3,
            'comment' => '(number of recent news entries to display; "0" is infinite)',
        ],
        [
            'title' => 'Excluded boards',
            'name' => 'exclude',
            'type' => 'text',
            'comment' => '(space seperated)',
        ],
        [
            'title' => '# of recent images',
            'name' => 'limit_images',
            'type' => 'text',
            'default' => '3',
            'comment' => '(maximum images to display)',
        ],
        [
            'title' => '# of recent posts',
            'name' => 'limit_posts',
            'type' => 'text',
            'default' => '30',
            'comment' => '(maximum posts to display)',
        ],
        [
            'title' => 'HTML file',
            'name' => 'html',
            'type' => 'text',
            'default' => 'index.html',
            'comment' => '(eg. "index.html")',
        ],
        [
            'title' => 'CSS file',
            'name' => 'css',
            'type' => 'text',
            'default' => 'index.css',
            'comment' => '(eg. "index.css")',
        ],
    ],
    'build_function' => 'index_build',
    'install_callback' => 'build_install',
];

// Keep the install callback function as is
if (!function_exists('build_install')) {
    function build_install($settings)
    {
        if (!is_numeric($settings['no_recent']) || $settings['no_recent'] < 0) {
            return [false, '<strong>' . utf8tohtml($settings['no_recent']) . '</strong> is not a non-negative integer.'];
        }
        if (!is_numeric($settings['limit_images']) || $settings['limit_images'] < 0) {
            return [false, '<strong>' . utf8tohtml($settings['limit_images']) . '</strong> is not a non-negative integer.'];
        }
        if (!is_numeric($settings['limit_posts']) || $settings['limit_posts'] < 0) {
            return [false, '<strong>' . utf8tohtml($settings['limit_posts']) . '</strong> is not a non-negative integer.'];
        }
    }
}
