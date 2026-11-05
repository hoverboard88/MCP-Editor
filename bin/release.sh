#!/usr/bin/env bash
#
# Cut a release with git flow.
#
#   bin/release.sh <major|minor|patch|X.Y.Z> [--push] [--yes] [--no-draft]
#
# From a clean, up-to-date dev branch this:
#
#   1. Drafts changelog entries for feature/* and bugfix/* branches merged
#      into dev since the last tag that "Unreleased" doesn't already cover.
#   2. Runs the coding standards check.
#   3. Starts release/X.Y.Z with git flow.
#   4. Adds the drafts to "Unreleased" in CHANGELOG.md and opens it in your
#      editor to review.
#   5. Sets the version in the plugin header.
#   6. Moves the "Unreleased" section of CHANGELOG.md under X.Y.Z.
#   7. Commits, then finishes the release (merge to main and dev, tag X.Y.Z).
#
# With --push it also pushes main, dev and the tag, and creates a GitHub
# release from the changelog section. Sites running the plugin update from the
# latest GitHub release, so pushing is what actually ships it.
#
# --yes skips the confirmation prompt and the editor review.
# --no-draft uses only the entries already written under "Unreleased".

set -euo pipefail

PLUGIN_FILE="hb-mcp-editor.php"
CHANGELOG="CHANGELOG.md"
DEV_BRANCH="dev"
MAIN_BRANCH="main"
REMOTE="origin"

in_release=""

# Once release/X.Y.Z exists, a failure leaves it behind; say how to clean up.
on_failure() {
	[[ -n "$in_release" ]] || return 0
	echo >&2
	echo "Release failed partway. Inspect release/$in_release, or remove it with:" >&2
	echo "  git checkout $DEV_BRANCH && git branch -D release/$in_release" >&2
}

die() {
	echo "Error: $*" >&2
	on_failure
	exit 1
}

usage() {
	sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'
	exit "${1:-0}"
}

# Body of the Unreleased section: everything up to the next "## [" heading,
# without leading or trailing blank lines.
unreleased_notes() {
	awk '
		/^## \[Unreleased\]/ { on = 1; next }
		on && /^## \[/       { exit }
		on                   { print }
	' "$CHANGELOG" | sed -e '/./,$!d' | sed -e ':a' -e '/^\n*$/{$d;N;ba' -e '}'
}

has_entries() {
	grep -q '^[[:space:]]*[-*] ' <<<"$1"
}

# Print "Category<TAB>Entry" for every feature/bugfix branch merged into dev
# since the last tag that the Unreleased section doesn't already mention.
#
# A GitHub PR merge is matched by its "(#N)" reference; it contributes the PR
# title when that is a real description, or else one entry per commit on the
# branch. A plain git flow merge contributes one entry per commit.
draft_entries() {
	local existing base merge subject title pr branch category ref lc text
	local re_pr='^Merge pull request #([0-9]+) from [^/]+/(.+)$'
	local re_branch="^Merge (remote-tracking )?branch '([^']+)'"

	existing=$(unreleased_notes)

	if ! base=$(git describe --tags --abbrev=0 "$DEV_BRANCH" 2>/dev/null); then
		base=$(git rev-list --max-parents=0 "$DEV_BRANCH" | tail -n 1)
	fi

	emit() {
		grep -qxF -- "- $2" <<<"$existing" && return 0
		printf '%s\t%s\n' "$1" "$2"
	}

	while read -r merge; do
		subject=$(git log -1 --format=%s "$merge")
		title=$(git log -1 --format=%b "$merge" | sed -n '/[^[:space:]]/{p;q;}')
		pr=""

		if [[ "$subject" =~ $re_pr ]]; then
			pr=${BASH_REMATCH[1]}
			branch=${BASH_REMATCH[2]}
		elif [[ "$subject" =~ $re_branch ]]; then
			branch=${BASH_REMATCH[2]#origin/}
			title=""
		else
			continue
		fi

		case "$branch" in
			feature/*) category="Added" ;;
			bugfix/*) category="Fixed" ;;
			*) continue ;;
		esac

		ref=""
		if [[ -n "$pr" ]]; then
			grep -qF "(#$pr)" <<<"$existing" && continue
			ref=" (#$pr)"
		fi

		# GitHub defaults a PR title to the branch name; that says nothing.
		lc=$(tr '[:upper:]' '[:lower:]' <<<"$title")
		if [[ -n "$title" && ! "$lc" =~ ^(feature|bugfix|hotfix|release)/ ]]; then
			emit "$category" "${title%.}$ref"
			continue
		fi

		while IFS= read -r text; do
			lc=$(tr '[:upper:]' '[:lower:]' <<<"$text")
			[[ -z "$text" || "$lc" =~ ^(fixup!|squash!|wip( |:|$)) ]] && continue
			emit "$category" "${text%.}$ref"
		done < <(git log --no-merges --reverse --format=%s "$merge^1..$merge^2")
	done < <(git log --first-parent --merges --reverse --format=%H "$base..$DEV_BRANCH")
}

# Add "Category<TAB>Entry" lines (from $HB_DRAFT) to the Unreleased section,
# under ### headings in Keep a Changelog order, creating headings as needed.
add_entries() {
	HB_DRAFT="$1" perl -e '
		use strict;
		use warnings;

		my $file = shift;
		open my $in, "<", $file or die "$file: $!\n";
		my $s = do { local $/; <$in> };
		close $in;

		$s =~ /^(## \[Unreleased\][^\n]*\n)(.*?)(?=^## \[|\z)/ms or die "No Unreleased section\n";
		my ( $head, $body, $start ) = ( $1, $2, $-[0] );
		my $len = length($head) + length($body);

		my ( $pre, $cur, @cats, %lines ) = ("");
		for my $line ( split /\n/, $body ) {
			if ( $line =~ /^### (.+?)\s*$/ ) {
				$cur = $1;
				push @cats, $cur unless $lines{$cur};
				$lines{$cur} ||= [];
			} elsif ( defined $cur ) {
				push @{ $lines{$cur} }, $line;
			} else {
				$pre .= "$line\n";
			}
		}

		for ( grep { length } split /\n/, $ENV{HB_DRAFT} ) {
			my ( $cat, $text ) = split /\t/, $_, 2;
			unless ( $lines{$cat} ) { push @cats, $cat; $lines{$cat} = []; }
			my $l = $lines{$cat};
			pop @$l while @$l && $l->[-1] =~ /^\s*$/;
			push @$l, "- $text";
		}

		my @canon = qw(Added Changed Deprecated Removed Fixed Security);
		my %rank;
		@rank{@canon} = 0 .. $#canon;
		@cats = sort { ( $rank{$a} // 99 ) <=> ( $rank{$b} // 99 ) } @cats;

		my $out = "$head\n";
		$pre =~ s/^\s+|\s+$//g;
		$out .= "$pre\n\n" if length $pre;
		for my $cat (@cats) {
			my @l = @{ $lines{$cat} };
			shift @l while @l && $l[0] =~ /^\s*$/;
			pop @l   while @l && $l[-1] =~ /^\s*$/;
			$out .= "### $cat\n\n" . join( "\n", @l ) . "\n\n" if @l;
		}

		substr( $s, $start, $len ) = $out;
		open my $outfh, ">", $file or die "$file: $!\n";
		print $outfh $s;
		close $outfh;
	' "$CHANGELOG"
}

bump=""
push=0
yes=0
draft=1

for arg in "$@"; do
	case "$arg" in
		--push) push=1 ;;
		--yes | -y) yes=1 ;;
		--no-draft) draft=0 ;;
		-h | --help) usage 0 ;;
		-*) die "Unknown option $arg" ;;
		*)
			[[ -z "$bump" ]] || die "Only one version argument is allowed."
			bump="$arg"
			;;
	esac
done

[[ -n "$bump" ]] || usage 1

cd "$(git rev-parse --show-toplevel)"

# --- Preconditions -----------------------------------------------------------

git flow version >/dev/null 2>&1 || die "git flow is not installed."

if ((push)); then
	command -v gh >/dev/null || die "--push needs the GitHub CLI (gh) to create the release."
	gh auth status >/dev/null 2>&1 || die "gh is not logged in. Run: gh auth login"
fi

[[ "$(git branch --show-current)" == "$DEV_BRANCH" ]] || die "Run this from the $DEV_BRANCH branch."
[[ -z "$(git status --porcelain)" ]] || die "The working tree has uncommitted changes."

echo "Fetching $REMOTE..."
git fetch --quiet --tags "$REMOTE"

for branch in "$DEV_BRANCH" "$MAIN_BRANCH"; do
	# A fresh clone may only have dev locally; git flow needs main to merge into.
	if ! git rev-parse --verify --quiet "refs/heads/$branch" >/dev/null; then
		git rev-parse --verify --quiet "$REMOTE/$branch" >/dev/null || die "No $branch branch locally or on $REMOTE."
		git branch --quiet --track "$branch" "$REMOTE/$branch"
	fi

	if git rev-parse --verify --quiet "$REMOTE/$branch" >/dev/null; then
		behind=$(git rev-list --count "$branch..$REMOTE/$branch")
		((behind == 0)) || die "$branch is $behind commit(s) behind $REMOTE/$branch. Pull first."
	fi
done

# --- Versions ----------------------------------------------------------------

current=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$PLUGIN_FILE" | head -n 1 | tr -d '[:space:]')
[[ "$current" =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)$ ]] || die "Could not read the version from $PLUGIN_FILE (found '$current')."

major=${BASH_REMATCH[1]}
minor=${BASH_REMATCH[2]}
patch=${BASH_REMATCH[3]}

case "$bump" in
	major) next="$((major + 1)).0.0" ;;
	minor) next="$major.$((minor + 1)).0" ;;
	patch) next="$major.$minor.$((patch + 1))" ;;
	*)
		[[ "$bump" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "Version must be major, minor, patch or X.Y.Z."
		next="$bump"
		;;
esac

newest=$(printf '%s\n%s\n' "$current" "$next" | sort -t. -k1,1n -k2,2n -k3,3n | tail -n 1)
[[ "$next" != "$current" && "$newest" == "$next" ]] || die "$next is not newer than the current $current."

if git rev-parse --verify --quiet "refs/tags/$next" >/dev/null; then
	die "Tag $next already exists."
fi

# --- Changelog ---------------------------------------------------------------

[[ -f "$CHANGELOG" ]] || die "$CHANGELOG not found."
grep -q '^## \[Unreleased\]' "$CHANGELOG" || die "$CHANGELOG has no '## [Unreleased]' section."

notes=$(unreleased_notes)
drafts=""
((draft)) && drafts=$(draft_entries)

has_entries "$notes" || [[ -n "$drafts" ]] \
	|| die "The Unreleased section of $CHANGELOG has no entries, and no merged branches were found to draft from."

# --- Confirm -----------------------------------------------------------------

echo
echo "Release $current -> $next"
((push)) && echo "Will push $MAIN_BRANCH, $DEV_BRANCH and tag $next, and create a GitHub release." \
	|| echo "Will not push. Nothing leaves this machine."
echo
echo "Already in Unreleased:"
if has_entries "$notes"; then
	sed 's/^/  /' <<<"$notes"
else
	echo "  (nothing)"
fi

if [[ -n "$drafts" ]]; then
	echo
	echo "Drafted from merged branches (added to Unreleased for review):"
	while IFS=$'\t' read -r category text; do
		echo "  [$category] $text"
	done <<<"$drafts"
fi
echo

if ((!yes)); then
	read -r -p "Continue? [y/N] " answer
	[[ "$answer" =~ ^[Yy]$ ]] || die "Aborted."
fi

# --- Checks ------------------------------------------------------------------

[[ -x vendor/bin/phpcs ]] || die "vendor/bin/phpcs missing. Run: composer install"
echo "Running coding standards..."
vendor/bin/phpcs -q || die "Coding standards failed. Fix them on a bugfix branch first."

# --- Release -----------------------------------------------------------------

git flow release start "$next" --no-fetch -q
in_release="$next"
trap on_failure ERR

if [[ -n "$drafts" ]]; then
	add_entries "$drafts"

	if ((!yes)) && [[ -t 0 ]]; then
		editor=${VISUAL:-${EDITOR:-vi}}
		echo "Opening $CHANGELOG in $editor. Edit the Unreleased section, save and close to continue."
		# Unquoted so an editor with arguments, like "code --wait", works.
		$editor "$CHANGELOG"
	fi
fi

notes=$(unreleased_notes)
has_entries "$notes" || die "The Unreleased section of $CHANGELOG is empty after review."

perl -pi -e "s/^( \\* Version:\\s*).*/\${1}$next/" "$PLUGIN_FILE"
grep -q "^ \* Version: $next\$" "$PLUGIN_FILE" || die "Failed to update the version in $PLUGIN_FILE."

today=$(date +%Y-%m-%d)
perl -0pi -e "s/^## \\[Unreleased\\][^\\n]*\\n/## [Unreleased]\\n\\n## [$next] - $today\\n/m" "$CHANGELOG"

git add "$PLUGIN_FILE" "$CHANGELOG"
git commit -q -m "Release $next"

if ((push)); then
	push_flag="--push"
else
	push_flag="--no-push"
fi

git flow release finish "$next" -m "Release $next" "$push_flag" --no-ff --no-fetch

trap - ERR
in_release=""

if ((push)); then
	echo "Creating GitHub release $next..."
	gh release create "$next" --verify-tag --title "$next" --notes "$notes"
	echo
	echo "Released $next."
else
	echo
	echo "Release $next finished locally. To ship it:"
	echo "  git push $REMOTE $MAIN_BRANCH $DEV_BRANCH $next"
	echo "  gh release create $next --verify-tag --title $next --notes-file <(sed -n '/^## \\[$next\\]/,/^## \\[/{/^## \\[/d;p;}' $CHANGELOG)"
fi
