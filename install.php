<?php

/*
 *  Copyright (c) 2010-2013 Tinyboard Development Group
 */

// Installation/upgrade file
define('VERSION', 'v0.9.6-dev-22');

use Sudochan\Service\BoardService;
use Sudochan\Service\PageService;
use Sudochan\Manager\FileManager;

require_once 'bootstrap.php';

$step = isset($_GET['step']) ? round($_GET['step']) : 0;
$page = [
    'config' => $config,
    'title' => 'Install',
    'body' => '',
    'nojavascript' => true,
];

// this breaks the display of licenses if enabled
$config['minify_html'] = false;

if (file_exists($config['has_installed'])) {

    // Check the version number
    $version = trim(file_get_contents($config['has_installed']));
    if (empty($version)) {
        $version = 'v0.9.1';
    }

    function __query(string $sql): mixed
    {
        sql_open();

        if (mysql_version() >= 50503) {
            return query($sql);
        } else {
            return query(str_replace('utf8mb4', 'utf8', $sql));
        }
    }

    $boards = BoardService::listBoards();

    require_once __DIR__ . '/migrations.php';
    run_migrations($version, $boards, $page, $config);

    die(element('page.html', $page));
}

$installer = new class ($config, $page, $step) {
    private array $config;
    private array $page;
    private int $step;

    public function __construct(array $config, array $page, int $step)
    {
        $this->config = $config;
        $this->page = $page;
        $this->step = $step;
    }

    /**
     * Check whether a system command exists.
     *
     * @param string $cmd Command name.
     * @return bool True if the command is available.
     */
    private function commandExists(string $cmd): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            $where = @shell_exec('where ' . escapeshellarg($cmd) . ' 2>NUL');
            return !empty($where);
        } else {
            $ret = @shell_exec('command -v ' . escapeshellarg($cmd) . ' >/dev/null 2>&1 && echo ok');
            return trim((string) $ret) === 'ok';
        }
    }

    /**
     * Dispatch to the handler for the current step.
     *
     * @return void
     */
    public function dispatch(): void
    {
        $handlers = [
            0 => [$this, 'step0'],
            1 => [$this, 'step1'],
            2 => [$this, 'step2'],
            3 => [$this, 'step3'],
            4 => [$this, 'step4'],
            5 => [$this, 'step5'],
        ];

        if (isset($handlers[$this->step])) {
            $result = call_user_func($handlers[$this->step]);
            if (is_array($result)) {
                $this->page = array_merge($this->page, $result);
            }
            echo element('page.html', $this->page);
            return;
        }

        echo element('page.html', $this->page);
    }

    /**
     * Show license agreement.
     *
     * @return array Page data for rendering.
     */
    public function step0(): array
    {
        return [
            'body' => '<textarea style="width:700px;height:370px;margin:auto;display:block;background:white;color:black" disabled>' . htmlentities(file_get_contents('LICENSE')) . '</textarea>
            <p style="text-align:center">
                <a href="?step=1">I have read and understood the agreement. Proceed to installation.</a>
            </p>',
        ];
    }

    /**
     * Pre-installation checks.
     *
     * @return array Page data for rendering.
     */
    public function step1(): array
    {
        $can_exec = true;
        if (!function_exists('shell_exec')) {
            $can_exec = false;
        } elseif (in_array('shell_exec', array_map('trim', explode(', ', ini_get('disable_functions'))))) {
            $can_exec = false;
        } elseif (ini_get('safe_mode')) {
            $can_exec = false;
        } elseif (trim(@shell_exec('echo "TEST"')) !== 'TEST') {
            $can_exec = false;
        }

        if (!defined('PHP_VERSION_ID')) {
            $version = explode('.', PHP_VERSION);
            define('PHP_VERSION_ID', ($version[0] * 10000 + $version[1] * 100 + $version[2]));
        }

        // Required extensions
        $extensions = [
            'PDO' => [
                'installed' => extension_loaded('pdo'),
                'required' => true,
            ],
            'GD' => [
                'installed' => extension_loaded('gd'),
                'required' => true,
            ],
            'Imagick' => [
                'installed' => extension_loaded('imagick'),
                'required' => false,
            ],
            'OpenSSL' => [
                'installed' => extension_loaded('openssl'),
                'required' => true,
            ],
        ];

        $tests = [
            [
                'category' => 'PHP',
                'name' => 'PHP &ge; 8.3',
                'result' => PHP_VERSION_ID >= 80300,
                'required' => true,
                'message' => 'Sudochan requires PHP 8.3 or better.',
            ],
            [
                'category' => 'PHP',
                'name' => 'PHP &ge; 8.4',
                'result' => PHP_VERSION_ID >= 80400,
                'required' => false,
                'message' => 'PHP &ge; 8.4, though not required, is recommended to make the most out of Sudochan configuration files.',
            ],
            [
                'category' => 'PHP',
                'name' => 'mbstring extension installed',
                'result' => extension_loaded('mbstring'),
                'required' => true,
                'message' => 'You must install the PHP <a href="http://www.php.net/manual/en/mbstring.installation.php">mbstring</a> extension.',
            ],
            [
                'category' => 'PHP',
                'name' => 'OpenSSL extension installed',
                'result' => extension_loaded('openssl'),
                'required' => true,
                'message' => 'You must install the PHP <a href="https://www.php.net/manual/en/openssl.installation.php">OpenSSL</a> extension.',
            ],
            [
                'category' => 'Database',
                'name' => 'PDO extension installed',
                'result' => extension_loaded('pdo'),
                'required' => true,
                'message' => 'You must install the PHP <a href="http://www.php.net/manual/en/intro.pdo.php">PDO</a> extension.',
            ],
            [
                'category' => 'Database',
                'name' => 'MySQL PDO driver installed',
                'result' => extension_loaded('pdo') && in_array('mysql', \PDO::getAvailableDrivers()),
                'required' => true,
                'message' => 'The required <a href="http://www.php.net/manual/en/ref.pdo-mysql.php">PDO MySQL driver</a> is not installed.',
            ],
            [
                'category' => 'Image processing',
                'name' => 'GD extension installed',
                'result' => extension_loaded('gd'),
                'required' => true,
                'message' => 'You must install the PHP <a href="http://www.php.net/manual/en/intro.image.php">GD</a> extension. GD is a requirement even if you have chosen another image processor for thumbnailing.',
            ],
            [
                'category' => 'Image processing',
                'name' => 'GD: JPEG',
                'result' => function_exists('imagecreatefromjpeg'),
                'required' => true,
                'message' => 'imagecreatefromjpeg() does not exist. This is a problem.',
            ],
            [
                'category' => 'Image processing',
                'name' => 'GD: PNG',
                'result' => function_exists('imagecreatefrompng'),
                'required' => true,
                'message' => 'imagecreatefrompng() does not exist. This is a problem.',
            ],
            [
                'category' => 'Image processing',
                'name' => 'GD: GIF',
                'result' => function_exists('imagecreatefromgif'),
                'required' => true,
                'message' => 'imagecreatefromgif() does not exist. This is a problem.',
            ],
            [
                'category' => 'Image processing',
                'name' => 'Imagick extension installed',
                'result' => extension_loaded('imagick'),
                'required' => false,
                'message' => '(Optional) The PHP <a href="http://www.php.net/manual/en/imagick.installation.php">Imagick</a> (ImageMagick) extension is not installed. You may not use Imagick for better (and faster) image processing.',
            ],
            [
                'category' => 'Image processing',
                'name' => '`convert` (command-line ImageMagick)',
                'result' => $can_exec && $this->commandExists('convert'),
                'required' => false,
                'message' => '(Optional) `convert` was not found or executable; command-line ImageMagick image processing cannot be enabled.',
            ],
            [
                'category' => 'Image processing',
                'name' => '`identify` (command-line ImageMagick)',
                'result' => $can_exec && $this->commandExists('identify'),
                'required' => false,
                'message' => '(Optional) `identify` was not found or executable; command-line ImageMagick image processing cannot be enabled.',
            ],
            [
                'category' => 'Image processing',
                'name' => '`gm` (command-line GraphicsMagick)',
                'result' => $can_exec && $this->commandExists('gm'),
                'required' => false,
                'message' => '(Optional) `gm` was not found or executable; command-line GraphicsMagick (faster than ImageMagick) cannot be enabled.',
            ],
            [
                'category' => 'Image processing',
                'name' => '`gifsicle` (command-line animted GIF thumbnailing)',
                'result' => $can_exec && $this->commandExists('gifsicle'),
                'required' => false,
                'message' => '(Optional) `gifsicle` was not found or executable; you may not use `convert+gifsicle` for better animated GIF thumbnailing.',
            ],
            [
                'category' => 'File permissions',
                'name' => getcwd(),
                'result' => is_writable('.'),
                'required' => true,
                'message' => 'Sudochan does not have permission to create directories (boards) here. You will need to <code>chmod</code> (or operating system equivalent) appropriately.',
            ],
            [
                'category' => 'File permissions',
                'name' => getcwd() . '/tmp/cache',
                'result' => is_writable('tmp') && (!is_dir('tmp/cache') || is_writable('tmp/cache')),
                'required' => true,
                'message' => 'You must give Sudochan permission to create (and write to) the <code>tmp/cache</code> directory or performance will be drastically reduced.',
            ],
            [
                'category' => 'File permissions',
                'name' => getcwd() . 'instance-config.php',
                'result' => is_writable('instance-config.php'),
                'required' => false,
                'message' => 'Sudochan does not have permission to make changes to <code>instance-config.php</code>. To complete the installation, you will be asked to manually copy and paste code into the file instead.',
            ],
            [
                'category' => 'Misc',
                'name' => 'Caching available (APC, XCache, Memcached or Redis)',
                'result' => extension_loaded('apc') || extension_loaded('xcache')
                    || extension_loaded('memcached') || extension_loaded('redis'),
                'required' => false,
                'message' => 'You will not be able to enable the additional caching system, designed to minimize SQL queries and significantly improve performance. <a href="http://php.net/manual/en/book.apc.php">APC</a> is the recommended method of caching, but <a href="http://xcache.lighttpd.net/">XCache</a>, <a href="http://www.php.net/manual/en/intro.memcached.php">Memcached</a> and <a href="http://pecl.php.net/package/redis">Redis</a> are also supported.',
            ],
            [
                'category' => 'Misc',
                'name' => 'Sudochan installed using git',
                'result' => is_dir('.git'),
                'required' => false,
                'message' => 'Sudochan is still beta software and it\'s not going to come out of beta any time soon. As there are often many months between releases yet changes and bug fixes are very frequent, it\'s recommended to use the git repository to maintain your Sudochan installation. Using git makes upgrading much easier.',
            ],
        ];

        $this->config['font_awesome'] = true;

        return [
            'body' => element('installer/check-requirements.html', [
                'extensions' => $extensions,
                'tests' => $tests,
                'config' => $this->config,
            ]),
            'title' => 'Checking environment',
            'config' => $this->config,
        ];
    }

    /**
     * Present configuration form.
     *
     * @return array Page data for rendering.
     */
    public function step2(): array
    {
        $this->config['db'] = [
            'server'   => getenv('MYSQL_HOST') ?: 'mysql',
            'database' => getenv('MYSQL_DATABASE') ?: 'sudochan',
            'prefix'   => getenv('MYSQL_PREFIX') ?: '',
            'user'     => getenv('MYSQL_USER') ?: 'sudochan_user',
            'password' => getenv('MYSQL_PASSWORD') ?: 'userpassword',
        ];

        $this->config['cookies']['salt'] = substr(base64_encode(sha1(rand())), 0, 30);
        $this->config['secure_trip_salt'] = substr(base64_encode(sha1(rand())), 0, 30);

        return [
            'body' => element('installer/config.html', [
                'config' => $this->config,
            ]),
            'title' => 'Configuration',
            'config' => $this->config,
        ];
    }

    /**
     * Recursively build config string from an array.
     *
     * @param string $instance_config String accumulator.
     * @param array  $array           Configuration array.
     * @param string $prefix          Current prefix for nested keys.
     * @return void
     */
    private function create_config_from_array(string &$instance_config, array &$array, string $prefix = ''): void
    {
        foreach ($array as $k => $v) {
            $key = $prefix . '[' . var_export($k, true) . ']';
            if (is_array($v)) {
                $this->create_config_from_array($instance_config, $v, $key);
            } else {
                $instance_config .= '$config' . $key . ' = ' . var_export($v, true) . ";\n";
            }
        }
    }

    /**
     * Write instance-config.php.
     *
     * @return array Page data for rendering.
     */
    public function step3(): array
    {
        $instance_config = "<?php\n\n";

        $this->create_config_from_array($instance_config, $_POST);

        $instance_config .= "\n";

        if (@file_put_contents('instance-config.php', $instance_config, LOCK_EX) !== false) {
            @chmod('instance-config.php', 0644);
            header('Location: ?step=4', true, $this->config['redirect_http']);
            return [];
        } else {
            return [
                'title' => 'Manual installation required',
                'body' => '
                    <p>I couldn\'t write to <strong>instance-config.php</strong> with the new configuration, probably due to a permissions error.</p>
                    <p>Please complete the installation manually by copying and pasting the following code into the contents of <strong>instance-config.php</strong>:</p>
                    <textarea style="width:700px;height:370px;margin:auto;display:block;background:white;color:black">' . htmlentities($instance_config) . '</textarea>
                    <p style="text-align:center">
                        <a href="?step=4">Once complete, click here to complete installation.</a>
                    </p>
                ',
            ];
        }
    }

    /**
     * Execute SQL install and finalize.
     *
     * @return array Page data for rendering.
     */
    public function step4(): array
    {
        // SQL installation
        PageService::buildJavascript();

        $sql = @file_get_contents('install.sql') or error("Couldn't load install.sql.");

        sql_open();
        $mysql_version = mysql_version();

        // Parse SQL file by splitting on semicolons
        $queries = array_filter(array_map('trim', explode(';', $sql)));

        // Remove empty queries
        $queries = array_filter($queries, function ($q) {
            return $q !== '';
        });

        $queries[] = element('posts.sql', ['board' => 'b']);

        $sql_errors = '';
        foreach ($queries as $query) {
            // Ignore duplicates for boards and mods tables
            $query = preg_replace('/INSERT INTO\s+`(boards|mods)`/i', 'INSERT IGNORE INTO `$1`', $query);
            if ($mysql_version < 50503) {
                $query = preg_replace('/(CHARSET=|CHARACTER SET )utf8mb4/', '$1utf8', $query);
            }
            $query = preg_replace('/^([\w\s]*)`([0-9a-zA-Z$_\x{0080}-\x{FFFF}]+)`/u', '$1``$2``', $query);
            if (!query($query)) {
                $sql_errors .= '<li>' . db_error() . '</li>';
            }
        }

        $body = '<p style="text-align:center">Thank you for using Sudochan. Please remember to report any bugs you discover. <a href="http://tinyboard.org/docs/?p=Config">How do I edit the config files?</a></p>';

        if (!empty($sql_errors)) {
            $body .= '<div class="ban"><h2>SQL errors</h2><p>SQL errors were encountered when trying to install the database. This may be the result of using a database which is already occupied with a Sudochan installation; if so, you can probably ignore this.</p><p>The errors encountered were:</p><ul>' . $sql_errors . '</ul><p><a href="?step=5">Ignore errors and complete installation.</a></p></div>';
        } else {
            $boards = BoardService::listBoards();
            foreach ($boards as &$_board) {
                BoardService::setupBoard($_board);
                PageService::buildIndex();
            }

            FileManager::file_write($this->config['has_installed'], VERSION);
            $body .= '<div class="ban"><h2>Delete install.php!</h2><p>I couldn\'t remove <strong>install.php</strong>. You will have to remove it manually.</p></div>';
        }

        return [
            'title' => 'Installation complete',
            'body' => $body,
        ];
    }

    /**
     * Finalize and show completion.
     *
     * @return array Page data for rendering.
     */
    public function step5(): array
    {
        $body = '<p style="text-align:center">Thank you for using Sudochan. Please remember to report any bugs you discover.</p>';

        $boards = BoardService::listBoards();
        foreach ($boards as &$_board) {
            BoardService::setupBoard($_board);
            PageService::buildIndex();
        }

        FileManager::file_write($this->config['has_installed'], VERSION);
        $body .= '<div class="ban"><h2>Delete install.php!</h2><p>I couldn\'t remove <strong>install.php</strong>. You will have to remove it manually.</p></div>';

        return [
            'title' => 'Installation complete',
            'body' => $body,
        ];
    }
};

$installer->dispatch();
