<?php

namespace Dbmover\Dbmover;

use PDO;
use PDOException;
use Dariuszp\CliProgressBar;
use Dbmover\Dbmover\Objects\Sql;

/**
 * The main Schema class. This represents a migration for a single unique DSN
 * from the `dbmover.json` config file.
 */
abstract class Schema
{
    const CATALOG_COLUMN = 'CATALOG';
    const DROP_CONSTRAINT = 'CONSTRAINT';

    use TableHelper;
    use ColumnHelper;
    use IndexHelper;
    use ProcedureWrapper;
    use Helper\Ns;

    public $pdo;
    public $schemas = [];
    public $database;
    public $ignores = [];
    protected $user;
    protected $tables;

    /**
     * Constructor.
     *
     * @param string $dsn The DSN string to connect with. Passed verbatim to
     *  PHP's `PDO` constructor.
     * @param array $settings Hash of settings read from `dbmover.json`. The
     *  `"user"` and `"pass"` keys are mostly relevant.
     */
    public function __construct($dsn, array $settings = [])
    {
        preg_match('@dbname=(\w+)@', $dsn, $database);
        $this->database = $database[1];
        $user = isset($settings['user']) ? $settings['user'] : null;
        $pass = isset($settings['pass']) ? $settings['pass'] : null;
        $options = isset($settings['options']) ? $settings['options'] : [];
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $this->pdo = new PDO($dsn, $user, $pass, $options);
        if (isset($options['ignore']) && is_array($options['ignore'])) {
            $this->ignores = $options['ignore'];
        }
        $this->user = $user;
        echo "\033[0;34m".str_pad(
            "Loading current schema for {$this->database}...",
            100,
            ' ',
            STR_PAD_RIGHT
        );
        foreach ($this->getTables('BASE TABLE') as $table) {
            if (!$this->shouldBeIgnored($table)) {
                $this->tables[$table] = $this->create($table, 'Table');
            }
        }
    }

    /**
     * Expose the name of the current database.
     *
     * @return string
     */
    public function getName()
    {
        return $this->database;
    }

    /**
     * Add schema data read from an SQL file.
     *
     * @param string $schema The complete schema.
     */
    public function addSchema($schema)
    {
        $this->schemas[] = $schema;
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

        $tablenames = [];
        foreach ($tables as $table) {
            if (!(preg_match('@^CREATE.*?TABLE (\w+) ?\(@', $table, $name))) {
                $operations = array_merge($operations, (new Sql($table))->toSql());
                continue;
            }
            $name = $name[1];
            $tablenames[] = $name;

            // If the table doesn't exist yet, create it verbatim.
            if (!isset($this->tables[$name])) {
                $operations = array_merge($operations, (new Sql($table))->toSql());
            } else {
                $class = $this->getObjectName('Table');
                $this->tables[$name]->setComparisonObject($class::fromSql($table));
                $operations = array_merge($operations, $this->tables[$name]->toSql());
                /*
                $existing = $this->getTableDefinition($name);
                $new = $this->parseTableDefinition($table);
                foreach ($existing as $col => $definition) {
                    if (!isset($new[$col])) {
                        $operations[] = sprintf(
                            "ALTER TABLE %s DROP COLUMN %s",
                            $name,
                            $col
                        );
                    }
                }
                foreach ($new as $col => $definition) {
                    if (!isset($existing[$col])) {
                        $operations[] = $this->addColumn($name, $definition);
                    } else {
                        $comp = $definition;
                        unset($comp['key']);
                        if ($comp != $existing[$col]) {
                            $operations = array_merge(
                                $operations,
                                $this->alterColumn($name, $definition)
                            );
                        }
                    }
                    if (isset($definition['key'])
                        && $definition['key'] == 'PRI'
                    ) {
                        $operations[] = sprintf(
                            "ALTER TABLE %s ADD PRIMARY KEY(%s)",
                            $name,
                            $col
                        );
                    }
                }
                */
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

        // Recrate views
        $operations = array_merge($operations, $views);

        // Rerun ifs and alters
        $operations = array_merge($operations, $alter, $ifs);

        // Cleanup: remove tables that are not in the schema (any more)
        foreach ($this->getTables('BASE TABLE') as $table) {
            if (!isset($this->tables[$table]) && !$this->shouldBeIgnored($table)) {
                $operations = array_merge($operations, (new Sql("DROP TABLE $table CASCADE"))->toSql());
            }
        }

        // Perform the actual operations and display progress meter
        echo "\033[100D\033[1;34mPerforming operations for {$this->database}...\n";

        echo "\033[0m";

        $bar = new CliProgressBar(count($operations));
        $bar->display();

        $bar->setColorToRed();

        $fails = [];
        var_dump($operations);
        while ($operation = array_shift($operations)) {
            try {
                $this->pdo->exec($operation);
            } catch (PDOException $e) {
                $fails[$sql] = $e->getMessage();
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
                $operations[] = sprintf(
                    "ALTER TABLE %s DROP %s IF EXISTS %s CASCADE",
                    $row['tbl'],
                    $row['ctype'],
                    $row['constr']
                );
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
            $operations[] = new Sql("DROP TRIGGER $trigger");
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
                ROUTINE_%s = '%s'",
            static::CATALOG_COLUMN,
            $this->database
        ));
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function dropRoutines()
    {
        $operations = [];
        foreach ($this->getRoutines() as $routine) {
            if (!$this->shouldIgnore($routine)) {
                $operations[] = new Sql(sprintf(
                    "DROP %s %s%s",
                    $routine['routinetype'],
                    $routine['routinename'],
                    static::DROP_ROUTINE_SUFFIX
                ));
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
                TRIGGER_%s = '%s'",
            static::CATALOG_COLUMN,
            $this->database
        ));
        $stmt->execute();
        $triggers = [];
        foreach ($stmt->fetchAll() as $trigger) {
            $triggers[] = $trigger['triggername'];
        }
        return $triggers;
    }

    public function shouldIgnore($object)
    {
        foreach ($this->ignores as $regex) {
            if (preg_match($regex, $object)) {
                return true;
            }
        }
        return false;
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
     * Determine whether an object should be ignored as per config.
     *
     * @param string $name The name of the object to test.
     */
    protected function shouldBeIgnored($name) : bool
    {
        foreach ($this->ignores as $regex) {
            if (preg_match($regex, $name)) {
                return true;
            }
        }
        return false;
    }
}

