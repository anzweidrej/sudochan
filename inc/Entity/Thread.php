<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Entity;

use Sudochan\Dispatcher\EventDispatcher;
use Sudochan\Entity\Post;
use Sudochan\Manager\PermissionManager;
use Sudochan\Service\MarkupService;
use Sudochan\Utils\{Math, TextFormatter, StringFormatter, Token, LinkBuilder, Sanitize};

class Thread
{
    public ?int $thread;
    public ?string $subject;
    public string $email;
    public string $name;
    public ?string $trip;
    public ?string $capcode;
    public string $body;
    public string $body_nomarkup;
    public int $time;
    public int $bump;
    public string $thumb;
    public int $thumbwidth;
    public int $thumbheight;
    public string $file;
    public int $filewidth;
    public int $fileheight;
    public int $filesize;
    public string $filename;
    public string $filehash;
    public string $password;
    public string $ip;
    public bool $sticky;
    public bool $locked;
    public bool $sage;
    public ?string $embed;
    public array|bool $mod;
    public string $root;
    public bool $hr;
    /** @var Post[] */
    public array $posts;
    public int $omitted;
    public int $omitted_images;
    public array $modifiers;
    public int $id;

    /**
     * Construct a Thread object from a data row.
     *
     * @param object|array $post Row data.
     * @param string|null $root Base root URL.
     * @param array|bool $mod Moderator context or false.
     * @param bool $hr Whether to show horizontal rule when rendering.
     */
    public function __construct(object|array $post, ?string $root = null, array|bool $mod = false, bool $hr = true)
    {
        global $config;
        if (!isset($root)) {
            $root = &$config['root'];
        }

        foreach ($post as $key => $value) {
            $this->{$key} = $value;
        }

        $this->subject = StringFormatter::utf8tohtml($this->subject ?? '');
        $this->name = StringFormatter::utf8tohtml($this->name ?? '');
        $this->mod = $mod;
        $this->root = $root;
        $this->hr = $hr;

        $this->posts = [];
        $this->omitted = 0;
        $this->omitted_images = 0;

        if ($this->embed) {
            $this->embed = LinkBuilder::embed_html($this->embed);
        }

        $this->modifiers = Sanitize::extract_modifiers($this->body_nomarkup);

        if ($config['always_regenerate_markup']) {
            $this->body = $this->body_nomarkup;
            MarkupService::markup($this->body);
        }

        if ($this->mod) {
            // Fix internal links
            // Very complicated regex
            $this->body = preg_replace(
                '/<a((([a-zA-Z]+="[^"]+")|[a-zA-Z]+=[a-zA-Z]+|\s)*)href="' . preg_quote($config['root'], '/') . '(' . sprintf(preg_quote($config['board_path'], '/'), $config['board_regex']) . ')/u',
                '<a $1href="?/$4',
                $this->body,
            );
        }
    }

    /**
     * Build a link to this thread.
     *
     * @param string $pre Prefix for the anchor id.
     * @return string Full URL to the thread anchor.
     */
    public function link(string $pre = ''): string
    {
        global $config, $board;

        return $this->root . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $this->id) . '#' . $pre . $this->id;
    }

    /**
     * Add a reply Post to this thread.
     *
     * @param Post $post Reply post object
     */
    public function add(Post $post): void
    {
        $this->posts[] = $post;
    }

    /**
     * Generate moderator controls for the thread.
     *
     * @return string HTML fragment with mod controls or empty string.
     */
    public function postControls(): string
    {
        global $board, $config;

        $built = '';
        if ($this->mod) {
            // Mod controls (on posts)
            // Delete
            if (PermissionManager::hasPermission($config['mod']['delete'], $board['uri'], $this->mod)) {
                $built .= ' ' . Token::secure_link_confirm($config['mod']['link_delete'], _('Delete'), _('Are you sure you want to delete this?'), $board['dir'] . 'delete/' . $this->id);
            }

            // Delete all posts by IP
            if (PermissionManager::hasPermission($config['mod']['deletebyip'], $board['uri'], $this->mod)) {
                $built .= ' ' . Token::secure_link_confirm($config['mod']['link_deletebyip'], _('Delete all posts by IP'), _('Are you sure you want to delete all posts by this IP address?'), $board['dir'] . 'deletebyip/' . $this->id);
            }

            // Delete all posts by IP (global)
            if (PermissionManager::hasPermission($config['mod']['deletebyip_global'], $board['uri'], $this->mod)) {
                $built .= ' ' . Token::secure_link_confirm($config['mod']['link_deletebyip_global'], _('Delete all posts by IP across all boards'), _('Are you sure you want to delete all posts by this IP address, across all boards?'), $board['dir'] . 'deletebyip/' . $this->id . '/global');
            }

            // Ban
            if (PermissionManager::hasPermission($config['mod']['ban'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Ban') . '" href="?/' . $board['dir'] . 'ban/' . $this->id . '">' . $config['mod']['link_ban'] . '</a>';
            }

            // Ban & Delete
            if (PermissionManager::hasPermission($config['mod']['bandelete'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Ban & Delete') . '" href="?/' . $board['dir'] . 'ban&amp;delete/' . $this->id . '">' . $config['mod']['link_bandelete'] . '</a>';
            }

            // Delete file (keep post)
            if (!empty($this->file) && $this->file != 'deleted' && PermissionManager::hasPermission($config['mod']['deletefile'], $board['uri'], $this->mod)) {
                $built .= ' ' . Token::secure_link_confirm($config['mod']['link_deletefile'], _('Delete file'), _('Are you sure you want to delete this file?'), $board['dir'] . 'deletefile/' . $this->id);
            }

            // Spoiler file (keep post)
            if (!empty($this->file)  && $this->file != 'deleted' && $this->thumb != 'spoiler' && PermissionManager::hasPermission($config['mod']['spoilerimage'], $board['uri'], $this->mod) && $config['spoiler_images']) {
                $built .= ' ' . Token::secure_link_confirm($config['mod']['link_spoilerimage'], 'Spoiler File', 'Are you sure you want to spoiler this file?', $board['uri'] . '/spoiler/' . $this->id);
            }

            // Sticky
            if (PermissionManager::hasPermission($config['mod']['sticky'], $board['uri'], $this->mod)) {
                if ($this->sticky) {
                    $built .= ' <a title="' . _('Make thread not sticky') . '" href="?/' . Token::secure_link($board['dir'] . 'unsticky/' . $this->id) . '">' . $config['mod']['link_desticky'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Make thread sticky') . '" href="?/' . Token::secure_link($board['dir'] . 'sticky/' . $this->id) . '">' . $config['mod']['link_sticky'] . '</a>';
                }
            }

            if (PermissionManager::hasPermission($config['mod']['bumplock'], $board['uri'], $this->mod)) {
                if ($this->sage) {
                    $built .= ' <a title="' . _('Allow thread to be bumped') . '" href="?/' . Token::secure_link($board['dir'] . 'bumpunlock/' . $this->id) . '">' . $config['mod']['link_bumpunlock'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Prevent thread from being bumped') . '" href="?/' . Token::secure_link($board['dir'] . 'bumplock/' . $this->id) . '">' . $config['mod']['link_bumplock'] . '</a>';
                }
            }

            // Lock
            if (PermissionManager::hasPermission($config['mod']['lock'], $board['uri'], $this->mod)) {
                if ($this->locked) {
                    $built .= ' <a title="' . _('Unlock thread') . '" href="?/' . Token::secure_link($board['dir'] . 'unlock/' . $this->id) . '">' . $config['mod']['link_unlock'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Lock thread') . '" href="?/' . Token::secure_link($board['dir'] . 'lock/' . $this->id) . '">' . $config['mod']['link_lock'] . '</a>';
                }
            }

            if (PermissionManager::hasPermission($config['mod']['move'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Move thread to another board') . '" href="?/' . $board['dir'] . 'move/' . $this->id . '">' . $config['mod']['link_move'] . '</a>';
            }

            // Edit post
            if (PermissionManager::hasPermission($config['mod']['editpost'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Edit post') . '" href="?/' . $board['dir'] . 'edit' . ($config['mod']['raw_html_default'] ? '_raw' : '') . '/' . $this->id . '">' . $config['mod']['link_editpost'] . '</a>';
            }

            if (!empty($built)) {
                $built = '<span class="controls op">' . $built . '</span>';
            }
        }
        return $built;
    }

    /**
     * Return file aspect ratio as a string.
     *
     * @return string Ratio in "w:h" format or empty string if unknown.
     */
    public function ratio(): string
    {
        return Math::fraction($this->filewidth, $this->fileheight, ':');
    }

    /**
     * Render the thread using templates.
     *
     * @param bool $index True when rendering on index page.
     * @return string HTML for the thread.
     */
    public function build(bool $index = false): string
    {
        global $board, $config, $debug;

        EventDispatcher::event('show-thread', $this);

        $built = element('post_thread.html', ['config' => $config, 'board' => $board, 'post' => &$this, 'index' => $index]);

        return $built;
    }
}
