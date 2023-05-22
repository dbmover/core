# DbMover\Core
PHP-based database versioning tool, core package.

## Installation
The recommended way of installing DbMover is via
[Composer](https://getcomposer.org). Currently DbMover supports PostgreSQL and
MySQL via `dbmover/pgsql` and `dbmover/mysql` respectively. E.g.:

```sh
composer require dbmover/pgsql
```

## Design goals
Web applications often work with SQL databases. Programmers will layout such a
database in a "schema file", which is essentially just SQL statements. This is
fine when a new programmer starts working on a project, since she can simply
create the database and run the schema against it to get up and running. The
problem arises when, during the course of development or an application's
lifetime, changes to this schema are required. This involves manually applying
the changes to all developers' test databases, perhaps a staging database _and_
eventually the production database(s).

Doing this manually is tedious and error-prone. Remembering to write migrations
for each change is also tedious, and keeping track of which migrations have
already been applied (or not) is error-prone too (real life use case: importing
older versions of a particular database to resolve a particular issue, and the
migration "registry" itself being out of date).

DbMover automates this task for you by just looking at the central, leading,
version controlled schema files and applying any changes required. This allows
you to blindly run `vendor/bin/dbmover` e.g. in a post-receive hook.

## The `dbmover.json` file
DbMover is configured using a `dbmover.json` file. This should be at the root of
your project (i.e., two folders up from `vendor/bin`). The format is as follows:

```json
{
    "your dsn": {
        "user": "yourUserName",
        "pass": "something secret",
        "schema": ["path/to/file1.sql", "path/to/file2.sql"[, ...]],
        "plugins": []
    }
}
```

Whenever you run DbMover, it will loop through all entries and apply whatever
you asked for. Many projects will use a single database, but as you can see
DbMover fully supports multiple databases in one configuration file.

Of course, you don't want to have your actual username/password in a version
controlled configuration file (and you do want this version controlled). Best
practice (unless you're working on the project alone) is to version control a
`dbmover.json.sample` file which has blanks for user/pass, but does contain the
other important information (schemas and plugins).

### DSN
This is the "DSN" connection string for a database. The exact format will vary
per vendor, but is usually of the type "vendor:dname=NAME;host=HOST;port=PORT",
where `port` can usually be omitted to use the default. If in doubt, consult
your system administrator. Currently supported vendors are `pgsql` and `mysql`;
if you'd like to contribute another vendor, see the "contributing" section
below.

### `schema`
This is an array of schema files, relative to the root of your repository.
DbMover will process them in order. Note that the option to split your schema
into multiple files is supplied for convenience/maintainability - internally,
DbMover cats them all together before starting work.

### `plugins`
An array of plugins DbMover will load to perform the migration. By using
plugins, we make DbMover _very_ configurable to your exact needs. To just use
sane defaults, simply specify the database vendor specific plugin (e.g.
`Dbmover\Pgsql\Plugin`). More on plugins below.

## Running DbMover
Simply execute `vendor/bin/dbmover`. For each database specified, it will
perform the requested operations against your defined schemas. If you've been
filling in your `dbmover.json` following the above tutorial and run it now...
nothing happens. This is because all _actual_ functionality is in _plugins_. You
need to specify them in your `dbmover.json` config.

## Plugins
As of version 0.6, DbMover uses plugins to specify actions. It is important to
note that a plugin by itself should _not_ change anything in the database; they
are used to gather commands to execute when performing the migration. Hence,
since your `plugins` array is at this point empty, DbMover doesn't know what to
do yet. See above for the syntax.

Plugins are processed in the order specified and may be specified more than
once. In that case, they'll simply be run multiple times (this is actually
useful).

Each plugin is actually run twice; once to modify the SQL, and once on
`__destruct` for cleanup. The destruction calls are made in the same order as
the invoke calls (see "Writing custom plugins" below).

## Metapackages
Plugins can also load other plugins; in fact, there's a number of _metaplugins_
officially provided. Generally, they'll do what you need for your database type
and design. But, you can also mix and match, write your own or combine these.

As an example, say you have a MySQL database and just want DbMover to migrate
everything it can. In that case, you should install the following plugin:

```sh
composer require dbmover/mysql
```

...and register this single plugin:

```json
{
    ...
    plugins: ["Dbmover\\Mysql\\Plugin"]
}
```

## Writing custom plugins
Each plugin must implement `Dbmover\Core\PluginInterface`. Usually you'll want
to extend the abstract `Dbmover\Core\Plugin`, but there are cases thinkable
where this is undesired (hence the interface).

Plugins get constructed with a single argument: the instance of
`Dbmover\Core\Loader` currently running a migration. Via this object you may
access the underlying `PDO` instance using the `getPdo()` method. It also
exposes the name of the current database via `getDatabase()`.

The main task of a plugin is to receive the currently available SQL, transform
the parts relevant to that into _operations_ for the migration loader, and
return the (usually modified) SQL as a string. Ideally, after all plugins have
run there is no SQL left to inspect.

Above main task is accomplished via the magic `__invoke` method. It takes the
current SQL as a string parameter, and must return the (optionally modified) new
current SQL.

Plugins can also optionally implement a `__destroy` method. Plugins are
destroyed in the same order they are run, after all plugins have been run.

Metaplugins will override the `__construct` method and manually load their own
"subplugins". _Do not add new plugins_ in the `__invoke` or `__destruct`
implementations - by the time these are run DbMover is done assembling plugins
and behaviour is unspecified (and likely erratic).

Database vendors tend to add _very_ specific behaviour. We've implemented the
most common use cases we could think of (i.e., they work for our own rather
complex databases) but improvements can _always_ be made. If you wrote a useful
plugin of your own you'd like to share, please see "Contributing" below.

## Writing your schema
You should write your schema as if it were to be run against a completely empty
database - afterwards you should have something you can work with, possibly
including default data.

### Adding tables
Just add the new table definition to the schema and re-run.

### Adding columns
Forgot a column in a database? No problem, just add it in your schema and re-run
DbMover.

Note that new columns will always be appended to the end of the table. Some
database drivers (like MySQL) support the `BEFORE` keyword, but e.g. PostgreSQL
doesn't and DbMover is as database-agnostic as possible.

### Altering columns
Just change the column definition in the schema file and DbMover will alter it
for you. This assumes the column retains the same name and whatever data it
contains is compatible with the new type (or can be discarded); for more complex
alterations, see below.

### Dropping columns
Just remove them from the schema and re-run. Note: they'll be really, really
gone aftwards, databases don't support undo.

### Dropping tables, views etc.
Just remove them from the schema and re-run. Again: they'll be really gone.

### Indexes and foreign key constraints
Depending on your database vendor, it may be allowed to specify these during
table creation. Support for this is still _very_ experimental and most
definitely not complete. _So don't do that if possible._ Instead, create these
constraints after table creation using `CREATE INDEX` or `ALTER TABLE`
statements.

Primary keys may already be speficied in the `CREATE TABLE` block. Other
constraints are still a work in progress.

### Loose `ALTER` statements
Sometimes you need to `ALTER` a table after creation specifically, e.g. when it
has a foreign key referring to a table you need to create later on. For example,
a `blog_posts` table might refer to a `lastcomment`, while `blog_post_comments`
in turn refers to a `blog_id` on `blog_posts`. Here you would first create the
posts table, then the comments table (with its foreign key constraint), and
finally add the constraint to the posts table.

Each `ALTER TABLE` statement is run in isolation by DbMover in the order
specified in the schema file, so just (re)add the foreign key where you would
logically add it if running against an empty database. The statement will either
fail silently (if the column doesn't exist or is of the wrong type pending a
migration) or will succeed eventually.

## More complex schema changes
Some things are hard(er) to automatically determine, like a table or column
rename. You should wrap these changes in `IF` blocks with a condition that will
pass when the migration needs to be done, and will otherwise fail.

Depending on your database vendor, it might be required to wrap these in a
"throwaway" procedure. E.g. MySQL only supports `IF` inside a procedure. The
vendor-specific classes in DbMover handle this for you. Throwaway procedures are
prefixed with `tmp_`.

Note that the exact syntax of conditionals (`ELSE IF`, `ELSIF`) is also
vendor-dependent. The exact way to determine whether a table needs renaming is
also vendor-dependent (though in the current version DbMover only supports
ANSI-compatible databases anyway, so you can use `INFORMATION_SCHEMA` for this
purpose).

## Conditionals
DbMover supports, via the `dbmover/conditionals` plugin, the inclusion of `IF`
blocks in your schema. This is an extension on SQL in that these blocks are
generally only allowed inside procedures. DbMover will wrap them for you.

## Inserting default data
To prevent duplicate inserts, these should be wrapped in an `IF NOT EXISTS ()`
condition like so:

```sql
IF NOT EXISTS (SELECT 1 FROM mytable WHERE id = 1) THEN
    INSERT INTO mytable (id, value1, value2, valueN)
        VALUES (1, 2, 3, 4);
END IF;
```

This will usually require the `dbmover/VENDOR-conditionals` plugin (which isn't
bundled in the meta-packages). See `dbmover/mysql-conditionals` and
`dbmover/pgsql-conditionals` for more information.

## Transferring data from one table to another
This is sometimes necessary. In these cases, you should use `IF` blocks and
query e.g. `INFORMATION_SCHEMA` (depending on your vendor) to determine if the
migration has already run.

> Important: the `IF` should evaluate to `false` if the migration has already
> run to avoid running it twice. Take care here.

A simplified and abstract pseudo example:

```sql
IF NOT EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE ...) THEN
    INSERT INTO target SELECT * FROM original;
END IF;
```

Also, see the note in the previous section about conditionals.

## Caveats

### Be neat
DbMover assumes well-formed SQL, where keywords are written in ALL CAPS. It
does not specifically validate your SQL, though any errors will cause the
statement to fail and the script to halt (so theoretically they can't do much
harm...). DbMover will tell you what error it got.

By "be neat", we mean write `CREATE TABLE` instead of `create Table` etc.

DbMover also doesn't recognise e.g. MySQL's escaping of reserved words using
backticks. Just don't do that, it's evil.

> For the `ignore` regexes, you can perfectly use "strange" object names if you
> need to since these are regexed verbatim.

For hoisting, it is assumed that statements-to-be-hoisted are at the beginning
of lines (i.e., e.g. `/^IF /` in regular expression terms).

Databases may or may not be case-sensitive; keep in mind that DbMover _is_
case-sensitive, so just be consistent in your spelling.

### Storage engines and collations
Currently DbMover ignores these. Support for MySQL is planned; for PostgreSQL,
changing the collation is a database-wide operation which cannot be handled by
DbMover (it requires recreation of the entire database).

### Test your schema first
Always run DbMover against a test database for an updated schema. Everybody
makes typos, you don't want those to mangle a production database. Preferably
you'd test against a _copy_ of the actual production database.

### Bring down your application during migration
Depending on what you're requesting and how big your dataset is, migrations
might take a few minutes. You don't want users editing any data while the schema
isn't in a stable state yet!

How your application handles its down state is not up to DbMover. A simple way
would be to wrap write your own plugins for this:

```json
{
    "dsn": {
        "plugins": ['Myplugins\\Down', ..., 'Myplugins\\Up']
    }
}
```

A simple way to handle down/up states would be to write an empty file (e.g.
called simply `down`) in the root of your application, check for it in a front
controller, and remove it when bringing the application up again. A very basic
example:

```php
<?php

namespace Myplugins;

use Dbmover\Core\PluginInterface;

class Down implements PluginInterface
{
    public $description = 'Bringing application down...';

    public function __invoke(string $sql) : string
    {
        $cwd = getcwd();
        `touch $cwd/down`;
        return $sql;
    }
}

class Up implements PluginInterface
{
    public $description = 'Briging application back up...';

    public function __destruct()
    {
        $cwd = getcwd();
        `rm $cwd/down`;
        parent::__destruct();
    }
}
```

...and in your front controller (in this example, simply `index.php`):

```php
<?php

if (file_exists('/path/to/down')) {
    die("Application is down for maintainance.");
}
// ...other code...
```

### Backup your database before migration
If you tested against an actual copy and it worked fine this shouldn't be
necessary, but better safe than sorry. You might suffer a power outage during
the migration!

Besides, the simple fact that the script runs correctly doesn't necessarily mean
it did what you intended. Always verify your data after a migration.

Using the `Up` and `Down` custom plugins from the previous section, you could
handle this automatically. Use the Loader's `getErrors()` method to see if a
rollback is required or you can simply remove the backup (or keep it just in
case manual inspection happens to turn up something fishy).

## Contributing
SQLite support is sort-of planned for the near future, but is not extremely high
on my priority list (clients use it occasionally, but it's really not a database
suited very well for web development).

MSSQL and Oracle are valid choices, but we don't have access to them. If you do
and you fancy porting the database-specific parts of DbMover, by all means fork
the repository and send us a pull request!

There's no formal style guide, but look at the existing code and please try to
keep your coding style consitent with it. If you work on vendors I can't/won't
support, please also make sure you add unit tests for those.

Contributions are also very welcome in the form of bug reports (please file
against the affected package!) and feature requests (vendor support is by no
means exhaustive yet, just the most-used options).

Plugin packages sometimes also contain a TODO-list in their `README.md`. If your
request is already listed there, there's no need to report it since it's already
on the roadmap.

To run unit tests, execute `vendor/bin/toast`. The tests require an empty MySQL
database with the name `dbmover_test`, user `dbmover_test` and password
`moveit`.

## Debugging and development
Run `dbmover` with the `--dry-run` flag to just assemble a list of operations to
perform, without actually making any changes.

