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
        foreach ($this->operations as $operation) {
            if (is_array($operation)) {
                list($operation, $hr) = $operation;
            } else {
                $hr = $operation;
            }
            $this->sql($operation, $hr);
        }
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
            $this->notice("Migration for \033[0;35m{$this->database}\033[0;0m completed, but errors were encountered:\n"
                ."\033[0;31m".implode("\n", $this->errors)."\033[0;0m");
        }
        fwrite(STDOUT, "\n");
        /*
        foreach ($this->getTables('BASE TABLE') as $table) {
            if (!$this->shouldBeIgnored($table)) {
                $this->tables[$table] = $this->create($table, 'Table');
            }
        }
        */
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

    /**
     * Process all added schemas.
     */
    public function processSchemas()
    {
        echo "\033[100D\033[0;34m".str_pad(
            "Gathering operations for {$this->database}...",
            100,
            ' ',
            STR_PAD_RIGHT
        );
        $sql = implode("\n", $this->schemas);
        list($sql, $drop) = $this->hoist('@^DROP .*?;$@ms', $sql);
        $operations = $drop;
        list($sql, $alter) = $this->hoist('@^ALTER TABLE .*?;$@ms', $sql);

        // Gather all conditionals and optionally wrap them in a "lambda".
        list($sql, $ifs) = $this->hoist('@^IF.*?^END IF;$@ms', $sql);
        foreach ($ifs as &$if) {
            $if = $this->wrapInProcedure($if);
        }

        $operations = array_merge($operations, $ifs);
        list($sql, $tables) = $this->hoist(
            '@^CREATE([A-Z]|\s)*(TABLE|SEQUENCE) .*?;$@ms',
            $sql
        );

        // Hoist all other recreatable objects.
        list($sql, $procedures) = $this->hoist(static::REGEX_PROCEDURES, $sql);
        list($sql, $triggers) = $this->hoist(static::REGEX_TRIGGERS, $sql);
        $hoists = array_merge($procedures, $triggers);
        list($sql, $views) = $this->hoist(
            '@^CREATE VIEW.*?;$@ms',
            $sql
        );

        $operations = array_merge($operations, $this->dropRecreatables());

        list($sql, $keys) = $this->hoist('@^CREATE(\s+UNIQUE)?\s+INDEX.*?;$@ms', $sql);
        $indexes = [];
        $class = $this->getObjectName('Index');
        foreach ($keys as $key) {
            $key = $class::fromSql($key);
            $indexes[$key->name] = $key;
        }
        list($sql, $keys) = $this->hoist('@^ALTER TABLE (.*?) ADD PRIMARY KEY\s*\(.*?\)@ms', $sql);
        $indexes = [];
        $class = $this->getObjectName('Index');
        foreach ($keys as $key) {
            $key = $class::fromSql($key);
            $indexes[$key->name] = $key;
        }

        $tablenames = [];
        foreach ($tables as $table) {
            if (!(preg_match('@^CREATE.*?TABLE (\w+) ?\(@', $table, $name))) {
                $operations[] = $table;
                continue;
            }
            $name = $name[1];
            $tablenames[] = $name;

            // If the table doesn't exist yet, create it verbatim.
            if (!isset($this->tables[$name])) {
                $operations[] = $table;
            } else {
                $class = $this->getObjectName('Table');
                $this->tables[$name]->setComparisonObject($class::fromSql($table));
                foreach ($indexes as $index) {
                    if ($index->table == $name) {
                        $index->parent = $this->tables[$name];
                        $this->tables[$name]->requested->current->indexes[$index->name] = $index;
                        if (isset($this->tables[$name]->current->indexes[$index->name])) {
                            $this->tables[$name]->current->indexes[$index->name]->setComparisonObject($index);
                        }
                    }
                }
                $operations = array_merge($operations, $this->tables[$name]->toSql());
            }
        }

        foreach ($hoists as $hoist) {
            preg_match('@^CREATE (\w+) (\w+)@', $hoist, $data);
            if ($data[1] == 'FUNCTION' && $this instanceof Pgsql) {
                $data[2] .= '()';
            }
            $operations[] = "DROP {$data[1]} IF EXISTS {$data[2]}";
            $operations[] = $hoist;
        }

        if (strlen(trim($sql))) {
            $operations[] = $sql;
        }

        // Cleanup: remove tables that are not in the schema (any more)
        foreach ($this->getTables('BASE TABLE') as $table) {
            if (!isset($this->tables[$table]) && !$this->shouldBeIgnored($table)) {
                $operations[] = "DROP TABLE $table CASCADE";
            }
        }

        if (!$operations) {
            echo "\033[100D\033[1;34m".str_pad("{$this->database} is up to date!", 100, ' ', STR_PAD_RIGHT)."\n\033[0m";
        } else {
            // Perform the actual operations and display progress meter
            echo "\033[100D\033[1;34mPerforming operations for {$this->database}...\n";

            echo "\033[0m";

            $bar = new CliProgressBar(count($operations));
            $bar->display();

            $bar->setColorToRed();

            $fails = [];
            while ($operation = array_shift($operations)) {
                try {
                    $this->pdo->exec(trim($operation));
                } catch (PDOException $e) {
                    $fails[trim($operation)] = $e->getMessage();
                }
                $bar->progress();

                if ($bar->getCurrentstep() >= ($bar->getSteps() / 2)) {
                    $bar->setColorToYellow();
                }
            }
            
            $bar->setColorToGreen();
            $bar->display();
            
            $bar->end();
            echo "\033[0m";
            if ($fails) {
                echo "The following operations raised an exception:\n";
                echo "(This might not be a problem necessarily):\n";
                foreach ($fails as $command => $reason) {
                    echo "$command: $reason\n";
                }
            }
        }
    }

    /**
     * Drops all recreatable objects (views, procedures, indexes etc.) from the
     * current database so they can safely be recreated during the upgrade
     * process.
     *
     * @return array Array of SQL operations.
     */
    public function dropRecreatables()
    {
        $operations = array_merge(
            $this->dropConstraints(),
            $this->dropTriggers(),
            $this->dropRoutines(),
            $this->dropViews()
        );
        return $operations;
    }

    /**
     * Generate drop statements for all foreign key constraints in the database.
     *
     * @return array Array of SQL operations.
     */
    public function dropConstraints()
    {
        $operations = [];
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME tbl, CONSTRAINT_NAME constr, CONSTRAINT_TYPE ctype
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_TYPE = 'FOREIGN KEY'
                    AND (CONSTRAINT_CATALOG = ? OR CONSTRAINT_SCHEMA = ?)"
        );
        $stmt->execute([$this->database, $this->database]);
        if ($fks = $stmt->fetchAll()) {
            foreach ($fks as $row) {
                if (!$this->shouldBeIgnored($row['constr'])) {
                    $operations[] = sprintf(
                        "ALTER TABLE %s DROP %s IF EXISTS %s CASCADE",
                        $row['tbl'],
                        $row['ctype'],
                        $row['constr']
                    );
                }
            }
        }
        return $operations;
    }

    /**
     * Generate drop statements for all triggers in the database.
     *
     * @return array Array of SQL operations.
     */
    public function dropTriggers()
    {
        $operations = [];
        foreach ($this->getTriggers() as $trigger) {
            if (!$this->shouldBeIgnored($trigger)) {
                $operations[] = "DROP TRIGGER $trigger";
            }
        }
        return $operations;
    }

    /**
     * Hoist all matches of $regex from the provided SQL string, and return them
     * as an array for further processing.
     *
     * @param string $regex Regular expression to hoist.
     * @param string $sql Reference to the SQL string to hoist from. Hoisted
     *  statements are removed from this string.
     * @return array An array containing the modified SQL (index 0) and an array
     *  of hoisted statements at index 1 (or an empty array if nothing matched).
     */
    public function hoist($regex, $sql)
    {
        $hoisted = [];
        if (preg_match_all($regex, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $stmt) {
                $hoisted[] = $stmt[0];
                $sql = str_replace($stmt[0], '', $sql);
            }
        }
        return [$sql, $hoisted];
    }

    /**
     * Return a list of all routines in the current catalog.
     *
     * @return array Array of routines, including meta-information.
     */
    public function getRoutines()
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT
                ROUTINE_TYPE routinetype,
                ROUTINE_NAME routinename
            FROM INFORMATION_SCHEMA.ROUTINES WHERE
                (ROUTINE_CATALOG = '%1\$s' OR ROUTINE_SCHEMA = '%1\$s')",
            $this->database
        ));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dropRoutines()
    {
        $operations = [];
        foreach ($this->getRoutines() as $routine) {
            if (!$this->shouldBeIgnored($routine)) {
                $operations = array_merge($operations, (new Sql(sprintf(
                    "DROP %s %s%s",
                    $routine['routinetype'],
                    $routine['routinename'],
                    static::DROP_ROUTINE_SUFFIX
                )))->toSql());
            }
        }
        return $operations;
    }

    /**
     * Return a list of all triggers in the current catalog.
     *
     * @return array Array of trigger names.
     */
    public function getTriggers()
    {
        $stmt = $this->pdo->prepare(sprintf(
            "SELECT TRIGGER_NAME triggername
                FROM INFORMATION_SCHEMA.TRIGGERS WHERE
                (TRIGGER_CATALOG = '%1\$s' OR TRIGGER_SCHEMA = '%1\$s')",
            $this->database
        ));
        $stmt->execute();
        $triggers = [];
        foreach ($stmt->fetchAll() as $trigger) {
            $triggers[] = $trigger['triggername'];
        }
        return $triggers;
    }

    /**
     * Vendor-specifically wrap an object name (e.g. in `...` for MySQL).
     *
     * @param string $name The name to wrap.
     * @return string A wrapped name.
     */
    protected function wrapName($name)
    {
        return $name;
    }
    
    /**
     * Vendor-specifically unwrap an object name (e.g. remote `...` for MySQL).
     *
     * @param string $name The name to unwrap.
     * @return string An unwrapped name.
     */
    protected function unwrapName($name)
    {
        return $name;
    }

    protected function create($name, $class, ObjectInterface $parent = null)
    {
        $class = $this->getObjectName($class);
        $object = new $class($name, $parent);
        $object->setCurrentState($this->pdo, $this->database);
        return $object;
    }

    /**
     * Get an array of all table names in this database.
     *
     * @return array An array of table names.
     */
    public function getTables($type = 'BASE TABLE')
    {
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME
                FROM INFORMATION_SCHEMA.TABLES
                WHERE (TABLE_CATALOG = ? OR TABLE_SCHEMA = ?) AND TABLE_TYPE = ?"
        );
        $stmt->execute([$this->database, $this->database, $type]);
        $names = [];
        while (false !== ($table = $stmt->fetchColumn())) {
            $names[] = $table;
        }
        return $names;
    }
}

