/*
 * catalog-link.js - This script puts a link to the catalog below the board
 *                   subtitle and next to the board list.
 * https://github.com/vichan-devel/Tinyboard/blob/master/js/catalog-link.js
 *
 * Released under the MIT license
 * Copyright (c) 2013 copypaste <wizardchan@hush.com>
 * Copyright (c) 2013-2014 Marcin Łabanowski <marcin@6irc.net>
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/catalog-link.js';
 */

import $ from 'jquery';

$(document).ready(function catalog() {
    var board = $("input[name='board']");

    if (board) {
        var catalog_url = 'catalog.html';
        var pages = document.querySelector('.pages');
        var bottom = document.querySelector('.boardlist.bottom');
        var subtitle = document.querySelector('.subtitle');

        var link = document.createElement('a');
        link.href = catalog_url;

        if (pages) {
            link.textContent = 'Catalog';
            link.style.color = '#F10000';
            link.style.padding = '4px';
            link.style.paddingLeft = '9px';
            link.style.borderLeft = '1px solid';
            link.style.borderLeftColor = '#A8A8A8';
            link.style.textDecoration = 'underline';

            pages.appendChild(link);
        } else if (bottom) {
            link.textContent = '[Catalog]';
            link.style.paddingLeft = '10px';
            link.style.textDecoration = 'underline';
            document.body.insertBefore(link, bottom);
        }

        if (subtitle) {
            var link2 = document.createElement('a');
            link2.textContent = 'Catalog';
            link2.href = catalog_url;

            var br = document.createElement('br');
            subtitle.appendChild(br);
            subtitle.appendChild(link2);
        }
    }
});
