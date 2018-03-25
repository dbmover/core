<?php

namespace Dbmover\Core;

use Generator;

/**
 * Abstract base plugin. Usually other plugins extend this, but should at the
 * very least implement `Dbmover\Core\PluginInterface`.
 */
abstract class Plugin implements PluginInterface
{
    /** @var Dbmover\Core\Loader */
    protected $loader;
    /** @var array */
    protected $statements = [];
    /** @var array */
    protected $deferredStatements = [];

    /**
     * @var string
     * Description for the plugin, outputted on `__invoke`.
     */
    const DESCRIPTION = 'DbMoving...';

    /**
     * @var string
     * Deferred description for the plugin, outputted on `__destroy`. Skipped if
     * no deferred statements were defined.
     */
    const DEFERRED = 'Cleaning up...';

    /**
     * @param Dbmover\Core\Loader $loader
     * @return void
     */
    public function __construct(Loader $loader)
    {
        $this->loader = $loader;
    }

    /**
     * @param string $sql
     * @return string The same SQL, with handled statements stripped.
     */
    public function __invoke(string $sql) : string
    {
        return $sql;
    }

    /**
     * Add an SQL operation to the pool.
     *
     * @param string $sql
     * @return void
     */
    public function addOperation(string $sql) : void
    {
        $this->statements[] = $sql;
    }

    /**
     * Defer an SQL statement for later handling.
     *
     * @param string $sql
     * @return void
     */
    public function defer(string $sql) : void
    {
        $this->deferredStatements[] = $sql;
    }

    /**
     * Persist all default statements to the Loader.
     *
     * @return void
     */
    public function persist() : void
    {
        if ($this->statements) {
            $this->loader->addOperation(self::DESCRIPTION, $this->statements);
        }
    }

    /**
     * Persist all deferred statements to the loader.
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->deferredStatements) {
            $this->loader->addOperation(self::DEFERRED, $this->deferredStatements);
        }
    }

    /**
     * "Spawns" a child plugin. Useful for when one plugin should nest another
     * (i.e. PlugA::operations > PlugB::operations > PlugB::deferred >
     * PlugA::deferred).
     *
     * @param string $plugin Fully qualified classname of the plugin to spwan
     * @param string $sql The SQL to be modified
     * @return string Modified SQL
     */
    public function spawn(string $plugin, string $sql) : string
    {
        $plugin = new $plugin($this->loader);
        $sql = $plugin($sql);
        $plugin->persist();
        unset($plugin);
        return $sql;
    }

    protected function findOperations(string $regex, string $sql) : Generator
    {
        if (preg_match_all($regex, $sql, $matches, PREG_SET_ORDER)) {
            while ($matches) {
                $match = array_shift($matches);
                yield $match;
            }
        }
        return;
    }

    protected function extractOperations(string $regex, string &$sql) : Generator
    {
        while ($match = $this->findOperations($regex, $sql)) {
            $sql = str_replace($match[0], '', $sql);
            yield $match;
        }
        return;
    }
}

