<?php

$theme = [];

// Theme name
$theme['name'] = 'Catalog';
// Description (you can use Tinyboard markup here)
$theme['description'] = 'Show a post catalog.';
$theme['version'] = 'v0.1';

// Theme configuration
$theme['config'] = [];

$theme['config'][] = [
    'title' => 'Title',
    'name' => 'title',
    'type' => 'text',
    'default' => 'Catalog',
];

$__boards = listBoards();
$__default_boards = [];
foreach ($__boards as $__board) {
    $__default_boards[] = $__board['uri'];
}

$theme['config'][] = [
    'title' => 'Included boards',
    'name' => 'boards',
    'type' => 'text',
    'comment' => '(space seperated)',
    'default' => implode(' ', $__default_boards),
];

$theme['config'][] = [
    'title' => 'CSS file',
    'name' => 'css',
    'type' => 'text',
    'default' => 'catalog.css',
    'comment' => '(eg. "catalog.css")',
];

$theme['config'][] = [
    'title' => 'Update on new posts',
    'name' => 'update_on_posts',
    'type' => 'checkbox',
    'default' => false,
    'comment' => 'Without this checked, the catalog only updates on new threads.',
];

// Unique function name for building everything
$theme['build_function'] = 'catalog_build';
