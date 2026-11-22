#!/usr/bin/env bash
# Symlink this repo's plugin (and theme, if present) into a local WordPress site.
# Usage: bash bin/link-local.sh [site-path]   (default: ~/Herd/bookstore-nepal)
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SITE="${1:-$HOME/Herd/bookstore-nepal}"
CONTENT="$SITE/wp-content"

if [ ! -d "$CONTENT" ]; then
	echo "wp-content not found at $CONTENT" >&2
	exit 1
fi

link() {
	local src="$1" dest="$2"
	if [ ! -d "$src" ]; then
		echo "skip: $src does not exist yet"
		return 0
	fi
	if [ -L "$dest" ]; then
		rm "$dest"
	elif [ -e "$dest" ]; then
		echo "refusing to replace non-symlink $dest" >&2
		return 1
	fi
	ln -s "$src" "$dest"
	echo "linked $dest -> $src"
}

link "$REPO/plugins/bookstore-nepal-core" "$CONTENT/plugins/bookstore-nepal-core"
link "$REPO/themes/bookstore-nepal" "$CONTENT/themes/bookstore-nepal"
