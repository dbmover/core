#!/usr/bin/php5
<?php

/**
 * dbMover - a script that provides versioned database handling.
 *
 * Usage:
 * ./dbmover [options] database filename
 *
 * Example:
 * ./dbmover -u user -ppassword -h host dbname file-with-sql-data.sql
 *
 * By default dbMover assumes a MySQL database, since these are the most common.
 * The optional -t argument allows you to specify a different type, e.g. pgsql.
 * dbMover is tested with MySQL and PostgreSQL since those are the ones *I* use,
 * but in theory it should work with any PDO-compatible database as long as your
 * SQL statements are valid.
 *
 * The optional -i switch indicates that this is an initial import, i.e. when
 * migrating an existing database with SQL scripts to dbMover. dbMover then
 * simply registers the file[s] with their latest version for future refrence.
 *
 * The optional -m switch specifies the maximum version to parse in this run.
 * This is especially handy in conjunction with the -i switch, but might also
 * be useful in certain development setups. It should be followed by the maximum
 * named version to parse.
 *
 * dbMover works by analyzing your annotated SQL file:
 * <code>
 * -- {{{ v1.0.0
 * ...sql statements for v1.0.0
 * -- }}}
 * </code>
 *
 * The file is parsed sequentially, so the version names themselves are not
 * considered relevant - it's probably a good idea to use something like
 * incremental versions, but you can use descriptive names too. Whatever comes
 * "lower down" in the file is considered the later version.
 *
 * dbMover also supports specifying third-party scripts, e.g. when you are
 * working with external libraries that provide dbMover support:
 *
 * <code>
 * -- @include /path/to/file.sql
 * </code>
 *
 * It is important to note that external includes are always executed BEFORE
 * any SQL commands specified in that block, independent of where they get
 * included in the code. For clarity, it is therefore best to place them at
 * the beginning of the block.
 *
 * An @include /file.sql followed by a named version acts as a -m switch for
 * that includefile. This allows you to specify an include mulitple times,
 * with finely grained control over what should be included when.
 *
 * Any SQL code outside of dbMover versioned block is ignored.
 */

set_time_limit(0);
$args = [];
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i]{0} != '-') {
        $args['database'] = $argv[$i];
        if (isset($argv[$i + 1])) {
            $args['file'] = $argv[$i + 1];
            ++$i;
        } else {
            die("Abort: no input file specified.\n");
        }
        continue;
    }
    if ($argv[$i]{1} == 'p') {
        $args['pass'] = substr($argv[$i], 2);
        continue;
    }
    if ($argv[$i]{1} == 'i') {
        $args['init'] = strlen($argv[$i]) > 2 ? substr($argv[$i], 2) : true;
        continue;
    }
    if (!isset($argv[$i + 1]) || $argv[$i + 1]{0} == '-') {
        die("Abort: no argument supplied for {$argv[$i]{1}}\n");
    }        
    switch ($argv[$i]{1}) {
        case 't':
            $args['type'] = $argv[$i + 1];
            break;
        case 'u':
            $args['user'] = $argv[$i + 1];
            break;
        case 'h':
            $args['host'] = $argv[$i + 1];
            break;
        case 'm':
            $args['max'] = $argv[$i + 1];
            break;
    }
    ++$i;
}
$args += [
    'type' => 'mysql',
    'host' => 'localhost',
    'user' => 'root',
    'pass' => '',
    'init' => false,
    'max' => false,
];
if (!isset($args['database'])) {
    die("Abort: no database specified.\n");
}
if (!file_exists($args['file'])) {
    die("Abort: {$args['file']} does not exist.\n");
}
try {
    $options = [
        PDO::ATTR_PERSISTENT => true,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ];
    if ($args['type'] == 'mysql') {
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET names 'UTF8'";
    }
    $db = new PDO(
        "{$args['type']}:dbname={$args['database']};host={$args['host']}",
        $args['user'],
        $args['pass'],
        $options
    );
} catch (PDOException $e) {
    die("Abort: failed to connect to {$args['database']}\n");
}
$data = file_get_contents($args['file']);
preg_match_all(
    "@-- {{{ (.*?)\n(.*?)\n-- }}}\n@ms",
    $data,
    $matches,
    PREG_SET_ORDER
);
$vcheck = $db->prepare("SELECT id, checksum FROM dbmover_version WHERE filename = ? AND version = ?");
$vdone = $db->prepare("INSERT INTO dbmover_version (filename, version, checksum) VALUES (?, ?, ?)");
foreach ($matches as $set) {
    $version = $set[1];
    $commands = "\n{$set[2]}\n";
    $checksum = md5(preg_replace("@\s+@ms", '', $commands));
    echo "Checking $version in {$args['file']}...\n";
    $vcheck->execute([$args['file'], $version]);
    if ($done = $vcheck->fetch()) {
        if ($done['checksum'] != $checksum) {
            echo "Expected: {$done['checksum']}, calculated: $checksum\n";
            die("FATAL: checksum mismatch in $version of {$args['file']}, please resolve. Aborting now.\n");
        }
        echo "{$args['file']}, version $version was already done, skipping.\n";
    } else {
        echo "Analysing $version in {$args['file']}...\n";
        try {
            $externals = [];
            preg_replace_callback(
                "/-- @include (.*?)\n/m",
                function($match) use(&$externals) {
                    $externals[] = $match[1];
                    return '';
                },
                $commands
            );
            foreach ($externals as $external) {
                $parts = explode(' ', trim($external));
                $command = sprintf(
                    '%s %s-h %s -p%s -u %s%s %s %s',
                    $argv[0],
                    count($parts) == 2 ? "-m {$parts[1]} " : '',
                    $args['host'],
                    $args['pass'],
                    $args['user'],
                    $args['init'] ? ' -i' : '',
                    $args['database'],
                    count($parts) == 2 ? $parts[0] : $external
                );
                echo "@include found, forking $command...\n";
                passthru($command);
            }
            $comments = [];
            preg_replace_callback(
                "/-- @emit (.*?)\n/m",
                function($match) use(&$comments) {
                    $comments[] = $match[1];
                    return '';
                },
                $commands
            );
            if ($comments) {
                echo "\n\n".
                     "--------------------\n\n".
                     "Notes for $version:\n\n".implode("\n", $comments)."\n\n".
                     "--------------------\n\n";
            }
            $execs = [];
            preg_replace_callback(
                "/-- @exec (.*?)\n/m",
                function($match) use(&$execs) {
                    $execs[] = $match[1];
                    return '';
                },
                $commands
            );
            $db->beginTransaction();
            if (!$args['init']) {
                $db->exec($commands);
                echo "Processed $version in {$args['file']}: Ok!\n";
            } else {
                echo "Not processing version $version in {$args['file']}, we're only initializing here.\n";
            }
            $db->commit();
            foreach ($execs as $cmd) {
                $cmd = str_replace('$DIR', realpath(__DIR__), $cmd);
                echo "@exec $cmd...\n";
                passthru($cmd);
            }
            $vdone->execute([$args['file'], $version, $checksum]);
        } catch (PDOException $e) {
            $db->rollback();
            die("Error: SQL statement failed: ".$e->getMessage()."\n");
        }
    }
    if ($args['max'] && $args['max'] == $version) {
        echo "Stopping at version $version as requested.\n";
        break;
    }
}

