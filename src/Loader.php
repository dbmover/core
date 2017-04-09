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
final class Loader
{
    public $schemas = [];
    public $ignores = [];
    protected $tables;

    private $pdo;
    private $operations = [];
    private $vendor;
    private $database;
    private $plugins = [];
    private $user;

    /**
     * Constructor.
     *
     * @param string $dsn The DSN string to connect with. Passed verbatim to
     *  PHP's `PDO` constructor.
     * @param array $settings Hash of settings read from `dbmover.json`. See
     *  README.md for further information on possible settings.
     */
    public function __construct(string $dsn, array $settings = [])
    {
        preg_match('@^(\w+)?:@', $dsn, $vendor);
        $this->vendor = $vendor[1];
        preg_match('@dbname=(\w+)@', $dsn, $database);
        $this->database = $database[1];
        $user = $settings['user'] ?? null;
        $pass = $settings['pass'] ?? null;
        $options = $settings['options'] ?? [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $this->info("Starting migration for \033[0;35m{$this->database}\033[0;0m...");
        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            $this->notice(" `$dsn` for `$user` with password `$pass` is unavailable on this machine, skipping.");
            return;
        }
        if (isset($options['ignore']) && is_array($options['ignore'])) {
            $this->ignores = $options['ignore'];
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
        }
        while ($plugin = array_shift($this->plugins)) {
            unset($plugin);
        }
        $hooks = $settings['hooks'] ?? [];
        if (isset($hooks['pre'])) {
            $this->info('Executing pre hook...');
            exec($hooks['pre']);
        }
        foreach ($this->operations as $operation) {
            if (is_array($operation)) {
                list($operation, $hr) = $operation;
            } else {
                $hr = $operation;
            }
            $this->sql($operation, $hr);
        }
        // Strip remaining comments:
        $sql = preg_replace("@^--.*?$@", '', $sql);

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
            if (isset($hooks['post'])) {
                $this->info('Executing post hook...');
                exec($hooks['post']);
            }
            $this->success("Migration for \033[0;35m{$this->database}\033[0;0m completed, 0 errors.");
        } else {
            if (isset($hooks['rollback'])) {
                $this->info('Executing rollback hook...');
                exec($hooks['rollback']);
            }
            $this->notice("Migration for \033[0;35m{$this->database}\033[0;0m completed, but errors were encountered:\n"
                ."\033[0;31m".implode("\n", $this->errors)."\033[0;0m");
        }
        fwrite(STDOUT, "\n");
    }

    /**
     * Display success feedback.
     *
     * @param string $msg
     */
    public function success(string $msg)
    {
        fwrite(STDOUT, "\033[0;32mOk:\033[0;39m $msg\n");
    }

    /**
     * Display notice feedback.
     *
     * @param string $msg
     */
    public function notice(string $msg)
    {
        fwrite(STDOUT, "\033[0;33mNotice:\033[0;39m $msg\n");
    }

    /**
     * Display error feedback.
     *
     * @param string $msg
     */
    public function error(string $msg)
    {
        fwrite(STDERR, "\033[0;031mError:\033[0;39m $msg\n");
    }

    /**
     * Display informational feedback.
     *
     * @param string $msg
     */
    public function info(string $msg)
    {
        fwrite(STDOUT, "\033[0;34mInfo:\033[0;39m $msg\n");
    }

    /**
     * Execute and display SQL feedback.
     *
     * @param string $sql
     * @param string $hr Human readable form
     */
    public function sql(string $sql, string $hr)
    {
        $hr = trim($hr);
        if (strlen($hr) > 94) {
            $hr = substr(preg_replace("@\s+@m", ' ', $hr), 0, 94)." \033[0;33m[...]";
        }
        fwrite(STDOUT, "\033[0;36mSQL:\033[0;39m $hr ");
        try {
            $this->pdo->exec(trim($sql));
            fwrite(STDOUT, "\033 [0;32m[Ok]\033\n");
        } catch (PDOException $e) {
            $this->errors[trim($sql)] = $e->getMessage();
            fwrite(STDOUT, "\033 [0;31m[Error]\033\n");
        }
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
            if (file_exists(getcwd()."/vendor/$plugin/Plugin.php")) {
                $src = file_get_contents(getcwd()."/vendor/$plugin/Plugin.php");
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
                $this->plugins[] = new $classname($this);
            } elseif (class_exists("$plugin\\Plugin")) {
                $class = "$plugin\\Plugin";
                $this->plugins[] = new $class($this);
            } elseif (class_exists($plugin)) {
                $this->plugins[] = new $plugin($this);
            } else {
                throw new PluginUnavailableException($plugin);
            }
            $this->success("Loaded $plugin.");
        }
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
     * Adds an operation to the list.
     *
     * @param string $sql The SQL for the operation.
     * @param string $hr Optional human readable form.
     */
    public function addOperation(string $sql, string $hr = null)
    {
        $this->operations[] = isset($hr) ? [$sql, $hr] : $sql;
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
}

