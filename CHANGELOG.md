# Changelog

All notable changes to this plugin are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

Add entries under **Unreleased** as part of each feature or bugfix branch.
When a release is cut, `bin/release.sh` also drafts entries for any merged
feature or bugfix branch not mentioned here (a PR is matched by its `(#N)`),
then moves everything under the new version.

## [Unreleased]

## [1.2.0] - 2026-11-09

### Added

- Taxonomy abilities: `hb/list-taxonomies`, `hb/list-terms`,
  `hb/set-post-terms` (replace, add or remove a post's terms by ID or name,
  creating new terms as the tag box does), and `hb/create-term`,
  `hb/update-term` and `hb/delete-term`.
- `hb/get-content` now returns the post's terms in every taxonomy, and its
  post screen settings with the available templates and formats.
- `hb/update-content` sets slug, author, parent, menu order, template,
  comment and ping status, password, sticky and post format.
- `hb/set-status` ability: unpublish to draft or pending, make private,
  trash, or restore from the trash.
- `hb/list-authors` ability: users who can author a post type.
- `hb/list-content` lists trashed content when asked for status `trash`.
- `hb/update-seo` covers Yoast's search visibility (noindex), link following,
  canonical URL, breadcrumb title and social (Facebook and X) previews.
- Adding caps for user mirroring what they can do in the post edit screen (#4)

### Changed

- `hb/update-fields` writes every ACF field type, including repeater,
  flexible content, group, clone, image, gallery, relationship, post object,
  taxonomy, user, link and map fields. Sub-fields are addressed by name, and
  ACF's own validation runs before anything is saved.
- `hb/get-fields` returns each field's structure (sub-fields, layouts,
  choices, required) and expected value format, with values in the same shape
  `hb/update-fields` accepts.
- The AI Drafter role now has `manage_categories` and the remaining
  `delete_*` capabilities for posts and pages (others', published, private),
  so it can trash content.

## [1.1.0] - 2026-11-05

### Added

- `hb/upload-image` ability: add an image to the media library from a URL or
  base64 data, optionally attaching it and setting it as a featured image (#1).
- `hb/set-featured-image` ability: set or remove a post's featured image (#1).
- `hb/list-images` ability: search the media library for images to reuse (#1).
- `hb/get-content` now returns the post's featured image (#1).
- WordPress Coding Standards with a PHPCS check on pull requests (#1).
- Release script (`bin/release.sh`) and this changelog.
- Allow publishing and scheduling content over MCP (#2)
- Adding claude.md (#2)
- Adding release script (#3)

### Changed

- The AI Drafter role now has `upload_files` (#1).

## [1.0.0] - 2026-11-03

### Added

- Initial release: AI Drafter role and abilities to create, list, read and
  update content, ACF fields and Yoast SEO fields over MCP.