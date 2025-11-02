<?php

/*
 *  Copyright (c) 2010-2014 Tinyboard Development Group
 */

use Sudochan\Controller\Crud\{BanAppealCrudController, PostCrudController, ReportCrudController};

require_once 'bootstrap.php';

$handler = new class {
    /**
     * Dispatches the incoming request to the appropriate handler
     * based on POST parameters.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $actions = [
            'delete'  => [PostCrudController::class, 'executeDelete'],
            'report'  => [ReportCrudController::class, 'executeReport'],
            'post'    => [PostCrudController::class, 'executePost'],
            'appeal'  => [BanAppealCrudController::class, 'executeBanAppeal'],
        ];

        foreach ($actions as $key => $callable) {
            if (isset($_POST[$key])) {
                [$class, $method] = $callable;
                (new $class())->{$method}();
                return;
            }
        }

        $this->executeDefault();
    }

    /**
     * Default handler when no action is matched.
     *
     * @return void
     */
    private function executeDefault(): void
    {
        global $config;

        if (!file_exists($config['has_installed'])) {
            header('Location: install.php', true, $config['redirect_http']);
        } else {
            // They opened post.php in their browser manually.
            error($config['error']['nopost']);
        }
    }
};

$handler->dispatch();
