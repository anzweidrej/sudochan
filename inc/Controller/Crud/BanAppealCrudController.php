<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Controller\Crud;

use Sudochan\Manager\BanManager as Bans;

class BanAppealCrudController
{
    public function executeBanAppeal(): void
    {
        global $config;

        if (!isset($_POST['ban_id'])) {
            error($config['error']['bot']);
        }

        $ban_id = (int) $_POST['ban_id'];

        $bans = Bans::find($_SERVER['REMOTE_ADDR']);
        foreach ($bans as $_ban) {
            if ($_ban['id'] == $ban_id) {
                $ban = $_ban;
                break;
            }
        }

        if (!isset($ban)) {
            error(_("That ban doesn't exist or is not for you."));
        }

        if ($ban['expires'] && $ban['expires'] - $ban['created'] <= $config['ban_appeals_min_length']) {
            error(_("You cannot appeal a ban of this length."));
        }

        $query = query("SELECT `denied` FROM ``ban_appeals`` WHERE `ban_id` = $ban_id") or error(db_error());
        $ban_appeals = $query->fetchAll(\PDO::FETCH_COLUMN);

        if (count($ban_appeals) >= $config['ban_appeals_max']) {
            error(_("You cannot appeal this ban again."));
        }

        foreach ($ban_appeals as $is_denied) {
            if (!$is_denied) {
                error(_("There is already a pending appeal for this ban."));
            }
        }

        $query = prepare("INSERT INTO ``ban_appeals`` VALUES (NULL, :ban_id, :time, :message, 0)");
        $query->bindValue(':ban_id', $ban_id, \PDO::PARAM_INT);
        $query->bindValue(':time', time(), \PDO::PARAM_INT);
        $query->bindValue(':message', $_POST['appeal']);
        $query->execute() or error(db_error($query));

        Bans::displayBan($ban);
    }
}
