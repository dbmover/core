# dbMover
A PHP-based database versioning tool.

## Installation

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
or Oracle (or yet another database) and would like to contribute, you're more
than welcome! See the end of this readme.

```sh
$ composer require dbmover/mysql
# and/or
$ composer require dbmover/pgsql
```

For vendor-specific documentation, please refer to:
[PostgreSQL](http://dbmover.monomelodies.nl/pgsql/) and
[MySQL](http://dbmover.monomelodies.nl/mysql/).

## Design goals
Web applications often work with SQL databases. Programmers will layout such a
database in a "schema file", which is essentially just SQL statements. The
problem arises when, during the course of development or an application's
lifetime, changes to this schema are required. This involves manually applying
the changes to all developers' test databases, perhaps a staging database _and_
eventually the production database(s).

Doing this manually is tedious and error-prone. Remembering to write migrations
for each change is also tedious, and keeping track of which migrations have
already been applied (or not) is error-prone too (real life use case: importing
older versions of a particular database to resolve a particular issue, and the
migration "registry" itself being out of date).

dbMover automates this task for you by just looking at the central, leading,
version controlled schema files and applying any changes required.

## Usage
In the root of your project, place a `dbmover.json` file. This will contain the
settings for dbMover like connections, databasename(s) and the location of your
schema file(s).

The format is as follows:

```json
{
    "your dsn": {
        "user": "yourUserName",
        "password": "something secret",
        "schema": ["path/to/schema/file.sql"],
        "ignore": ["/regex/"]
    }
}
```

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

