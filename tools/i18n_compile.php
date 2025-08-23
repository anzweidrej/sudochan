#!/usr/bin/php
<?php

/*
 *  i18n_compile.php - compiles the i18n
 *
 *  Options:
 *    -l [locale], --locale=[locale]
 *      Compiles [locale] locale.
 *
 *  This script is based on code from vichan-devel/vichan
 *  Copyright (c) 2012-2018 vichan-devel
 *  Copyright (c) 2010-2014 Tinyboard Development Group (tinyboard.org)
 */

require dirname(__FILE__) . '/cli.php';

// parse command line
$opts = getopt('l:', ['locale:']);
$options = [];

$options['locale'] = isset($opts['l']) ? $opts['l'] : (isset($opts['locale']) ? $opts['locale'] : false);

if ($options['locale']) {
    $locales = [$options['locale']];
} else {
    die("Error: no locales specified; use -l switch, eg. -l pl_PL\n");
}

foreach ($locales as $loc) {
    if (file_exists($locdir = "locales/" . $loc)) {
        if (!is_dir($locdir)) {
            continue;
        }
    } else {
        die("Error: $locdir does not exist\n");
    }

    // Generate sudochan.po
    $poPath = "$locdir/LC_MESSAGES/sudochan.po";
    $moPath = "$locdir/LC_MESSAGES/sudochan.mo";
    if (file_exists($poPath)) {
        passthru("msgfmt \"$poPath\" -o \"$moPath\"");
    }

    // Generate javascript.po
    $poFile = "$locdir/LC_MESSAGES/javascript.po";
    $jsFile = "$locdir/LC_MESSAGES/javascript.js";
    if (file_exists($poFile)) {
        $loader = new Gettext\Loader\PoLoader();
        $translations = $loader->loadFile($poFile);
        $js = "var l10n = " . json_encode($translations->toArray('translation'), JSON_UNESCAPED_UNICODE) . ";";
        file_put_contents($jsFile, $js);
    }
}
