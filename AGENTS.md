# AGENTS.md — Satollo Dispatcher

WordPress plugin for sending emails to registered users/audiences.

## Stack
- PHP 8.1+, WordPress 6.9+, no build step, no dependencies
- Namespace: `Satollo\Dispatcher` (autoloaded from `classes/`)

## Layout
- `satollo-dispatcher.php` — plugin bootstrap
- `classes/` — core classes (Engine, Audience, Contact, Logger)
- `includes/` — feature modules (main, audiences, gutenberg, classic, repo)
- `admin/` — admin UI and settings
- `assets/` — JS/CSS

## Conventions
- Guard every PHP file with `defined('ABSPATH') || exit;`
- Follow WordPress coding standards; text domain `satollo-dispatcher`
- Keep changes minimal and backwards compatible
