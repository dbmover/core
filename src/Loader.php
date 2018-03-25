<?php

namespace Dbmover\Core;

use PDO;
use PDOException;
use Dariuszp\CliProgressBar;
use Dbmover\Dbmover\Objects\Sql;

/**
 * The main Loader class. This represents a migration for a single unique DSN
 * from the `dbmover.json` config file.
 */
class Loader
{
    protected $schemas = [];
    protected $ignores = [];
    protected $errors = [];
    protected $dsn;
    protected $pdo;
    protected $operations = [];
    protected $vendor;
    protected $database;
    protected $plugins = [];
    protected $user;
    protected $settings;
    protected $silent = false;

    /**
     * Constructor.
     *
     * @param string $dsn The DSN string to connect with. Passed verbatim to
     *  PHP's `PDO` constructor.
     * @param array $settings Hash of settings read from `dbmover.json`. See
     *  README.md for further information on possible settings.
     * @param bool $silent If true, do not output anything. Defaults to false.
     */
    public function __construct(string $dsn, array $settings = [], bool $silent = false)
    {
        global $argv;
        $this->dsn = $dsn;
        $this->settings = $settings;
        $this->silent = $silent;
        preg_match('@^(\w+)?:@', $dsn, $vendor);
        $this->vendor = $vendor[1];
        preg_match('@dbname=(\w+)@', $dsn, $database);
        $this->database = $database[1];
        $user = $settings['user'] ?? null;
        $pass = $settings['pass'] ?? null;
        $options = $settings['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        if (isset($options['ignore']) && is_array($options['ignore'])) {
            $this->ignores = $options['ignore'];
            unset($options['ignore']);
        }
        $this->info("Starting migration for \033[0;35m{$this->database}\033[0;0m...");
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $this->notice(" `$dsn` for `$user` with password `$pass` is unavailable on this machine, skipping.");
            return;
        }
        $this->user = $user;
        $this->info("Loading plugins...");
        try {
            $this->loadPlugins(...($settings['plugins'] ?? []));
        } catch (PluginUnavailableException $e) {
            $plugin = $e->getMessage();
            $this->error("The plugin `$plugin` is not installed, did you `composer require` it? Skipping schema migration.");
            return;
        }
        $this->info("Loading requested schemas for {$this->database}...");
        if (!(isset($settings['schema']) && is_array($settings['schema']))) {
            $this->notice("`\"schema\"` for `$dsn` does not contain an array of file names, skipping.");
            return;
        }
        foreach ($settings['schema'] as $schema) {
            $this->addSchema($schema);
        }
        $this->info("Applying plugins to schemas...");
        $sql = implode("\n", $this->schemas);
        $this->errors = [];
        foreach ($this->plugins as $plugin) {
            $sql = $plugin($sql);
            $plugin->persist();
        }
        while ($plugin = array_shift($this->plugins)) {
            unset($plugin);
        }
        $stmts = [];
        foreach ($this->operations as $operation) {
            $stmts = array_merge($stmts, $this->sql(...$operation));
        }
        // Strip remaining comments
        $sql = preg_replace("@^--.*?$@m", '', $sql);
        // and superfluous whitespace
        $sql = preg_replace("@^\n{2,}@m", "\n", $sql);

        $left = trim($sql);
        if (strlen($left)) {
            $lines = count(explode("\n", $left));
            if ($lines == 1) {
                $this->notice("1 line of SQL was unhandled: \033[0;35m$left\033[0;0m");
            } else {
                $this->notice("$lines lines of SQL were unhandled:\n\033[0;35m$left\033[0;0m");
            }
        }
        if (!$this->errors) {
            $this->success("Migration for \033[0;35m{$this->database}\033[0;0m completed, 0 errors.");
        } else {
            $this->notice("Migration for \033[0;35m{$this->database}\033[0;0m completed, but errors were encountered.");
            foreach ($this->errors as $sql => $message) {
                $this->notice($sql);
                $this->error($message);
            }
        }
        if (!$this->silent) {
            fwrite(STDOUT, "\n");
        }
        if (isset($argv[1]) && $argv[1] == '--dry-run') {
            foreach ($stmts as $stmt) {
                fwrite(STDOUT, trim($stmt)."\n");
            }
        }
    }

    /**
     * Execute a batch of SQL statements and display feedback.
     *
     * @param string $description Description
     * @param array $sqls Array of SQL statements
     * @return array Array of SQL statements
     */
    public function sql(string $description, array $sqls) : array
    {
        global $argv;
        $description = trim($description);
        if (strlen($description) > 94) {
            $description = substr(preg_replace("@\s+@m", ' ', $description), 0, 94)." \033[0;33m[...]";
        }
        if (!$this->silent) {
            fwrite(STDOUT, "\033[0;36mSQL:\033[0;39m $description \033[0;37m  0%");
        }
        $error = false;
        $orig = count($sqls);
        $done = 0;
        $stmts = [];
        while ($sql = array_shift($sqls)) {
            $stmts[] = trim($sql);
            if (!(isset($argv[1]) && $argv[1] == '--dry-run')) {
                try {
                    $this->pdo->exec(trim($sql));
                } catch (PDOException $e) {
                    $this->errors[trim($sql)] = $e->getMessage();
                    $error = true;
                }
            }
            $done++;
            if (!$this->silent) {
                fwrite(STDOUT, sprintf(
                    "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;D%s%%",
                    str_pad(round($done / $orig * 100), 4, ' ', STR_PAD_LEFT)
                ));
            }
        }
        if (!$this->silent) {
            if ($error) {
                fwrite(STDOUT, "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;31m[Error]\033\n");
            } else {
                fwrite(STDOUT, "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;32m[Ok]\033\n");
            }
        }
        return $stmts;
    }

    /**
     * Expose the current PDO objecty.
     *
     * @return PDO
     */
    public function getPdo() : PDO
    {
        return $this->pdo;
    }

    /**
     * Expose the name of the current vendor.
     *
     * @return string
     */
    public function getVendor() : string
    {
        return $this->vendor;
    }

    /**
     * Expose the name of the current database.
     *
     * @return string
     */
    public function getDatabase() : string
    {
        return $this->database;
    }

    /**
     * Expose the name of the current database user.
     *
     * @return string
     */
    public function getUser() : string
    {
        return $this->user;
    }

    /**
     * Expose any errors encountered.
     *
     * @return array
     */
    public function getErrors() : array
    {
        return $this->errors;
    }

    /**
     * Attempt to load all requested plugins. A plugin may be defined either by
     * its Composer package name or a PSR-resolvable namespace (in both of which
     * cases, the classname must be `Plugin`), or a fully resolvable classname.
     *
     * Examples: `foo/bar`, `Foo\\Bar{\\Plugin}`, `Foo\\Bar\\CustomPlugin`
     *
     * @param string ...$plugins
     * @throws Dbmover\Core\PluginUnavailableException
     */
    public function loadPlugins(string ...$plugins)
    {
        foreach ($plugins as $plugin) {
            if (file_exists(getcwd()."/vendor/$plugin/src/Plugin.php")) {
                $src = file_get_contents(getcwd()."/vendor/$plugin/src/Plugin.php");
                $classname = false;
                if (preg_match("@class (\w+)@m", $src, $classname)) {
                    $classname = $classname[1];
                    if (preg_match("@namespace (\w|\\)+?;@m", $src, $namespace)) {
                        $classname = "\\$namespace[1]\\$classname";
                    } else {
                        $classname = "\\$classname";
                    }
                }
                if (!$classname || !class_exists($classname)) {
                    throw new PluginUnavailableException($plugin);
                }
                $this->addPlugin(new $classname($this));
            } elseif (class_exists("$plugin\\Plugin")) {
                $class = "$plugin\\Plugin";
                $this->addPlugin(new $class($this));
            } elseif (class_exists($plugin)) {
                $this->addPlugin(new $plugin($this));
            } else {
                throw new PluginUnavailableException($plugin);
            }
            $this->success("Loaded $plugin.");
        }
    }

    public function addPlugin(PluginInterface $plugin)
    {
        $this->plugins[] = $plugin;
    }

    /**
     * Add schema data from an SQL file.
     *
     * @param string $schema The filename containing the schema.
     */
    public function addSchema(string $schema)
    {
        $work = $schema;
        if ($work{0} != '/') {
            $work = getcwd()."/$work";
        }
        if (!file_exists($work)) {
            $this->notice("`\"$schema\"` for `{$this->database}` not found, skipping.");
            return;
        }
        $this->schemas[] = file_get_contents($work);
        $this->success("Loaded $schema.");
    }

    /**
     * Adds a batch of operations to the list.
     *
     * @param string $description Description.
     * @param array $sqls Array of SQL statements for the operation.
     */
    public function addOperation(string $description, array $sqls)
    {
        $this->operations[] = [$description, $sqls];
    }

    /**
     * Determine whether an object should be ignored as per config.
     *
     * @param string $name The name of the object to test.
     * @return bool
     */
    public function shouldBeIgnored($name) : bool
    {
        foreach ($this->ignores as $regex) {
            if (preg_match($regex, $name)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Display success feedback.
     *
     * @param string $msg
     * @return void
     */
    protected function success(string $msg) : void
    {
        if (!$this->silent) {
            fwrite(STDOUT, "\033[0;32mOk:\033[0;39m $msg\n");
        }
    }

    /**
     * Display notice feedback.
     *
     * @param string $msg
     * @return void
     */
    protected function notice(string $msg) : void
    {
        if (!$this->silent) {
            fwrite(STDOUT, "\033[0;33mNotice:\033[0;39m $msg\n");
        }
    }

    /**
     * Display error feedback.
     *
     * @param string $msg
     * @return void
     */
    protected function error(string $msg) : void
    {
        if (!$this->silent) {
            fwrite(STDERR, "\033[0;031mError:\033[0;39m $msg\n");
        }
    }

    /**
     * Display informational feedback.
     *
     * @param string $msg
     * @return void
     */
    protected function info(string $msg) : void
    {
        if (!$this->silent) {
            fwrite(STDOUT, "\033[0;34mInfo:\033[0;39m $msg\n");
        }
    }
}

