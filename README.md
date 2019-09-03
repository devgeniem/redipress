# RediPress
A WordPress plugin that provides a blazing fast search engine and WP Query performance enhancements.

RediPress utilizes popular in-memory key-value database Redis as well as its RediSearch module, which provides a very performant full text search engine and secondary index on top of Redis.

The plugin hooks to various WordPress APIs, hooks and filters to create a replica of WordPress' posts database table in RediSearch and to keep it up-to-date. It also hooks into `WP_Query` and diverts all suitable queries into RediSearch instead of MySQL, which makes the queries a lot faster, in many cases almost instant.

RediPress also provides advanced searching features that are lacking from the original WordPress search, like weighted search terms and fuzzy search.

RediPress is also built with extensive amount of hooks and filters to customize its functionalities to suit the needs of the developer. In addition to just optimizing the queries, RediPress also brings some completely new features to the table with the ability to include non-WordPress-posts in the search results or adding and querying location data within the index.

## Requirements
- Redis + [RediSearch](https://oss.redislabs.com/redisearch/index.html) module. At least version 1.6.0 of RediSearch is required. The plugin is tested up to version 1.6.1.
- WordPress version 5.0.0 or later. The plugin could work with earlier versions as well, but it has not been tested.

## Installation and initialization
1. Install Redis with RediSearch and ensure it can be connected from the WordPress installation.
2. Install and activate the RediPress plugin. For now the only available installation method is through Composer.
3. Define the connection parameters either in the admin panel or as constants in your code (recommended).
4. If you are planning to include custom posts or additional fields in the search index, it should be done now.
5. Create the index schema to RediSearch. It can be done either in the admin panel or through WP-CLI with command `wp redipress create`.
6. Run the actual indexing. It can as well be done either in the admin panel or through WP-CLI. For now the AJAX implementation does not have batch processing which means bigger amounts of posts can and will timeout the request. Running the command `wp redipress index` is a recommended way to run the initial indexing.