#!/usr/bin/env bash
# Boot WP Playground for Jot with persistent state.
#
# State (SQLite DB, OAuth tokens, AI Connector config, users) lives in
# $HOME/.jot-playground/wordpress, outside the repo, so secrets stay out of git.
# The plugin code is mounted live from this repo, so `git checkout <branch>`
# swaps the code under test without touching the persistent site state.
#
# Overrides:
#   JOT_PLAYGROUND_DIR   persistent /wordpress dir   (default: ~/.jot-playground/wordpress)
#   JOT_PLAYGROUND_WP    WordPress version           (default: 7.0-RC2)
#   JOT_PLAYGROUND_PHP   PHP version                 (default: 8.3)
#   JOT_PLAYGROUND_PORT  port to listen on           (default: 9400)

set -euo pipefail

WP_DIR="${JOT_PLAYGROUND_DIR:-$HOME/.jot-playground/wordpress}"
WP_VERSION="${JOT_PLAYGROUND_WP:-7.0-RC2}"
PHP_VERSION="${JOT_PLAYGROUND_PHP:-8.3}"
PORT="${JOT_PLAYGROUND_PORT:-9400}"

mkdir -p "$WP_DIR"

# First boot: dir is empty, let Playground download+install WP into it.
# Subsequent boots: files exist; skip the install dance and reuse them
# (the SQLite DB under wp-content/database/ is what carries options + user meta).
if [ -f "$WP_DIR/wp-config.php" ]; then
  INSTALL_MODE="install-from-existing-files-if-needed"
else
  INSTALL_MODE="download-and-install"
fi

exec npx @wp-playground/cli@latest server \
  --mount-before-install="$WP_DIR:/wordpress" \
  --auto-mount \
  --wordpress-install-mode="$INSTALL_MODE" \
  --wp="$WP_VERSION" \
  --php="$PHP_VERSION" \
  --port="$PORT" \
  --login
