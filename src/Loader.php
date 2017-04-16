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

    private $dsn;
    private $pdo;
    private $operations = [];
    private $vendor;
    private $database;
    private $plugins = [];
    private $user;
    private $description = 'DbMoving';
    private $settings;

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
        $this->dsn = $dsn;
        $this->settings = $settings;
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
            $plugin->persist();
        }
        while ($plugin = array_shift($this->plugins)) {
            unset($plugin);
        }
        $hooks = $settings['hooks'] ?? [];
        if (isset($hooks['pre'])) {
            $this->info('Executing pre hook...');
            $this->executeHook($hooks['pre']);
        }
        foreach ($this->operations as $operation) {
            $this->sql(...$operation);
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
            if (isset($hooks['post'])) {
                $this->info('Executing post hook...');
                $this->executeHook($hooks['post']);
            }
            $this->success("Migration for \033[0;35m{$this->database}\033[0;0m completed, 0 errors.");
        } else {
            if (isset($hooks['rollback'])) {
                $this->info('Executing rollback hook...');
                $this->executeHook($hooks['rollback']);
            }
            $this->notice("Migration for \033[0;35m{$this->database}\033[0;0m completed, but errors were encountered.");
            foreach ($this->errors as $sql => $message) {
                $this->notice($sql);
                $this->error($message);
            }
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
     * Execute a batch of SQL statements and display feedback.
     *
     * @param string $description Description
     * @param array $sqls Array of SQL statements
     */
    public function sql(string $description, array $sqls)
    {
        $description = trim($description);
        if (strlen($description) > 94) {
            $description = substr(preg_replace("@\s+@m", ' ', $description), 0, 94)." \033[0;33m[...]";
        }
        fwrite(STDOUT, "\033[0;36mSQL:\033[0;39m $description \033[0;37m  0%");
        $error = false;
        $orig = count($sqls);
        $done = 0;
        while ($sql = array_shift($sqls)) {
            try {
                $this->pdo->exec(trim($sql));
            } catch (PDOException $e) {
                $this->errors[trim($sql)] = $e->getMessage();
                $error = true;
            }
            $done++;
            fwrite(STDOUT, sprintf(
                "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;D%s%%",
                str_pad(round($done / $orig * 100), 4, ' ', STR_PAD_LEFT)
            ));
        }
        if ($error) {
            fwrite(STDOUT, "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;31m[Error]\033\n");
        } else {
            fwrite(STDOUT, "\033[0;D\033[0;D\033[0;D\033[0;D\033[0;32m[Ok]\033\n");
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
     * Set the description to show when the current batch runs.
     *
     * @param string $description
     */
    public function setDescription(string $description)
    {
        $this->description = $description;
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

    private function executeHook(string $command)
    {
        $dsn = escapeshellarg($this->dsn);
        $user = escapeshellarg($this->settings['user']);
        $pass = escapeshellarg($this->settings['pass']);
        exec("$command $dsn $user $pass");
    }
}

