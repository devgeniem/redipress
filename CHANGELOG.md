# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)
and this project adheres to [Semantic Versioning](http://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- This changelog adhering to the 'keep a changelog' standard.
- Nonscalar fields are serialized before storing them to the index.
- A support for `update_post_meta_cache` and `update_post_term_cache`.

### Changed
- The index creation is now run on the `init` hook with a priority of *1000*. This allow various dependencies to execute before RediPress indexing.
- Removed `post_object` and `permalink` from the index as they are never queried.

### Fixed
- String escaping fixed for post data indexing.