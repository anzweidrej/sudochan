<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Handler;

class ErrorHandler
{
    public static function basic_error_function_because_the_other_isnt_loaded_yet(string $message, int|bool $priority = true): void
    {
        global $config;

        if ($config['syslog'] && $priority !== false) {
            // Use LOG_NOTICE instead of LOG_ERR or LOG_WARNING because most error message are not significant.
            self::_syslog($priority !== true ? $priority : LOG_NOTICE, $message);
        }

        // Yes, this is horrible.
        die('<!DOCTYPE html><html><head><title>Error</title>' .
            '<style type="text/css">' .
                'body{text-align:center;font-family:arial, helvetica, sans-serif;font-size:10pt;}' .
                'p{padding:0;margin:20px 0;}' .
                'p.c{font-size:11px;}' .
            '</style></head>' .
            '<body><h2>Error</h2>' . $message . '<hr/>' .
            '<p class="c">This alternative error page is being displayed because the other couldn\'t be found or hasn\'t loaded yet.</p></body></html>');
    }

    public static function fatal_error_handler(): void
    {
        if ($error = error_get_last()) {
            if ($error['type'] == E_ERROR) {
                if (function_exists('error')) {
                    error('Caught fatal error: ' . $error['message'] . ' in <strong>' . $error['file'] . '</strong> on line ' . $error['line'], LOG_ERR);
                } else {
                    self::basic_error_function_because_the_other_isnt_loaded_yet('Caught fatal error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line'], LOG_ERR);
                }
            }
        }
    }

    public static function _syslog(int $priority, string $message): void
    {
        if (isset($_SERVER['REMOTE_ADDR'], $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'])) {
            // CGI
            syslog($priority, $message . ' - client: ' . $_SERVER['REMOTE_ADDR'] . ', request: "' . $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . '"');
        } else {
            syslog($priority, $message);
        }
    }

    public static function verbose_error_handler(int $errno, string $errstr, ?string $errfile, ?int $errline): bool
    {
        if (error_reporting() == 0) {
            return false;
        } // Looks like this warning was suppressed by the @ operator.
        error(utf8tohtml($errstr), true, [
            'file' => $errfile . ':' . $errline,
            'errno' => $errno,
            'error' => $errstr,
            'backtrace' => array_slice(debug_backtrace(), 1),
        ]);
    }
}
