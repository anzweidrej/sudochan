<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class TinyboardExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('filesize', 'format_bytes'),
            new TwigFilter('truncate', [TinyboardRuntime::class, 'twig_truncate_filter']),
            new TwigFilter('truncate_body', 'truncate'),
            new TwigFilter('truncate_filename', [TinyboardRuntime::class, 'twig_filename_truncate_filter']),
            new TwigFilter('extension', [TinyboardRuntime::class, 'twig_extension_filter']),
            new TwigFilter('sprintf', [TinyboardRuntime::class, 'twig_sprintf_filter']),
            new TwigFilter('capcode', 'capcode'),
            new TwigFilter('hasPermission', [TinyboardRuntime::class, 'twig_hasPermission_filter']),
            new TwigFilter('date', [TinyboardRuntime::class, 'twig_date_filter']),
            new TwigFilter('poster_id', 'poster_id'),
            new TwigFilter('remove_whitespace', [TinyboardRuntime::class, 'twig_remove_whitespace_filter']),
            new TwigFilter('count', 'count'),
            new TwigFilter('ago', 'ago'),
            new TwigFilter('until', 'until'),
            new TwigFilter('push', [TinyboardRuntime::class, 'twig_push_filter']),
            new TwigFilter('bidi_cleanup', 'bidi_cleanup'),
            new TwigFilter('addslashes', 'addslashes'),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('time', 'time'),
            new TwigFunction('floor', 'floor'),
            new TwigFunction('timezone', [TinyboardRuntime::class, 'twig_timezone_function']),
            new TwigFunction('hiddenInputs', 'hiddenInputs'),
            new TwigFunction('hiddenInputsHash', 'hiddenInputsHash'),
        ];
    }

    public function getName(): string
    {
        return 'tinyboard';
    }
}
