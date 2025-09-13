<?php

$theme = [
    'name' => 'Frameset',
    'description' => 'Use a basic frameset layout, with a list of boards and pages on a sidebar to the left of the page. Users never have to leave the homepage; they can do all their browsing from the one page.',
    'version' => 'v0.1',
    'config' => [
        [
            'title' => 'Site title',
            'name' => 'title',
            'type' => 'text',
        ],
        [
            'title' => 'Slogan',
            'name' => 'subtitle',
            'type' => 'text',
            'comment' => '(optional)',
        ],
        [
            'title' => 'Main HTML file',
            'name' => 'file_main',
            'type' => 'text',
            'default' => 'frames.html',
            'comment' => '(eg. "frames.html")',
        ],
        [
            'title' => 'Index file',
            'name' => 'file_index',
            'type' => 'text',
            'default' => 'main.html',
            'comment' => '(eg. "main.html")',
        ],
        [
            'title' => 'Sidebar file',
            'name' => 'file_sidebar',
            'type' => 'text',
            'default' => 'sidebar.html',
            'comment' => '(eg. "sidebar.html")',
        ],
        [
            'title' => 'CSS file',
            'name' => 'css',
            'type' => 'text',
            'default' => 'frames.css',
            'comment' => '(eg. "frames.css")',
        ],
    ],
    'build_function' => 'frameset_build',
];
