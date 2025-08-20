<?php

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

    public function __construct(object|array $post, ?string $root = null, array|bool $mod = false, bool $hr = true)
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
        $this->hr = $hr;

        $this->posts = [];
        $this->omitted = 0;
        $this->omitted_images = 0;

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

        return $this->root . $board['dir'] . $config['dir']['res'] . sprintf($config['file_page'], $this->id) . '#' . $pre . $this->id;
    }

    public function add(Post $post): void
    {
        $this->posts[] = $post;
    }

    public function postControls(): string
    {
        global $board, $config;

        $built = '';
        if ($this->mod) {
            // Mod controls (on posts)
            // Delete
            if (hasPermission($config['mod']['delete'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_delete'], _('Delete'), _('Are you sure you want to delete this?'), $board['dir'] . 'delete/' . $this->id);
            }

            // Delete all posts by IP
            if (hasPermission($config['mod']['deletebyip'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletebyip'], _('Delete all posts by IP'), _('Are you sure you want to delete all posts by this IP address?'), $board['dir'] . 'deletebyip/' . $this->id);
            }

            // Delete all posts by IP (global)
            if (hasPermission($config['mod']['deletebyip_global'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletebyip_global'], _('Delete all posts by IP across all boards'), _('Are you sure you want to delete all posts by this IP address, across all boards?'), $board['dir'] . 'deletebyip/' . $this->id . '/global');
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
            if (!empty($this->file) && $this->file != 'deleted' && hasPermission($config['mod']['deletefile'], $board['uri'], $this->mod)) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_deletefile'], _('Delete file'), _('Are you sure you want to delete this file?'), $board['dir'] . 'deletefile/' . $this->id);
            }

            // Spoiler file (keep post)
            if (!empty($this->file)  && $this->file != 'deleted' && $this->thumb != 'spoiler' && hasPermission($config['mod']['spoilerimage'], $board['uri'], $this->mod) && $config['spoiler_images']) {
                $built .= ' ' . secure_link_confirm($config['mod']['link_spoilerimage'], 'Spoiler File', 'Are you sure you want to spoiler this file?', $board['uri'] . '/spoiler/' . $this->id);
            }

            // Sticky
            if (hasPermission($config['mod']['sticky'], $board['uri'], $this->mod)) {
                if ($this->sticky) {
                    $built .= ' <a title="' . _('Make thread not sticky') . '" href="?/' . secure_link($board['dir'] . 'unsticky/' . $this->id) . '">' . $config['mod']['link_desticky'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Make thread sticky') . '" href="?/' . secure_link($board['dir'] . 'sticky/' . $this->id) . '">' . $config['mod']['link_sticky'] . '</a>';
                }
            }

            if (hasPermission($config['mod']['bumplock'], $board['uri'], $this->mod)) {
                if ($this->sage) {
                    $built .= ' <a title="' . _('Allow thread to be bumped') . '" href="?/' . secure_link($board['dir'] . 'bumpunlock/' . $this->id) . '">' . $config['mod']['link_bumpunlock'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Prevent thread from being bumped') . '" href="?/' . secure_link($board['dir'] . 'bumplock/' . $this->id) . '">' . $config['mod']['link_bumplock'] . '</a>';
                }
            }

            // Lock
            if (hasPermission($config['mod']['lock'], $board['uri'], $this->mod)) {
                if ($this->locked) {
                    $built .= ' <a title="' . _('Unlock thread') . '" href="?/' . secure_link($board['dir'] . 'unlock/' . $this->id) . '">' . $config['mod']['link_unlock'] . '</a>';
                } else {
                    $built .= ' <a title="' . _('Lock thread') . '" href="?/' . secure_link($board['dir'] . 'lock/' . $this->id) . '">' . $config['mod']['link_lock'] . '</a>';
                }
            }

            if (hasPermission($config['mod']['move'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Move thread to another board') . '" href="?/' . $board['dir'] . 'move/' . $this->id . '">' . $config['mod']['link_move'] . '</a>';
            }

            // Edit post
            if (hasPermission($config['mod']['editpost'], $board['uri'], $this->mod)) {
                $built .= ' <a title="' . _('Edit post') . '" href="?/' . $board['dir'] . 'edit' . ($config['mod']['raw_html_default'] ? '_raw' : '') . '/' . $this->id . '">' . $config['mod']['link_editpost'] . '</a>';
            }

            if (!empty($built)) {
                $built = '<span class="controls op">' . $built . '</span>';
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
        global $board, $config, $debug;

        event('show-thread', $this);

        $built = element('post_thread.html', ['config' => $config, 'board' => $board, 'post' => &$this, 'index' => $index]);

        return $built;
    }
}
