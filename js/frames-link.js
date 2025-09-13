/*
 * frames-link.js - This script puts a frames link to options menu.
 *
 * Released under the MIT license
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/frames-link.js';
 */

$(document).ready(function frames() {
    var $li = $('li[data-cmd="set"][data-opt="frames"]');

    if (!$li.length) return;

    var $a = $('<a>').attr('href', '/frames.html').text('[Frames]');

    $li.empty().append($a);
});
