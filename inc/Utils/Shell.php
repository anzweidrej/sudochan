<?php

namespace Sudochan\Utils;

class Shell
{
    /**
     * Execute a shell command, capture output/errors and return trimmed output or false on success.
     *
     * @param string $command Command to run.
     * @param bool   $suppress_stdout If true, suppress stdout.
     * @return string|false Output (errors/diagnostics) or false on success.
     */
    public static function shell_exec_error(string $command, bool $suppress_stdout = false): string|false
    {
        global $config, $debug;

        if ($config['debug']) {
            $start = microtime(true);
        }

        $return = trim(shell_exec('PATH="' . escapeshellcmd($config['shell_path']) . ':$PATH";' .
            $command . ' 2>&1 ' . ($suppress_stdout ? '> /dev/null ' : '') . '&& echo "TB_SUCCESS"'));
        $return = preg_replace('/TB_SUCCESS$/', '', $return);

        if ($config['debug']) {
            $time = microtime(true) - $start;
            $debug['exec'][] = [
                'command' => $command,
                'time' => '~' . round($time * 1000, 2) . 'ms',
                'response' => $return ? $return : null,
            ];
            $debug['time']['exec'] += $time;
        }

        return $return === 'TB_SUCCESS' ? false : $return;
    }
}
