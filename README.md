# RediPress
A WordPress plugin that provides a blazing fast search engine and WP Query performance enhancements.

RediPress utilizes popular in-memory key-value database Redis as well as its RediSearch module, which provides a very performant full text search engine and secondary index on top of Redis.

The plugin hooks to various WordPress APIs, hooks and filters to create a replica of WordPress' posts database table in RediSearch and to keep it up-to-date. It also hooks into `WP_Query` and diverts all suitable queries into RediSearch instead of MySQL, which makes the queries a lot faster, in many cases almost instant.

RediPress also provides advanced searching features that are lacking from the original WordPress search, like weighted search terms and fuzzy search.

RediPress is also built with extensive amount of hooks and filters to customize its functionalities to suit the needs of the developer. In addition to just optimizing the queries, RediPress also brings some completely new features to the table with the ability to include non-WordPress-posts in the search results or adding and querying location data within the index.

# Table of Contents

<!-- @import "[TOC]" {cmd="toc" depthFrom=2 depthTo=6 orderedList=false githubCompatibility=true} -->

<!-- code_chunk_output -->

- [Requirements](#requirements)
- [Installation and initialization](#installation-and-initialization)
- [Usage](#usage)
  - [Extra parameters](#extra-parameters)
    - [Weights](#weights)
      - [Post types](#post-types)
      - [Authors](#authors)
      - [Taxonomy terms](#taxonomy-terms)
      - [Meta values](#meta-values)
    - [Fuzzy matching](#fuzzy-matching)
- [Expanding](#expanding)
  - [Adding custom fields](#adding-custom-fields)
  - [Modifying the post object](#modifying-the-post-object)
- [Third party plugins](#third-party-plugins)
  - [Advanced Custom Fields](#advanced-custom-fields)
  - [Polylang](#polylang)
- [Troubleshooting](#troubleshooting)

<!-- /code_chunk_output -->


## Requirements
- Redis + [RediSearch](https://oss.redislabs.com/redisearch/index.html) module. At least version 1.6.0 of RediSearch is required. The plugin is tested up to version 1.6.1.
- WordPress version 5.0.0 or later. The plugin could work with earlier versions as well, but it has not been tested.

## Installation and initialization
1. Install Redis with RediSearch and ensure it can be connected from the WordPress installation.
2. Install and activate the RediPress plugin. For now the only available installation method is through Composer.
3. Define the connection parameters either in the admin panel or as constants in your code (recommended).
4. If you are planning to include custom posts or additional fields in the search index, it should be done now.
5. Create the index schema to RediSearch. It can be done either in the admin panel or through WP-CLI with command `wp redipress create`.
6. Run the actual indexing. It can as well be done either in the admin panel or through WP-CLI. Running the command `wp redipress index` is a recommended way to run the initial indexing. If the indexing stops you can continue indexing by running the command `wp redipress index posts missing`.

### Indexing multisite installations

You can use the same commands with multisite: `wp redipress create` and `wp redipress index`. For multisite you have to specify site url with the `--url` parameter in order for RediPress to function properly. Example `wp redipress index --url=subsite.domain.test`.

## Usage

The basic usage of RediPress goes through the `WP_Query` class. You can use the class and functions that depend on it (such as `get_posts()`) like you normally would, and RediPress hooks into the queries and runs them against the RediSearch database instead of the normal MySQL resulting in much lower latencies and query times.

Unlike some other similar query-speeding plugins, you don't need to add any parameters to the queries as RediPress knows what it is capable of doing and handles all queries automatically. If there is a query it can't handle, it automatically falls back to MySQL.

### Extra parameters

There are some extra parameters that can be used alongside the regular parameters.

#### Weights

If no ordering parameters are set with a search query or if `orderby` is set to `relevance`, RediPress uses RediSearch's default relevance calculations for ordering the results. By default it weighs `post_title` field with score `5.0`, `post_excerpt` with `2.0` and other fields with `1.0`. These can all by changed by filters.

There is also a possibility to give custom weights to certain values when doing the query.

##### Post types

Custom weights can be given to different post types either in the admin panel or in code. In code it is done by adding a custom `weight` parameter to the `WP_Query` parameters with `post_type` as a key and another array as its value containing the post types and their weights as key-value pairs.

```php
new WP_Query([
    'post_type' => 'any',
    'orderby'   => 'relevance',
    'weight'    => [
        'post_type' => [
            'post'             => 1.5,
            'page'             => 3.0,
            'custom_post_type' => 7.0,
        ],
    ],
]);
```

##### Authors

You can also weigh different post authors differently. So far there is no admin functionality for this and it must be done in code.

```php
new WP_Query([
    'post_type' => 'any',
    'orderby'   => 'relevance',
    'weight'    => [
        'author' => [
            'admin'      => 1.5,
            'jane_doe'   => 2.0,
            'john_smith' => 5.0,
        ],
    ],
]);
```

##### Taxonomy terms

It is also possible to weigh posts with certain taxonomy terms higher than others. In this case the first level inside the `taxonomy` parameter is the taxonomy name with another associative array as its value with terms and their weights as key-value pairs.

```php
new WP_Query([
    'post_type' => 'any',
    'orderby'   => 'relevance',
    'weight'    => [
        'taxonomy' => [
            'category' => [
                'news'          => 3.0,
                'announcements' => 5.0,
            ],
            'post_tag' => [
                'some_tag'    => 4.25,
                'another_tag' => 6.0,
            ],
        ],
    ],
]);
```

##### Meta values

Weights can also be defined for meta values. Obviously the meta keys should be added to the RediPress index and also set to be `queryable`. In this case the first level inside the `meta` is the meta key with another associative array as its value with values and their weights as key-value pairs.

```php
new WP_Query([
    'post_type' => 'any',
    'orderby'   => 'relevance',
    'weight'    => [
        'meta' => [
            'meta_key1' => [
                'foo' => 2.0,
                'bar' => 3.0,
            ],
            'another_key' => [
                'fizz' => 1.25,
                'buzz' => 3.0,
            ],
        ],
    ],
]);
```

#### Fuzzy matching

RediPress supports fuzzy matching of search terms with the `s` parameter. The setting for the Levenshtein distance can be either `1`, `2` or `3`. This can also be set in the admin panel.

```php
new WP_Query([
    's'         => 'foobar',
    'post_type' => 'any',
    'fuzzy'     => 2,
]);
```

### Filters

#### Query parts

To customize the RediSearch query, you can filter individual query parts with the following filters:

- `redipress/sortby`
- `redipress/applies`
- `redipress/filters`
- `redipress/groupby`
- `redipress/reduce_functions`
- `redipress/load`

## Expanding

RediPress is built to be as developer-friendly as possible. Nearly everything can be filtered and there are a lot of actions to work with.

### Adding custom fields

To add custom fields into the RediPress index, two things must be done: register a new field into the table schema, and add a filter which provides the data for the field during the indexing and on post save.

Adding a new schema field works with the `redipress/schema_fields` filter. It filters an array of objects that extend `Geniem\RediPress\Entity\SchemaField`. For now the options are a numeric field, a text field a and tag field. The differences on these can be read from the [official RediSearch documentation](https://oss.redislabs.com/redisearch/Commands.html#Parameters).

```php
add_filter( 'redipress/schema_fields', function( $fields ) {
    $fields[] = new \Geniem\RediPress\Entity\TextField([
        'name'     => 'my_field_name',
        'weight'   => 1.5,
        'sortable' => true,
    ]);

    return $fields;
}, 10, 1 );
```

**Notice** The index needs to be re-created if new fields are created, which also empties the index and thus requires complete re-indexing as well.

When the custom field is created and in the schema, the next is step to provide a function that populates the data for the field. This is done via the `redipress/additional_field/{field_name}` filter. The filter gets called every time the post is indexed, regardless of whether it is done on save post action, on a complete re-index or for example via WP-CLI command.

```php
add_filter( 'redipress/additional_field/my_field_name', function( $data, $post_id, $post ) {
    return get_post_meta( $post_id, 'my_field_name', true );
}, 10, 1 );
```

### Modifying the post object

RediPress stores the post object serialized in its index. It can be modified before saving which allows the developer to store additional data for the post to be used after it's retrieved from the database.

This happens with the `redipress/post_object` filter. In the example below, all ACF fields of the post are included in the object.

```php
add_filter( 'redipress/post_object', function( $post ) {
    $post->fields = \get_fields( $post->ID );

    return $post;
}, 10, 1 );
```

## Third party plugins

### Advanced Custom Fields

There is a lot of functionality in [ACF Codifier](https://github.com/devgeniem/acf-codifier) for working with RediPress. Without Codifier, ACF fields should be included in the index via filters.

### Polylang

RediPress supports Polylang out of the box. Other multi-language plugins may require some coding to work.

## Troubleshooting

If you run into problems you can try dropping all indeces by running `wp redipress drop`. After this re-index.

## Delete posts from the index

If you need to delete posts from the index by `blog_id` or `post_type` then you can use cli command `wp redipress delete`.
Limit for the delete is `100` by default but you can change that with a parameter `--limit`.

### Example usage
```bash
wp redipress delete --blog_id=1 --post_type=post --limit=500
```