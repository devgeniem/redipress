# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

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