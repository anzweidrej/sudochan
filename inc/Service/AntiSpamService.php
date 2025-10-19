<?php

namespace Sudochan\Service;

use Sudochan\Utils\Obfuscation;
use Sudochan\Manager\FilterManager as Filter;

class AntiSpamService
{
    /**
     * Validate posted anti-spam hash against reconstructed inputs and DB record.
     *
     * @param array $extra_salt Optional extra salt parts to include in the hash.
     * @return bool|string Returns the valid hash string, or true/false-like sentinel on failure.
     */
    public static function checkSpam(array $extra_salt = []): bool|string
    {
        global $config, $pdo;

        if (!isset($_POST['hash'])) {
            return true;
        }

        $hash = $_POST['hash'];

        if (!empty($extra_salt)) {
            // create a salted hash of the "extra salt"
            $extra_salt = implode(':', $extra_salt);
        } else {
            $extra_salt = '';
        }

        // Reconstruct the $inputs array
        $inputs = [];

        foreach ($_POST as $name => $value) {
            if (in_array($name, $config['spam']['valid_inputs'])) {
                continue;
            }
            $inputs[$name] = $value;
        }

        // Sort the inputs in alphabetical order (A-Z)
        ksort($inputs);

        $_hash = '';
        // Iterate through each input
        foreach ($inputs as $name => $value) {
            $_hash .= $name . '=' . $value;
        }

        // Add a salt to the hash
        $_hash .= $config['cookies']['salt'];
        // Use SHA1 for the hash
        $_hash = sha1($_hash . $extra_salt);

        if ($hash != $_hash) {
            return true;
        }

        $query = prepare('SELECT `passed` FROM ``antispam`` WHERE `hash` = :hash');
        $query->bindValue(':hash', $hash);
        $query->execute() or error(db_error($query));
        $passed = $query->fetchColumn(0);

        if ($passed === false || $passed > $config['spam']['hidden_inputs_max_pass']) {
            // there was no database entry for this hash. most likely expired.
            return true;
        }

        return $hash;
    }

    /**
     * Increment the 'passed' counter for an anti-spam hash.
     *
     * @param string $hash The anti-spam hash to increment.
     * @return void
     */
    public static function incrementSpamHash(string $hash): void
    {
        $query = prepare('UPDATE ``antispam`` SET `passed` = `passed` + 1 WHERE `hash` = :hash');
        $query->bindValue(':hash', $hash);
        $query->execute() or error(db_error($query));
    }

    /**
     * Purge old entries from the flood prevention table based on configured cache time.
     *
     * @return void
     */
    public static function purge_flood_table(): void
    {
        global $config;

        // Determine how long we need to keep a cache of posts for flood prevention. Unfortunately, it is not
        // aware of flood filters in other board configurations. You can solve this problem by settings the
        // config variable $config['flood_cache'] (seconds).

        if (isset($config['flood_cache'])) {
            $max_time = &$config['flood_cache'];
        } else {
            $max_time = 0;
            foreach ($config['filters'] as $filter) {
                if (isset($filter['condition']['flood-time'])) {
                    $max_time = max($max_time, $filter['condition']['flood-time']);
                }
            }
        }

        $time = time() - $max_time;

        query("DELETE FROM ``flood`` WHERE `time` < $time") or error(db_error());
    }

    /**
     * Run configured filters against a post and apply their actions.
     *
     * @param array $post The post data to check.
     * @return void
     */
    public static function do_filters(array $post): void
    {
        global $config;

        if (!isset($config['filters']) || empty($config['filters'])) {
            return;
        }

        $has_flood = false;
        foreach ($config['filters'] as $filter) {
            if (isset($filter['condition']['flood-match'])) {
                $has_flood = true;
                break;
            }
        }

        if ($has_flood) {
            if ($post['has_file']) {
                $query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash OR `filehash` = :filehash");
                $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
                $query->bindValue(':posthash', Obfuscation::make_comment_hex($post['body_nomarkup']));
                $query->bindValue(':filehash', $post['filehash']);
            } else {
                $query = prepare("SELECT * FROM ``flood`` WHERE `ip` = :ip OR `posthash` = :posthash");
                $query->bindValue(':ip', $_SERVER['REMOTE_ADDR']);
                $query->bindValue(':posthash', Obfuscation::make_comment_hex($post['body_nomarkup']));
            }
            $query->execute() or error(db_error($query));
            $flood_check = $query->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $flood_check = false;
        }

        foreach ($config['filters'] as $filter_array) {
            $filter = new Filter($filter_array);
            $filter->flood_check = $flood_check;
            if ($filter->check($post)) {
                $filter->action();
            }
        }

        self::purge_flood_table();
    }
}
