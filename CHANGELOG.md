# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).


## [2.0.0] - 2023-01-13

### Added
- Geolocation field functionality for both posts and users.

### Changed
- The required RediSearch version is from now on at least 2.2.1.
- Ability to drop and create the index without having to delete all data first.
- A lot of changes in the filter names clarifying which index or search (i.e. posts or users) the filters are modifying.

## [1.16.0] - 2022-05-17

### Added
- Support for WP_Query password parameters.

## [1.15.0] - 2021-12-17

### Fixed
- Fixed "less_than" operator in the meta clause compare map.

## [1.14.0] - 2021-12-02

### Added
- `redipress/taxonomies` filter for the ability to select which taxonomies are indexed for which post.

### Fixed
- A bug where taxonomy terms didn't get deleted from a post's index.
- Running index from the admin side.
- Querying tags that contain dashes.

## [1.13.0] - 2021-09-24

### Added
- `escape_parentheses` setting for the user to choose whether to escape parentheses in search queries or not.
- User locale field for the ability to query users by their locale.

### Fixed
- A bug where setting order by to relevance would cause the query not to work.

## [1.12.0] - 2021-09-08

### Added
- Added support for `include_children` parameter of taxonomy queries.

### Fixed
- Force integer value to Redis port env on connect.
- Escape brackets in indexing.
- Taxonomy query clauses did not default to field `term_id` as they should.

## [1.11.0] - 2021-06-11

### Fixed
- Escape dots in addition to dashes in indexing.
- Remove tabs from post_content while indexing.

## [1.10.0] - 2021-05-04

### Added
- A CLI command `wp redipress delete` to delete posts by blog_id and post_type.
- A WP_Query argument `groupby` added to modify the grouping argument of the RediSearch query.

## [1.9.1] - 2021-02-24

### Fixed
- A bug where queryable fields with array values may not work properly.

## [1.9.0] - 2020-12-16

### Added
- The ability for 3rd party addons to fetch the ID of the post being currently indexed.

### Changed
- `post_author` field changed from Numeric to Text. *REQUIRES INDEX RECREATION AND REINDEXING*
- Polylang integration to query all languages if empty `lang` string is given.

### Fixed
- `author` parameter queries.

## [1.8.4] - 2020-10-29

### Fixed
- Further fix for taxonomies with dashes in their names.

## [1.8.3] - 2020-10-19

### Fixed
- A bug where `FT.INFO` would crash on formatting when `MAXTEXTFIELDS` list was present.
- A bug where taxonomies with dashes in their names would cause the queries to crash.

## [1.8.2] - 2020-10-02

### Changed
- The default scorer for `FT.SEARCH` queries from `DISMAX` to RediSearch default `TFIDF`. It's changeable with a filter.

### Fixed
- A bug where search queries would always go to `FT.AGGREGATE` even if `FT.SEARCH` would be a better choice.

## [1.8.1] - 2020-09-23

### Fixed
- A bug where saving a post twice during the same execution would result in missing fields during the second save.

## [1.8.0] - 2020-09-11

### Added
- Ability to query taxonomy slugs over multiple multisite blogs.
- Ability to use taxonomy queries as parts of meta query.
- Setting for disabling adding post author's display name to post's search index.

### Changed
- Updated dependency libraries PDFparser and PHPWord to latest versions.
- Run database save, if wanted, after indexing all posts.

### Fixed
- A bug in the Polylang integrations regarding multisite queries.

## [1.7.1] - 2020-08-19

### Fixed
- A bug regarding the search index string of custom fields.

## [1.7.0] - 2020-08-17

### Changed
- Singular post resolving to use WP_Query instead of our own implementation.
- Indexing additional fields to use custom static functionality instead of the previous filter-based approach.
- Make the default stop words list to be empty. It can be altered or returned to RediSearch default list via a filter.
- Define `WP_IMPORTING` constant when indexing.

## [1.6.1] - 2020-06-23

### Fixed
- Incompatibility of the `post_type` query var with the WP_Query.

## [1.6.0] - 2020-06-15

### Added
- Support for `author__in`, `author__not_in`, `post_parent__in` and `post_parent__not_in` WP_Query arguments.
- Support for WP_Query's `fields` argument.

### Fixed
- A bug where empty post content could cause some posts not to be indexed.
- A bug regarding WP_Query calls with `'post_type' => 'any'`.
- A bug where wrong posts with the post name in a hierarchical post type would sometimes be returned.
- A bug where main query lang taxonomy query failed in certain circumstances.
- A bug where the response formatting function crashed with non-key-value lists.
- A bug where query_var value 0 resulted in skipping the query.
- A bug in the Polylang main query fix when no 'lang' query var was set
- REST API compability.
- Wrong number of results were shown in the DustPress Debugger.

## [1.5.4] - 2020-06-10

### Fixed
- A bug where `post_parent` would not be saved for a post.

## [1.5.3] - 2020-06-09

### Fixed
- A bug where group by clauses set via filter would not cause an FT.AGGREGATE call on their own.
- A bug where `"posts_per_page" => -1` query argument would result in too few results.

## [1.5.2] - 2020-06-05

### Added
- A reverse filter for getting the Search class instance.

### Fixed
- A bug where `"posts_per_page" => -1` query argument would result in empty result set.

## [1.5.1] - 2020-06-04

### Added
- A setting (`fallback`) to disable the MySQL fallback if no results are found in RediSearch.

### Changed
- Wildcards won't be added to the end of the keywords if the keyword is just one character in length.

## [1.5.0] - 2020-06-01

### Added
- Changed the default scorer for relevancy searches to DISMAX and added a filter (`redipress/scorer`) for changing that.
- `post_mime_type` field to the core schema and the functions to support querying it.

### Fixed
- Singular view main queries where there is `paged` parameter present at the same time.

## [1.4.1] - 2020-05-20

### Fixed
- Support for parentheses and pipes in search terms for complicated filtering.

## [1.4.0] - 2020-05-15

### Fixed
- Polylang's localized main queries on multisites by using the language slug in term queries instead of the language term id.
- Bail early from the indexing function if the given post does not exist.
- Set the correct post types for 's' queries.
- A bug where ordering a query by post date would fail in certain conditions.

### Added
- Possibility to use `all` as a parameter for `blog` to query all network sites at once.
- Utility function `delete_doc()`.

### Changed
- Query to be run with FT.SEARCH if nothing in it requires features of FT.AGGREGATE.
- If multiple write actions are done during one script execution, they will by default be written in disk only once at the end of the execution.

## [1.3.1] - 2020-04-28

### Fixed
- Escaping additional field values when the value is "0".

## [1.3.0] - 2020-04-23

### Added
- Similar value formatting functionalities to update_value than in the save post action.

### Fixed
- A bug where giving a string or integer value to taxonomy query `terms` parameter would cause a warning.
- A bug where 404 page does not work properly.

## [1.2.1] - 2020-04-02

### Fixed
- A bug where the indexing of missing posts would not work.

## [1.2.0] - 2020-03-02

### Added
- Filters for various query parts to enable query customizations.

## [1.1.5] - 2020-02-18

### Fixed
- A bug where the tax query was not parsed in all situations.

## [1.1.4] - 2020-02-14

### Changed
- Do not add the Polylang language query for main queries. PLL will handle it.
- Set query offset firstly from the 'offset' query variable if it is set.

## [1.1.3] - 2020-02-07

### Fixed
- Empty query variable handling.

## [1.1.2] - 2020-02-07

### Fixed
- Empty search string handling.

### Changed
- Prevent the query builder from handling empty query variables.

## [1.1.1] - 2020-02-06

### Changed
- Always add an asterisk at the end of each search keyword to better mimic WordPress native behaviour.

## [1.1.0] - 2020-02-05

### Added
- A way to give a document ID for custom posts with index all feature.

### Changed
- Sortby fields are no longer added into groupby clause but only in the return fields lists with a FIRST_VALUE reducer by default.

### Fixed
- A bug with the main query when there is a page and one or more other posts with the same post_name.

## [1.0.2] - 2020-02-03

### Changed
- Version number to resemble reality in the changelog.

### Fixed
- Removed syntax error causing character from code.

## [1.0.1] - 2020-01-31

### Added
- This changelog adhering to the 'keep a changelog' standard.
- Nonscalar fields are serialized before storing them to the index.
- Support for `update_post_meta_cache` and `update_post_term_cache`.
- Support for giving own weights for post types, post authors, taxonomy terms and meta values.
- Support for `update_post_meta_cache` and `update_post_term_cache`.
- doc, rtf, odt, pdf & docx support
- Index management in admin
- `wp redipress index missing` command for indexing only posts that do not exist in the search index already.
- A reverse filter for getting the Index class instance.
- A method for deleting indexed items by a field name and matching value.
- A way to index only certain posts via the CLI commands.

### Changed
- The index creation is now run on the `init` hook with a priority of *1000*. This allow various dependencies to execute before RediPress indexing.
- Removed `post_object` and `permalink` from the index as they are never queried.
- Getting the document id for posts with a custom document id.

### Fixed
- String escaping fixed for post data indexing.
- A bug in user creation if the user query setting was disabled.