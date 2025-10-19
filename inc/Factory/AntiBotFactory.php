<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Factory;

use Sudochan\Security\AntiBot;

class AntiBotFactory
{
    /**
     * Create and persist an AntiBot instance.
     *
     * @param string $board Board identifier.
     * @param int|null $thread Thread ID or null for board-level.
     * @return AntiBot Newly created AntiBot instance.
     */
    public static function _create_antibot(string $board, ?int $thread): AntiBot
    {
        global $config, $purged_old_antispam;

        $antibot = new AntiBot([$board, $thread]);

        if (!isset($purged_old_antispam)) {
            $purged_old_antispam = true;
            query('DELETE FROM ``antispam`` WHERE `expires` < UNIX_TIMESTAMP()') or error(db_error());
        }

        if ($thread) {
            $query = prepare('UPDATE ``antispam`` SET `expires` = UNIX_TIMESTAMP() + :expires WHERE `board` = :board AND `thread` = :thread AND `expires` IS NULL');
        } else {
            $query = prepare('UPDATE ``antispam`` SET `expires` = UNIX_TIMESTAMP() + :expires WHERE `board` = :board AND `thread` IS NULL AND `expires` IS NULL');
        }

        $query->bindValue(':board', $board);
        if ($thread) {
            $query->bindValue(':thread', $thread);
        }
        $query->bindValue(':expires', $config['spam']['hidden_inputs_expire']);
        $query->execute() or error(db_error($query));

        $query = prepare('INSERT INTO ``antispam`` VALUES (:board, :thread, :hash, UNIX_TIMESTAMP(), NULL, 0)');
        $query->bindValue(':board', $board);
        $query->bindValue(':thread', $thread);
        $query->bindValue(':hash', $antibot->hash());
        $query->execute() or error(db_error($query));

        return $antibot;
    }

    /**
     * Public wrapper to create an AntiBot instance.
     *
     * @param string $board Board identifier.
     * @param int|null $thread Optional thread ID.
     * @return AntiBot Newly created AntiBot instance.
     */
    public static function create_antibot(string $board, ?int $thread = null): AntiBot
    {
        require_once __DIR__ . '/../AntiBot.php';

        return self::_create_antibot($board, $thread);
    }
}
