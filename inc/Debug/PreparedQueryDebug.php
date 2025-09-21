<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

namespace Sudochan\Debug;

class PreparedQueryDebug
{
    protected \PDOStatement $query;
    protected \PDOStatement|false $explain_query = false;

    public function __construct(string $query)
    {
        global $pdo, $config;
        $query = preg_replace("/[\n\t]+/", ' ', $query);

        $this->query = $pdo->prepare($query);
        if (
            $config['debug']
            && $config['debug_explain']
            && preg_match('/^(SELECT|INSERT|UPDATE|DELETE) /i', $query)
        ) {
            $this->explain_query = $pdo->prepare("EXPLAIN $query");
        }
    }

    /**
     * Proxy to the wrapped PDOStatement.
     *
     * @param string $function
     * @param array  $args
     * @return mixed
     */
    public function __call(string $function, array $args): mixed
    {
        global $config, $debug;

        if ($config['debug'] && $function === 'execute') {
            if ($this->explain_query) {
                $this->explain_query->execute() or error(db_error($this->explain_query));
            }
            $start = microtime(true);
        }

        if ($this->explain_query && $function === 'bindValue') {
            call_user_func_array([$this->explain_query, $function], $args);
        }

        $return = call_user_func_array([$this->query, $function], $args);

        if ($config['debug'] && $function === 'execute') {
            $time = microtime(true) - $start;
            $debug['sql'][] = [
                'query'   => $this->query->queryString,
                'rows'    => $this->query->rowCount(),
                'explain' => $this->explain_query ? $this->explain_query->fetchAll(\PDO::FETCH_ASSOC) : null,
                'time'    => '~' . round($time * 1000, 2) . 'ms',
            ];
            $debug['time']['db_queries'] += $time;
        }

        return $return;
    }
}
