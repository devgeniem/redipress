# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2020-05-15

### Fixed
- Fix Polylang's localized main queries on multisites by using the language slug in term queries instead of the language term id.
- Bail early from the indexing function if the given post does not exist.
- Set the correct post types for 's' queries.
- A bug where ordering a query by post date would fail in certain conditions.

### Added
- Possibility to use `all` as a parameter for `blog` to query all network sites at once.

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

### Changed
- Fix empty query variable handling.

## [1.1.2] - 2020-02-07

### Changed
- Fix empty search string handling.
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