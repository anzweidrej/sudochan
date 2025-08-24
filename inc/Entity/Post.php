<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Entity;

class Post
{
    public int $id;
    public ?int $thread;
    public ?string $subject;
    public ?string $name;
    public string $body;
    public string $body_nomarkup;
    public ?string $embed;
    public array|bool $mod;
    public string $root;
    public array $modifiers;
    public bool $hr;
    public ?string $file;
    public ?string $thumb;
    public int $time;
    public ?int $filewidth = null;
    public ?int $fileheight = null;
    public ?string $email = null;
    public ?string $trip = null;
    public ?string $capcode = null;
    public ?int $bump = null;
    public ?int $thumbwidth = null;
    public ?int $thumbheight = null;
    public ?int $filesize = null;
    public ?string $filename = null;
    public ?string $filehash = null;
    public ?string $password = null;
    public ?string $ip = null;
    public ?bool $sticky = null;
    public ?bool $locked = null;
    public ?bool $sage = null;

    public function __construct(object|array $post, ?string $root = null, array|bool $mod = false)
    {
        global $config;
        if (!isset($root)) {
            $root = &$config['root'];
        }

        foreach ($post as $key => $value) {
            $this->{$key} = $value;
        }

        $this->subject = utf8tohtml($this->subject ?? '');
        $this->name = utf8tohtml($this->name ?? '');
        $this->mod = $mod;
        $this->root = $root;

        if ($this->embed) {
            $this->embed = embed_html($this->embed);
        }

        $this->modifiers = extract_modifiers($this->body_nomarkup);

        if ($config['always_regenerate_markup']) {
            $this->body = $this->body_nomarkup;
            markup($this->body);
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

    public function link(string $pre = ''): string
    {
        global $config, $board;

        return $this->root . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $this->thread) . '#' . $pre . $this->id;
    }

    public function postControls(): string
    {
        global $board, $config;

        $built = '';
        if ($this->mod) {
            // Mod controls (on posts)

            // Delete
            if (hasPermission($config['mod']['delete'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_delete'], 'Delete', 'Are you sure you want to delete this?', $board['dir'] . 'delete/' . $this->id);
            }

            // Delete all posts by IP
            if (hasPermission($config['mod']['deletebyip'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletebyip'], 'Delete all posts by IP', 'Are you sure you want to delete all posts by this IP address?', $board['dir'] . 'deletebyip/' . $this->id);
            }

            // Delete all posts by IP (global)
            if (hasPermission($config['mod']['deletebyip_global'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletebyip_global'], 'Delete all posts by IP across all boards', 'Are you sure you want to delete all posts by this IP address, across all boards?', $board['dir'] . 'deletebyip/' . $this->id . '/global');
            }

            // Ban
            if (hasPermission($config['mod']['ban'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Ban') . '" href="?/' . $board['dir'] . 'ban/' . $this->id . '">' . $config['mod']['link_ban'] . '</a>';
            }

            // Ban & Delete
            if (hasPermission($config['mod']['bandelete'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Ban & Delete') . '" href="?/' . $board['dir'] . 'ban&amp;delete/' . $this->id . '">' . $config['mod']['link_bandelete'] . '</a>';
            }

            // Delete file (keep post)
            if (!empty($this->file) && hasPermission($config['mod']['deletefile'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletefile'], _('Delete file'), _('Are you sure you want to delete this file?'), $board['dir'] . 'deletefile/' . $this->id);
            }

            // Spoiler file (keep post)
            if (!empty($this->file)  && $this->file != 'deleted' && $this->thumb != 'spoiler' && hasPermission($config['mod']['spoilerimage'], $board['uri'], $this->mod) && $config['spoiler_images']) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_spoilerimage'], 'Spoiler File', 'Are you sure you want to spoiler this file?', $board['uri'] . '/spoiler/' . $this->id);
            }

            // Edit post
            if (hasPermission($config['mod']['editpost'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Edit post') . '" href="?/' . $board['dir'] . 'edit' . ($config['mod']['raw_html_default'] ? '_raw' : '') . '/' . $this->id . '">' . $config['mod']['link_editpost'] . '</a>';
            }

            if (!empty($built)) {
                $built = '<span class="controls">' . $built . '</span>';
            }
        }
        return $built;
    }

    public function ratio(): string
    {
        return fraction($this->filewidth, $this->fileheight, ':');
    }

    public function build(bool $index = false): string
    {
        global $board, $config;

        return element('post_reply.html', ['config' => $config, 'board' => $board, 'post' => &$this, 'index' => $index]);
    }
}
