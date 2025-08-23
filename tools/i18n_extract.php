#!/usr/bin/php
<?php

/*
 *  i18n_extract.php - extracts the strings and updates all locales
 *
 *  Options:
 *    -l [locale], --locale=[locale]
 *      Updates only [locale] locale. If it does not exist yet, we create a new directory.
 *
 *  Examples:
 *    i18n_extract.php -l en_US
 *    i18n_extract.php --locale=fr_FR
 *
 *  This script is based on code from vichan-devel/vichan
 *  Copyright (c) 2012-2018 vichan-devel
 *  Copyright (c) 2010-2014 Tinyboard Development Group (tinyboard.org)
 */

require dirname(__FILE__) . '/cli.php';

// Parse command line
$opts = getopt('l:', ['locale:']);
$options = [];

$options['locale'] = isset($opts['l']) ? $opts['l'] : (isset($opts['locale']) ? $opts['locale'] : false);

$locales = glob("locales/*");
$locales = array_map("basename", $locales);

// Only allow locales matching ISO format (e.g., en_US, fr_FR)
$iso_pattern = '/^[a-z]{2}_[A-Z]{2}$/';

$locales = array_filter($locales, function ($loc) use ($iso_pattern) {
    return preg_match($iso_pattern, $loc);
});

if ($options['locale']) {
    if (preg_match($iso_pattern, $options['locale'])) {
        $locales = [$options['locale']];
    } else {
        fwrite(STDERR, "Locale must be in ISO format!\n");
        exit(1);
    }
}

foreach ($locales as $loc) {
    if (file_exists($locdir = "locales/" . $loc)) {
        if (!is_dir($locdir)) {
            continue;
        }
    } else {
        mkdir($locdir);
        mkdir($locdir . "/LC_MESSAGES", 0777, true);
    }

    // Generate messages.po
    $join = file_exists($locdir . "/LC_MESSAGES/sudochan.po") ? "-j" : "";
    $cmd = "cd $locdir/LC_MESSAGES && xgettext -d sudochan -L php --from-code=utf-8 $join -c $(find ../../../ -name '*.php')";
    passthru('bash -c "' . $cmd . '"');

    // Generate javascript.po
    $cmd = "cd $locdir/LC_MESSAGES && xgettext -d javascript -L Python --force-po --from-code=utf-8 $join -c $(find ../../../js/ ../../../templates/ -not -path '*node_modules*' -name '*.js')";
    passthru('bash -c "' . $cmd . '"');
}
