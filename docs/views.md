# Dbmover\Views
This plugin drops all existing views prior to migration, and recreates the
requested views afterwards.

Vendors supporting more complex views (e.g. materialized views in PostgreSQL)
should extend this plugin and handle accordingly.

