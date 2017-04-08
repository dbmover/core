# DbMover
A PHP-based database versioning tool.

## Installation
The recommended way of installing DbMover is via
[Composer](https://getcomposer.org). Seriously - as of version 0.6 we use a
plugin-based architecture. Installing manually will land you in Dependency Hell.

```sh
composer require dbmover/core dbmover/PLUGIN [...]
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
        "plugins": [],
        "hooks": {
            "pre": "/path/to/pre-command",
            "post": "/path/to/post-command",
            "rollback": "/path/to/rollback-command"
        }
    }
}
```

Whenever you run DbMover, it will loop through all entries and apply whatever
you asked for. Many projects will use a single database, but as you can see
DbMover fully supports multiple databases in one configuration file.

### DSN
This is the "DSN" connection string for a database. The exact format will vary
per vendor, but is usually of the type "vendor:dname=NAME;host=HOST;port=PORT",
where `port` can usually be omitted to use the default. If in doubt, consult
your system administrator. Currently supported vendors are `pgsql` and `mysql`;
if you'd like to contribute another vendor, see the "contributing" section
below.

### `user` and `pass`
Obviously... you can also pass these as arguments on the command line if you'd
rather not have them in version control:

```sh
vendor/bin/dbmover myusername mysupersecretpass
```

Or, just exclude `dbmover.json` from your VCS. Passing on the command line is
also useful if e.g. production and development credentials are different (a
likely scenario). Credentials passed on the command line always override those
specified in `dbmover.json`. If only _one_ credential is passed, it is assumed
to be the _password_ and the username is taken from `dbmover.json` (if supplied,
otherwise defaults to "current system username" which may or may not be what you
want).

### `schema`
This is an array of schema files, relative to the root of your repository.
DbMover will process them in order. Note that the option to split your schema
into multiple files is supplied for convenience/maintainability - internally,
DbMover cats them all together before starting work.

### `plugins`
An array of plugins DbMover will load to perform the migration. By using
plugins, we make DbMover _very_ configurable to your exact needs. Each plugin is
denoted by either its Composer package name (e.g. "some/plugin") or a classname
autoloadable by Composer (e.g. "My\\Custom\\Plugin"). More on plugins below.

### `hooks`
An optional hash of hook scripts to run at various parts of this migration. The
`pre` hook is run _before_ anything else, and could e.g. backup your database,
signal to your application that it's down for maintainance mode etc. The `post`
hook is run after a successful migration and can be used e.g. to bring your
application out of maintainance mode, clean any backups etc. The `rollback` hook
is run whenever DbMover encounters an error in one of your schemas. You can
guess what you could/should do in there...

All hooks are _optional_.

Each hook receives three arguments: the DSN, the username and the password. If
the username wasn't supplied (see above) they will receive just two arguments.
These arguments can be used to (re)connect to the database if needed.

## Running DbMover
Simply execute `vendor/bin/dbmover`, optionally supplying username/password as
parameters (see above). For each database specified, it will perform the
requested operations against your defined schemas. If you've been filling in
your `dbmover.json` following the above tutorial and run it now... nothing
happens. This is because all _actual_ functionality is in _plugins_. You need to
specify them in your `dbmover.json` config.

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
`__destruct` for cleanup. The destruction calls are made in reverse order to the
invoke calls (see "Writing custom plugins" below).

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
    plugins: ["dbmover/mysql"]
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



## Commands
The Core package comes bundles with two commands: `update` and `seed`.
Additional commands may be registered via _plugins_.

### Configuring updates
The `update` command attempts to synchronise your database structure with the
definition in your schema file(s). It takes the following options:

#### `schema` : Array
An array of locations of schema files, relative to the root directory. If
mutliple schemas are given, they are applied in order. Note that DbMover
internally concatenates multiple schema files first.

#### `ignore` : Array
An array of regexes that, should anything match, will cause those objects to
be completely ignore by DbMover. This can be used if your application e.g.
contains generated tables (perhaps with a timestamp in the name?) that should
not be versioned at all.

### Configuring seeds
The `seed` command imports whatever initial data into your database. This is
useful for getting new developers up and running, but you could also integrate
it as part of initialising your unit tests. It takes the following option:

#### `source` : Array
An array of locations of source files to seed with. If any entry is seen to be
executable, it is run; if not, it is assumed to be textual SQL statements and is
run verbatim.

    Normally you'd use a different database to run unit tests against than you
    would to actually develop with. Since commands are configured per-DSN, you
    can easily have a different seed for tests as for the "up and running"
    scenario.

### Before and after hooks
Every command by default also supports an optional `before` and `after` hook.
These can contain a string with a command to be run respectively before the
operation starts, or after it ended. You can (should?) configure these to e.g.
backup your database and/or restore after failure, maybe temporarily put your
application in maintainance mode during operations etc. The command can be
anything in any language. The `after` command is also passed a single argument;
`0` if the operation seems to have completed successfully, or an error code if
something went haywire.

### Plugins
Every command also optionally supports an array of `plugins` to use. More on
those in the next section, but the values are simply PSR-resolvable PHP
classnames.

## Architecture
When you've properly configured DbMover and you run `vendor/bin/dbmover update`
against your schema (note: `update` is the default command and may be omitted),
you'll notice... nothing happens! This is because DbMover as of version 0.6 uses
a plugin based architecture, meaning all _actual_ functionality is defined in
plugins. A plugin can be either a custom command, or a modifier for a specific
operation you would like to be performed.

For custom commands, the resolvable classname should be used as the `$COMMAND`
key. Commands define human readable names, but DbMover needs this to autoload
them. For convenience, you can use the syntax `My\Classname:customname` as well.

For plugins, simply specify the resolvable classname in the array. Plugins are
loaded and applied in order, giving you great control. For more information on
writing your own plugins, see below.

## Writing custom plugins
Each plugin must extend the `Dbmover\Core\Plugin` class. Its constructor takes
a single argument: the instance of DbMover currently running (i.e., for the
current DSN):

```php
<?php

namespace Foo\Bar;

use Dbmover\Core\Plugin;
use Dbmover\Core\Dsn;

class MyCustomPlugin extends Plugin
{
    public function __construct(Dsn $dbmover)
    {
        // ...
    }
}

```



### Composer (recommended)
```sh
# eg. `dbmover/mysql`
composer require dbmover/VENDOR
```

### Manual
1. Download or clone the [main repository](https://github.com/dbmover/dbmover);
2. Download or clone the vendor repository (e.g.
   [https://github.com/dbmover/mysql](https://github.com/dbmover/mysql);
3. There is an executable `dbmover` in the `bin` directory of the main
   repository;
4. Register the correct namespaces (`Dbmover\Dbmover` and `Dbmover\VENDOR`) to
   the respective `src` directories in your PSR-4 autoloader.

## Vendor support
dbMover currently supports the MySQL and PostgreSQL database engines. Support
for SQLite is sort-of planned for the near future. If you have access to MSSQL
or Oracle (or yet another PDO-compatible database) and would like to contribute,
you're more than welcome! See the end of this readme.

```sh
$ composer require dbmover/mysql
# and/or
$ composer require dbmover/pgsql
```

For vendor-specific documentation, please refer to:
[PostgreSQL](http://dbmover.monomelodies.nl/pgsql/) and
[MySQL](http://dbmover.monomelodies.nl/mysql/).

## Usage
In the root of your project, place a `dbmover.json` file. This will contain the
settings for dbMover like connections, databasename(s) and the location of your
schema file(s).

The contents of `"your dsn"` are a bit driver-specific, but will usually be
along the lines of `"engine:host=host,dbname=name,port=1234"`, with one or more
being optional (defaulting to the engine defaults). This is the exact same
string that PHP's `PDO` constructor expects.

The file names of the schemas must be either relative to the directory you're
running from (recommended, since typically you want to keep those in version
control alongside your project's code) or absolute.

> Best practice: leave the config file out of source control, e.g. by adding it
> to `.gitignore`. The database connection credentials will change seldom (if
> ever) so setting this up should mostly be a one-time manual operation.
> This way your development database can use a throwaway password, and the
> production database can use something much stronger, use a host different than
> `localhost` etc.

The optional `"ignore"` entry can contain an array of regexes of objects to
ignore during the migration (e.g. when your application automatically creates
tables for cached data or something). The regular expressions are injected into
PHP's `preg_match` verbatim so should also contain delimiters and can optionally
specify pattern modifiers like `"/i"`. They are checked for all objects (tables,
views, procedures etc.).

After defining the config file, run the executable from that same location:

```sh
$ vendor/bin/dbmover
```

> For manual installs (see above), this file will be in a different place. You
> could consider creating a symlink to replicate the exact behaviour.

That's it - your database should now be up to date with your schema(s).

## Adding tables
Just add the new table definition to the schema and re-run.

## Adding columns
Forgot a column in a database? No problem, just add it in your schema and re-run
dbMover.

Note that new columns will always be appended to the end of the table. Some
database drivers (like MySQL) support the `BEFORE` keyword, but e.g. PostgreSQL
doesn't and dbMover is as database-agnostic as possible.

If your vendor supports it and you _really_ need to add a column at a certain
position, use an `IF` block as described below.

## Altering columns
Just change the column definition in the schema file and dbMover will alter it
for you. This assumes the column retains the same name and whatever data it
contains is compatible with the new type (or can be discarded); for more complex
alterations, see below.

## Dropping columns
Just remove them from the schema and re-run.

## Dropping tables, views etc.
Just remove them from the schema and re-run.

## Foreign key constraints and indexes
Depending on your database vendor, it may be allowed to specify these during
table creation. That would mean dbMover never sees them if the table already
exists! _So don't do that._ Instead, create these constraints after table
creation using `ALTER TABLE` statements.

The reason is that during the migration, dbMover will `DROP` all existing
constraints and recreate them during the migration to ensure only the
constraints intended by your schema exist on the database. This is the most
surefire way to accomplish this, as opposed to analysing your SQL statements,
accounting for vendor-specific syntax etc.etc.etc.

> For extremely large tables, recreating indexes might considerably slow down
> the moving process. C'est la vie.

## Loose `ALTER` statements
Sometimes you need to `ALTER` a table after creation specifically, e.g. when it
has a foreign key referring to a table you need to create later on. For example,
a `blog_posts` table might refer to a `lastcomment`, while `blog_post_comments`
in turn refers to a `blog_id` on `blog_posts`. Here you would first create the
posts table, then the comments table (with its foreign key constraint), and
finally add the constraint to the posts table.

Each `ALTER TABLE` statement is run in isolation by dbMover in the order
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
vendor-specific classes in dbMover handle this for you. Throwaway procedures are
prefixed with `tmp_`.

Note that the exact syntax of conditionals (`ELSE IF`, `ELSIF`) is also
vendor-dependent. The exact way to determine whether a table needs renaming is
also vendor-dependent (though in the current version dbMover only supports
ANSI-compatible databases anyway, so you can use `INFORMATION_SCHEMA` for this
purpose).

## Inserting default data
To prevent duplicate inserts, these should be wrapped in an `IF NOT EXISTS ()`
condition like so:

```sql
IF NOT EXISTS (SELECT 1 FROM mytable WHERE id = 1) THEN
    INSERT INTO mytable (id, value1, value2, valueN)
        VALUES (1, 2, 3, 4);
END IF;
```

## The order of things
While your schema file should run perfectly when called against an empty
database, if the database already contains objects dbMover will reorder the
statements as best it can. In particular:

1. All statements beginning with `IF` are hoisted.
2. All `ALTER TABLE` statements are also hoisted.
3. The above two are run in isolation.
4. Indexes and foreign key constraints are dropped.
5. All `CREATE TABLE` statements are hoisted and analysed.
    1. If the table should be created, issue that statement verbatim.
    2. If the table needs updating, issue the required update statements.
6. Drop existing views, routines and triggers.
7. Run all other statements (`CREATE PROCEDURE`, `CREATE VIEW` etc.).
8. Re-run step 3. Note that `ALTER TABLE` statements will silently fail, and
   presumably some or all conditions that were `false` will now evaluate to
   `true` and vice versa.
9. Attempt to drop tables that were not or not longer present in the schema.

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

## Caveats

### Be neat
dbMover assumes well-formed SQL, where keywords are written in ALL CAPS. It
does not specifically validate your SQL, though any errors will cause the
statement to fail and the script to halt (so theoretically they can't do much
harm...). dbMover will tell you what error it got.

By "be neat", we mean write `CREATE TABLE` instead of `create Table` etc.

dbMover also doesn't recognise e.g. MySQL's escaping of reserved words using
backticks. Just don't do that, it's evil.

> For the `ignore` regexes, you can perfectly use "strange" object names if you
> need to since these are regexed verbatim.

For hoisting, it is assumed that statements-to-be-hoisted are at the beginning
of lines (i.e., e.g. `/^IF /` in regular expression terms).

Databases may or may not be case-sensitive; keep in mind that dbMover _is_
case-sensitive, so just be consistent in your spelling.

### Storage engines and collations
dbMover ignores these. The assumption is that modifying these are a risky and
very rare operation that you want to do manually and/or monitor more closely
anyway.

### Test your schema first
Always run dbMover against a test database for an updated schema. Everybody
makes typos, you don't want those to mangle a production database. Preferably
you'd test against a _copy_ of the actual production database.

### Bring down your application during migration
Depending on what you're requesting and how big your dataset is, migrations
might take a few minutes. You don't want users editing any data while the schema
isn't in a stable state yet!

How your application handles its down state is not up to dbMover. A simple way
would be to wrap the dbMover call in a script of your own, e.g.:

```sh
touch down
vendor/bin/dbmover
rm down
```

...and in your application something like:
```php
<?php

if (file_exists('down')) {
    die("Application is down for maintainance.");
}
// ...other code...
```

This is of course an extremely simple example but should point you in the right
direction.

### Backup your database before migration
If you tested against an actual copy and it worked fine this shouldn't be
necessary, but better safe than sorry. You might suffer a power outage during
the migration!

Besides, the simple fact that the script runs correctly doesn't necessarily mean
it did what you intended. Always verify your data after a migration.

## Contributing
SQLite support is sort-of planned for the near future, but is not extremely high
on my priority list (clients use it occasionally, but it's really not a database
suited very well for web development).

MSSQL and Oracle are valid choices, but we don't have access to them. If you do
and you fancy porting the database-specific parts of dbMover, by all means fork
the repository and send us a pull request!

There's no formal style guide, but look at the existing code and please try to
keep your coding style consitent with it. If you work on vendors I can't/won't
support, please also make sure you add unit tests for those.

