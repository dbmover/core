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
            $this->loader->addOperation(static::DESCRIPTION, $this->statements);
            $this->statements = [];
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
            $this->loader->addOperation(static::DEFERRED, $this->deferredStatements);
            $this->deferredStatements = [];
        }
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
        foreach ($this->findOperations($regex, $sql) as $match) {
            $sql = str_replace($match[0], '', $sql);
            yield $match;
        }
        return;
    }
}

