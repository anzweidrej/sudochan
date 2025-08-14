<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

defined('TINYBOARD') or exit;

/**
 * Dispatch an event to registered handlers.
 * Returns the first non-empty handler result, or false if none.
 */
function event(string $event, mixed ...$args): mixed
{
    global $events;

    if (!isset($events[$event])) {
        return false;
    }

    foreach ($events[$event] as $callback) {
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

function event_handler(string $event, callable $callback): void
{
    global $events;

    if (!isset($events[$event])) {
        $events[$event] = [];
    }

    $events[$event][] = $callback;
}

function reset_events(): void
{
    global $events;
    $events = [];
}
