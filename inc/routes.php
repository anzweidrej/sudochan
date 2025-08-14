<?php

return [
    ''                                  => ':?/',                        // redirect to dashboard
    '/'                                 => 'dashboard',                  // dashboard
    '/confirm/(.+)'                     => 'confirm',                    // confirm action (if javascript didn't work)
    '/logout'                           => 'secure logout',              // logout

    '/users'                            => 'users',                      // manage users
    '/users/(\d+)/(promote|demote)'     => 'secure user_promote',        // promote/demote user
    '/users/(\d+)'                      => 'secure_POST user',           // edit user
    '/users/new'                        => 'secure_POST user_new',       // create a new user

    '/new_PM/([^/]+)'                   => 'secure_POST new_pm',         // create a new pm
    '/PM/(\d+)(/reply)?'                => 'pm',                         // read a pm
    '/inbox'                            => 'inbox',                      // pm inbox

    '/log'                              => 'log',                        // modlog
    '/log/(\d+)'                        => 'log',                        // modlog
    '/log:([^/]+)'                      => 'user_log',                   // modlog
    '/log:([^/]+)/(\d+)'                => 'user_log',                   // modlog
    '/news'                             => 'secure_POST news',           // view news
    '/news/(\d+)'                       => 'secure_POST news',           // view news
    '/news/delete/(\d+)'                => 'secure news_delete',         // delete from news

    '/noticeboard'                      => 'secure_POST noticeboard',    // view noticeboard
    '/noticeboard/(\d+)'                => 'secure_POST noticeboard',    // view noticeboard
    '/noticeboard/delete/(\d+)'         => 'secure noticeboard_delete',  // delete from noticeboard

    '/edit/(\%b)'                       => 'secure_POST edit_board',     // edit board details
    '/new-board'                        => 'secure_POST new_board',      // create a new board

    '/rebuild'                          => 'secure_POST rebuild',        // rebuild static files
    '/reports'                          => 'reports',                    // report queue
    '/reports/(\d+)/dismiss(all)?'      => 'secure report_dismiss',      // dismiss a report

    '/IP/([\w.:]+)'                     => 'secure_POST ip',             // view ip address
    '/IP/([\w.:]+)/remove_note/(\d+)'   => 'secure ip_remove_note',      // remove note from ip address

    '/ban'                              => 'secure_POST ban',            // new ban
    '/bans'                             => 'secure_POST bans',           // ban list
    '/bans/(\d+)'                       => 'secure_POST bans',           // ban list
    '/ban-appeals'                      => 'secure_POST ban_appeals',    // view ban appeals

    '/search'                           => 'search_redirect',            // search
    '/search/(posts|IP_notes|bans|log)/(.+)/(\d+)' => 'search',          // search
    '/search/(posts|IP_notes|bans|log)/(.+)'       => 'search',          // search

    '/(\%b)/ban(&delete)?/(\d+)'        => 'secure_POST ban_post',       // ban poster
    '/(\%b)/move/(\d+)'                 => 'secure_POST move',           // move thread
    '/(\%b)/edit(_raw)?/(\d+)'          => 'secure_POST edit_post',      // edit post
    '/(\%b)/delete/(\d+)'               => 'secure delete',              // delete post
    '/(\%b)/deletefile/(\d+)'           => 'secure deletefile',          // delete file from post
    '/(\%b+)/spoiler/(\d+)'             => 'secure spoiler_image',       // spoiler file
    '/(\%b)/deletebyip/(\d+)(/global)?' => 'secure deletebyip',          // delete all posts by IP address
    '/(\%b)/(un)?lock/(\d+)'            => 'secure lock',                // lock thread
    '/(\%b)/(un)?sticky/(\d+)'          => 'secure sticky',              // sticky thread
    '/(\%b)/bump(un)?lock/(\d+)'        => 'secure bumplock',            // "bumplock" thread

    '/themes'                           => 'themes_list',                // manage themes
    '/themes/(\w+)'                     => 'secure_POST theme_configure',// configure/reconfigure theme
    '/themes/(\w+)/rebuild'             => 'secure theme_rebuild',       // rebuild theme
    '/themes/(\w+)/uninstall'           => 'secure theme_uninstall',     // uninstall theme

    '/config'                           => 'secure_POST config',         // config editor
    '/config/(\%b)'                     => 'secure_POST config',         // config editor

    // these pages aren't listed in the dashboard without $config['debug']
    '/debug/antispam'                   => 'debug_antispam',
    '/debug/recent'                     => 'debug_recent_posts',
    '/debug/apc'                        => 'debug_apc',
    '/debug/sql'                        => 'secure_POST debug_sql',
];
