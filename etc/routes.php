<?php

return [
    ''                                  => ':?/', // redirect to dashboard
    '/'                                 => 'DashboardController@mod_dashboard', // dashboard
    '/confirm/(.+)'                     => 'AuthController@mod_confirm', // confirm action (if javascript didn't work)
    '/logout'                           => 'secure AuthController@mod_logout', // logout

    '/users'                            => 'UserController@mod_users', // manage users
    '/users/(\d+)/(promote|demote)'     => 'secure UserController@mod_user_promote', // promote/demote user
    '/users/(\d+)'                      => 'secure_POST UserController@mod_user', // edit user
    '/users/new'                        => 'secure_POST UserController@mod_user_new', // create a new user

    '/new_PM/([^/]+)'                   => 'secure_POST PmController@mod_new_pm', // create a new pm
    '/PM/(\d+)(/reply)?'                => 'PmController@mod_pm', // read a pm
    '/inbox'                            => 'PmController@mod_inbox', // pm inbox

    '/log'                              => 'LogController@mod_log', // modlog
    '/log/(\d+)'                        => 'LogController@mod_log', // modlog
    '/log:([^/]+)'                      => 'LogController@mod_user_log', // modlog
    '/log:([^/]+)/(\d+)'                => 'LogController@mod_user_log', // modlog
    '/news'                             => 'secure_POST NewsController@mod_news', // view news
    '/news/(\d+)'                       => 'secure_POST NewsController@mod_news', // view news
    '/news/delete/(\d+)'                => 'secure NewsController@mod_news_delete', // delete from news

    '/noticeboard'                      => 'secure_POST NoticeboardController@mod_noticeboard', // view noticeboard
    '/noticeboard/(\d+)'                => 'secure_POST NoticeboardController@mod_noticeboard', // view noticeboard
    '/noticeboard/delete/(\d+)'         => 'secure NoticeboardController@mod_noticeboard_delete', // delete from noticeboard

    '/edit/(\%b)'                       => 'secure_POST BoardController@mod_edit_board', // edit board details
    '/new-board'                        => 'secure_POST BoardController@mod_new_board', // create a new board

    '/rebuild'                          => 'secure_POST DashboardController@mod_rebuild', // rebuild static files
    '/reports'                          => 'ReportController@mod_reports', // report queue
    '/reports/(\d+)/dismiss(all)?'      => 'secure ReportController@mod_report_dismiss', // dismiss a report

    '/IP/([\w.:]+)'                     => 'secure_POST IpNoteController@mod_ip', // view ip address
    '/IP/([\w.:]+)/remove_note/(\d+)'   => 'secure IpNoteController@mod_ip_remove_note', // remove note from ip address

    '/ban'                              => 'secure_POST BanController@mod_ban', // new ban
    '/bans'                             => 'secure_POST BanController@mod_bans', // ban list
    '/bans/(\d+)'                       => 'secure_POST BanController@mod_bans', // ban list

    '/ban-appeals'                      => 'secure_POST BanAppealsController@mod_ban_appeals', // view ban appeals

    '/search'                           => 'SearchController@mod_search_redirect', // search
    '/search/(posts|IP_notes|bans|log)/(.+)/(\d+)' => 'SearchController@mod_search', // search
    '/search/(posts|IP_notes|bans|log)/(.+)'       => 'SearchController@mod_search', // search

    '/(\%b)/ban(&delete)?/(\d+)'        => 'secure_POST PostController@mod_ban_post', // ban poster
    '/(\%b)/move/(\d+)'                 => 'secure_POST PostController@mod_move', // move thread
    '/(\%b)/edit(_raw)?/(\d+)'          => 'secure_POST PostController@mod_edit_post', // edit post
    '/(\%b)/delete/(\d+)'               => 'secure PostController@mod_delete', // delete post
    '/(\%b)/deletefile/(\d+)'           => 'secure PostController@mod_deletefile', // delete file from post
    '/(\%b+)/spoiler/(\d+)'             => 'secure PostController@mod_spoiler_image', // spoiler file
    '/(\%b)/deletebyip/(\d+)(/global)?' => 'secure PostController@mod_deletebyip', // delete all posts by IP address
    '/(\%b)/(un)?lock/(\d+)'            => 'secure PostController@mod_lock', // lock thread
    '/(\%b)/(un)?sticky/(\d+)'          => 'secure PostController@mod_sticky', // sticky thread
    '/(\%b)/bump(un)?lock/(\d+)'        => 'secure PostController@mod_bumplock', // "bumplock" thread

    '/themes'                           => 'ThemeController@mod_themes_list', // manage themes
    '/themes/(\w+)'                     => 'secure_POST ThemeController@mod_theme_configure', // configure/reconfigure theme
    '/themes/(\w+)/rebuild'             => 'secure ThemeController@mod_theme_rebuild', // rebuild theme
    '/themes/(\w+)/uninstall'           => 'secure ThemeController@mod_theme_uninstall', // uninstall theme

    '/config'                           => 'secure_POST ConfigController@mod_config', // config editor
    '/config/(\%b)'                     => 'secure_POST ConfigController@mod_config', // config editor

    // these pages aren't listed in the dashboard without $config['debug']
    '/debug/antispam'                   => 'DebugController@mod_debug_antispam',
    '/debug/recent'                     => 'DebugController@mod_debug_recent_posts',
    '/debug/apc'                        => 'DebugController@mod_debug_apc',
    '/debug/sql'                        => 'secure_POST DebugController@mod_debug_sql',
];
