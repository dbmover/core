<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;
use Dbmover\Core\Views;

/** Testsuite for Dbmover\Core\Loader */
return function () : Generator {
    $loader = Wrapper::createObject(
        Loader::class,
        'mysql:dbname=dbmover_test',
        ['user' => 'dbmover_test', 'pass' => 'moveit', 'options' => ['ignore' => ['@^fizzbuzz$@']]],
        true
    );

    /** getPdo returns an instanceof PDO */
    yield function () use ($loader) {
        $result = $loader->getPdo();
        assert($result instanceof PDO);
    };

    /** getVendor returns 'mysql' */
    yield function () use ($loader) {
        $result = $loader->getVendor();
        assert($result === 'mysql');
    };

    /** getDatabase returns 'dbmover_test' */
    yield function () use ($loader) {
        $result = $loader->getDatabase();
        assert($result === 'dbmover_test');
    };

    /** getUser returns 'dbmover_test' */
    yield function () use ($loader) {
        $result = $loader->getUser();
        assert($result === 'dbmover_test');
    };

    /** getErrors returns empty array */
    yield function () use ($loader) {
        $result = $loader->getErrors();
        assert($result === []);
    };

    /** loadPlugins does not throw an error for an existing plugin */
    yield function () use ($loader) {
        $e = null;
        try {
            $result = $loader->loadPlugins('Dbmover\Core\Views');
        } catch (Throwable $e) {
        }
        assert($e === null);
    };

    /** loadPlugins does throw an error for an existing plugin */
    yield function () use ($loader) {
        $e = null;
        try {
            $result = $loader->loadPlugins('Something\Invalid');
        } catch (Throwable $e) {
        }
        assert($e !== null);
    };

    /** addOperation adds an operation */
    yield function () use ($loader) {
        $loader->addOperation('blarps', ['SELECT foo FROM bar']);
        assert($loader->getOperations() === [['blarps', ['SELECT foo FROM bar']]]);
    };

    /** shouldBeIgnored returns false if something should not be ignored */
    yield function () use ($loader) {
        $result = $loader->shouldBeIgnored('blarps');
        assert($result === false);
    };

    /** shouldBeIgnored returns true if something should be ignored */
    yield function () use ($loader) {
        $result = $loader->shouldBeIgnored('fizzbuzz');
        assert($result === true);
    };

};

