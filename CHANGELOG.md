# Changelog

All notable changes to this plugin are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

Add entries under **Unreleased** as part of each feature or bugfix branch.
When a release is cut, `bin/release.sh` also drafts entries for any merged
feature or bugfix branch not mentioned here (a PR is matched by its `(#N)`),
then moves everything under the new version.

## [Unreleased]

### Added

- `hb/upload-image` ability: add an image to the media library from a URL or
  base64 data, optionally attaching it and setting it as a featured image (#1).
- `hb/set-featured-image` ability: set or remove a post's featured image (#1).
- `hb/list-images` ability: search the media library for images to reuse (#1).
- `hb/get-content` now returns the post's featured image (#1).
- WordPress Coding Standards with a PHPCS check on pull requests (#1).
- Release script (`bin/release.sh`) and this changelog.

### Changed

- The AI Drafter role now has `upload_files` (#1).

## [1.0.0] - 2026-11-03

### Added

- Initial release: AI Drafter role and abilities to create, list, read and
  update content, ACF fields and Yoast SEO fields over MCP.
