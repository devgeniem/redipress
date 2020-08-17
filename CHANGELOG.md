# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [1.7.0] - 2020-06-23

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