<?php

namespace Dbmover\Core;

abstract class Plugin implements PluginInterface
{
    protected $loader;
    protected $statements = [];
    protected $deferredStatements = [];
    public $description = 'DbMoving...';

    public function __construct(Loader $loader)
    {
        $this->loader = $loader;
    }

    public function __invoke(string $sql) : string
    {
        return $sql;
    }

    public function addOperation(string $sql)
    {
        $this->statements[] = $sql;
    }

    public function defer(string $sql)
    {
        $this->deferredStatements[] = $sql;
    }

    public function persist()
    {
        if ($this->statements) {
            $this->loader->addOperation($this->description, $this->statements);
        }
    }

    public function __destruct()
    {
        if ($this->deferredStatements) {
            $this->loader->addOperation($this->description, $this->deferredStatements);
        }
    }
}

