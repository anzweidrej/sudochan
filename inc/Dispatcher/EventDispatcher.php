<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Dispatcher;

defined('TINYBOARD') or exit;

class EventDispatcher
{
    private static array $events = [];

    /**
     * Dispatch an event to registered handlers.
     * Returns the first non-empty handler result, or false if none.
     */
    public static function event(string $event, mixed ...$args): mixed
    {
        if (!isset(self::$events[$event])) {
            return false;
        }

        foreach (self::$events[$event] as $callback) {
            if (!is_callable($callback)) {
                error('Event handler for ' . $event . ' is not callable!');
            }
            $error = call_user_func_array($callback, $args);
            if ($error) {
                return $error;
            }
        }

        return false;
    }

    public static function event_handler(string $event, callable $callback): void
    {
        if (!isset(self::$events[$event])) {
            self::$events[$event] = [];
        }

        self::$events[$event][] = $callback;
    }

    public static function reset_events(): void
    {
        self::$events = [];
    }
}
