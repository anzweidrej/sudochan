<?php

use Sudochan\Service\BoardService;

$__boards = BoardService::listBoards();
$__default_boards = [];
foreach ($__boards as $__board) {
    $__default_boards[] = $__board['uri'];
}

$theme = [
    'name' => 'Catalog',
    'description' => 'Show a post catalog.',
    'version' => 'v0.1',
    'config' => [
        [
            'title' => 'Title',
            'name' => 'title',
            'type' => 'text',
            'default' => 'Catalog',
        ],
        [
            'title' => 'Included boards',
            'name' => 'boards',
            'type' => 'text',
            'comment' => '(space seperated)',
            'default' => implode(' ', $__default_boards),
        ],
        [
            'title' => 'CSS file',
            'name' => 'css',
            'type' => 'text',
            'default' => 'catalog.css',
            'comment' => '(eg. "catalog.css")',
        ],
        [
            'title' => 'Update on new posts',
            'name' => 'update_on_posts',
            'type' => 'checkbox',
            'default' => false,
            'comment' => 'Without this checked, the catalog only updates on new threads.',
        ],
    ],
    'build_function' => 'catalog_build',
];
