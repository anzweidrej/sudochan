<?php

namespace Sudochan;

use Sudochan\Utils\Token;
use Sudochan\Controller\AuthController;

class Dispatcher
{
    /**
     * Dispatch mod pages.
     *
     * @param array $pages
     * @param string $query
     * @param array $config
     * @param array|null &$debug
     * @param float|null $parse_start_time
     */
    public static function dispatch(array $pages, string $query, array $config, ?array &$debug = null, ?float $parse_start_time = null): void
    {
        foreach ($pages as $uri => $handler) {
            if (preg_match($uri, $query, $matches) === 1) {
                $matches = array_slice($matches, 1);

                if (isset($matches['board'])) {
                    $board_value = $matches['board'];
                    unset($matches['board']);
                    foreach ($matches as $k => $v) {
                        if ($v === $board_value) {
                            if (preg_match('/^' . sprintf(substr($config['board_path'], 0, -1), '(' . $config['board_regex'] . ')') . '$/u', $v, $board_match)) {
                                $matches[$k] = $board_match[1];
                            }
                            break;
                        }
                    }
                }

                if (is_string($handler) && preg_match('/^secure(_POST)? /', $handler, $m) === 1) {
                    $secure_post_only = isset($m[1]);
                    if (!$secure_post_only || $_SERVER['REQUEST_METHOD'] == 'POST') {
                        $token = isset($matches['token']) ? $matches['token'] : (isset($_POST['token']) ? $_POST['token'] : false);

                        if ($token === false) {
                            if ($secure_post_only) {
                                error($config['error']['csrf']);
                            } else {
                                AuthController::mod_confirm(substr($query, 1));
                                exit;
                            }
                        }

                        // CSRF-protected page; validate security token
                        $actual_query = preg_replace('!/([a-f0-9]{8})$!', '', $query);
                        if ($token !== Token::make_secure_link_token(substr($actual_query, 1))) {
                            error($config['error']['csrf']);
                        }
                    }
                    $handler = preg_replace('/^secure(_POST)? /', '', $handler);
                }

                if ($config['debug']) {
                    $debug['mod_page'] = [
                        'req' => $query,
                        'match' => $uri,
                        'handler' => $handler,
                    ];
                    $debug['time']['parse_mod_req'] = '~' . round((microtime(true) - $parse_start_time) * 1000, 2) . 'ms';
                }

                // Remove numeric keys from matches, as they are not needed
                $matches = array_values($matches);

                if (is_string($handler)) {
                    if ($handler !== '' && $handler[0] === ':') {
                        header('Location: ' . substr($handler, 1), true, $config['redirect_http']);
                    } elseif (strpos($handler, '@') !== false) {
                        list($class, $method) = explode('@', $handler, 2);
                        $fqcn = "Sudochan\\Controller\\$class";
                        if (class_exists($fqcn) && method_exists($fqcn, $method)) {
                            $instance = new $fqcn();
                            call_user_func_array([$instance, $method], $matches);
                        } else {
                            error("Controller '$fqcn@$method' not found!");
                        }
                    } elseif (is_callable("mod_page_$handler")) {
                        call_user_func_array("mod_page_$handler", $matches);
                    } elseif (is_callable("mod_$handler")) {
                        call_user_func_array("mod_$handler", $matches);
                    } else {
                        error("Mod page '$handler' not found!");
                    }
                } elseif (is_callable($handler)) {
                    call_user_func_array($handler, $matches);
                } else {
                    error("Mod page '$handler' not a string, and not callable!");
                }

                exit;
            }
        }

        error($config['error']['404']);
    }
}
