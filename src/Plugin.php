<?php

namespace Dbmover\Core;

abstract class Plugin implements PluginInterface
{
    protected $loader;
    protected $deferredStatements = [];

    public function __construct(Loader $loader)
    {
        $this->loader = $loader;
    }

    public function __invoke(string $sql) : string
    {
        return $sql;
    }

    protected function defer(string $sql, string $hr = null)
    {
        $this->deferredStatements[] = [$sql, $hr ?? $sql];
    }

    public function __destruct()
    {
        foreach ($this->deferredStatements as $sql) {
            $this->loader->addOperation(...$sql);
        }
    }
}

