<?php

namespace Dbmover\Core;

abstract class Plugin implements PluginInterface
{
    /** @var Dbmover\Core\Loader */
    protected $loader;
    /** @var array */
    protected $statements = [];
    /** @var array */
    protected $deferredStatements = [];
    /** @var string */
    public $description = 'DbMoving...';

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
            $this->loader->addOperation($this->description, $this->statements);
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
            $this->loader->addOperation($this->description, $this->deferredStatements);
        }
    }
}

