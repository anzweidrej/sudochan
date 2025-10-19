<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

use PhpMyAdmin\Twig\Extensions\I18nExtension;
use Sudochan\Security\Authenticator;
use Sudochan\Twig\TinyboardExtension;
use Sudochan\Twig\TinyboardRuntime;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\FactoryRuntimeLoader;
use Sudochan\Utils\StringFormatter;
use Sudochan\Handler\ErrorHandler;

$twig = false;

/*
 * Loads Twig and sets up the environment.
 */
function load_twig(): void
{
    global $twig, $config;

    $loader = new FilesystemLoader($config['dir']['template']);
    $twig = new Environment($loader, [
        'autoescape' => false,
        'cache' => (is_writable('tmp') && (!is_dir('tmp/cache') || is_writable('tmp/cache'))) ? 'tmp/cache' : false,
        'debug' => $config['debug'],
    ]);
    $twig->addExtension(new TinyboardExtension());
    $twig->addExtension(new I18nExtension());

    // Register runtime loader for TinyboardRuntime
    $twig->addRuntimeLoader(new FactoryRuntimeLoader([
        TinyboardRuntime::class => function () {
            return new TinyboardRuntime();
        },
    ]));
}

/*
 * Renders a template file with Twig.
 */
function element(string $templateFile, array $options): string
{
    global $config, $debug, $twig, $build_pages;

    if (!$twig) {
        load_twig();
    }

    if (
        class_exists('Auth')
        && method_exists('Auth', 'create_pm_header')
        && ((isset($options['mod']) && $options['mod']) || isset($options['__mod']))
        && !preg_match('!^mod/!', $templateFile)
    ) {
        $options['pm'] = Authenticator::create_pm_header();
    }

    if (isset($options['body']) && $config['debug']) {
        $_debug = $debug;

        if (isset($debug['start'])) {
            $_debug['time']['total'] = '~' . round((microtime(true) - $_debug['start']) * 1000, 2) . 'ms';
            $_debug['time']['init'] = '~' . round(($_debug['start_debug'] - $_debug['start']) * 1000, 2) . 'ms';
            unset($_debug['start'], $_debug['start_debug']);
        }
        if ($config['try_smarter'] && isset($build_pages) && !empty($build_pages)) {
            $_debug['build_pages'] = $build_pages;
        }
        $_debug['included'] = get_included_files();
        $_debug['memory'] = round(memory_get_usage(true) / (1024 * 1024), 2) . ' MiB';
        $_debug['time']['db_queries'] = '~' . round($_debug['time']['db_queries'] * 1000, 2) . 'ms';
        $_debug['time']['exec'] = '~' . round($_debug['time']['exec'] * 1000, 2) . 'ms';
        $options['body']
            .= '<h3>Debug</h3><pre style="white-space: pre-wrap;font-size: 10px;">'
            . str_replace("\n", '<br/>', StringFormatter::utf8tohtml(print_r($_debug, true)))
            . '</pre>';
    }

    // Read the template file
    if (@file_get_contents("{$config['dir']['template']}/{$templateFile}")) {
        $body = $twig->render($templateFile, $options);

        if ($config['minify_html'] && preg_match('/\.html$/', $templateFile)) {
            $body = trim(preg_replace("/[\t\r\n]/", '', $body));
        }

        return $body;
    } else {
        throw new \Exception("Template file '{$templateFile}' does not exist or is empty in '{$config['dir']['template']}'!");
    }
}

/*
 * Renders a moderator page.
 */
function mod_page(string $title, string $template, array $args, string|false $subtitle = false): void
{
    global $config, $mod;

    echo element(
        'page.html',
        [
            'config' => $config,
            'mod' => $mod,
            'hide_dashboard_link' => $template == 'mod/dashboard.html',
            'title' => $title,
            'subtitle' => $subtitle,
            'nojavascript' => true,
            'body' => element(
                $template,
                array_merge(
                    ['config' => $config, 'mod' => $mod],
                    $args,
                ),
            ),
        ],
    );
}

/*
 * Renders an error page.
 */
function error(string $message, bool|int $priority = true, mixed $debug_stuff = false): never
{
    global $board, $mod, $config, $db_error;

    if ($config['syslog'] && $priority !== false) {
        // Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
        ErrorHandler::_syslog($priority !== true ? $priority : LOG_NOTICE, $message);
    }

    if (defined('STDIN')) {
        // Running from CLI
        die('Error: ' . $message . "\n");
    }

    if ($config['debug'] && isset($db_error)) {
        $debug_stuff = array_combine(['SQLSTATE', 'Error code', 'Error message'], $db_error);
    }

    // Is there a reason to disable this?
    if (isset($_POST['json_response'])) {
        header('Content-Type: text/json; charset=utf-8');
        die(json_encode([
            'error' => $message,
        ]));
    }

    die(element('page.html', [
        'config' => $config,
        'title' => _('Error'),
        'subtitle' => _('An error has occured.'),
        'body' => element('error.html', [
            'config' => $config,
            'message' => $message,
            'mod' => $mod,
            'board' => isset($board) ? $board : false,
            'debug' => is_array($debug_stuff) ? str_replace("\n", '&#10;', StringFormatter::utf8tohtml(print_r($debug_stuff, true))) : StringFormatter::utf8tohtml($debug_stuff),
        ]),
    ]));
}
