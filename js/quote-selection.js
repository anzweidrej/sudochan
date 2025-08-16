/*
 * quote-selection.js
 *
 * This is a little buggy.
 * Allows you to quote a post by just selecting some text, then beginning to type.
 *
 * Released under the MIT license
 * Copyright (c) 2012 Michael Save <savetheinternet@tinyboard.org>
 *
 * Usage:
 *   $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/quote-selection.js';
 *
 */

import $ from 'jquery';

$(document).ready(function () {
    if (!window.getSelection) return;

    $.fn.selectRange = function (start, end) {
        return this.each(function () {
            if (this.setSelectionRange) {
                this.focus();
                this.setSelectionRange(start, end);
            } else if (this.createTextRange) {
                var range = this.createTextRange();
                range.collapse(true);
                range.moveEnd('character', end);
                range.moveStart('character', start);
                range.select();
            }
        });
    };

    var altKey = false;
    var ctrlKey = false;
    var metaKey = false;

    $(document).on('keyup', function (e) {
        if (e.key === 'Alt') altKey = false;
        else if (e.key === 'Control') ctrlKey = false;
        else if (e.key === 'Meta') metaKey = false;
    });

    $(document).on('keydown', function (e) {
        if (e.altKey) altKey = true;
        else if (e.ctrlKey) ctrlKey = true;
        else if (e.metaKey) metaKey = true;

        if (altKey || ctrlKey || metaKey) {
            // console.log('CTRL/ALT/Something used. Ignoring');
            return;
        }

        // Only allow A-Z, 0-9 and common symbols
        if (!/^[a-zA-Z0-9]$/.test(e.key)) return;

        var selection = window.getSelection();
        var $post = $(selection.anchorNode).parents('.post');
        if ($post.length == 0) {
            // console.log('Start of selection was not post div', $(selection.anchorNode).parent());
            return;
        }

        var postID = $post.find('.post_no:eq(1)').text();

        if (
            postID !=
            $(selection.focusNode)
                .parents('.post')
                .find('.post_no:eq(1)')
                .text()
        ) {
            // console.log('Selection left post div', $(selection.focusNode).parent());
            return;
        }

        var selectedText = selection.toString();
        // console.log('Selected text: ' + selectedText.replace(/\n/g, '\\n').replace(/\r/g, '\\r'));

        if ($('body').hasClass('debug')) alert(selectedText);

        if (selectedText.length == 0) return;

        var body = $('textarea#body')[0];

        var last_quote = body.value.match(/[\S.]*(^|[\S\s]*)>>(\d+)/);
        if (last_quote) last_quote = last_quote[2];

        // to solve some bugs on weird browsers, we need to replace \r\n with \n and then undo that after
        var quote =
            (last_quote != postID ? '>>' + postID + '\r\n' : '') +
            selectedText
                .trim()
                .replace(/\r\n/g, '\n')
                .replace(/^/gm, '>')
                .replace(/\n/g, '\r\n') +
            '\r\n';

        // console.log('Deselecting text');
        selection.removeAllRanges();

        if (document.selection) {
            // IE
            body.focus();
            var sel = document.selection.createRange();
            sel.text = quote;
            body.focus();
        } else if (body.selectionStart || body.selectionStart == '0') {
            // Mozilla
            var start = body.selectionStart;
            var end = body.selectionEnd;

            if (!body.value.substring(0, start).match(/(^|\n)$/)) {
                quote = '\r\n\r\n' + quote;
            }

            body.value =
                body.value.substring(0, start) +
                quote +
                body.value.substring(end, body.value.length);
            $(body).selectRange(start + quote.length, start + quote.length);
        } else {
            // ???
            body.value += quote;
            body.focus();
        }
    });
});
