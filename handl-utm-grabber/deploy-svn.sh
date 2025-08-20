#!/usr/bin/env bash
set -euo pipefail

# -------------------------------------------------------------------
# USAGE CHECK & CONFIGURATION
# -------------------------------------------------------------------
if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <version>"
  exit 1
fi
VERSION="$1"
PLUGIN_SLUG="handl-utm-grabber"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
# TMP_SVN="$(mktemp -d)/${PLUGIN_SLUG}-svn"
TMP_SVN="../${PLUGIN_SLUG}-svn-temp" # TODO Uncomment the line above and comment this
echo "🚀 Deploying ${PLUGIN_SLUG} v${VERSION} to SVN…"

# -------------------------------------------------------------------
# CONFIRMATION PROMPT
# -------------------------------------------------------------------
echo
read -p "⚠️  Are you sure you want to deploy version ${VERSION}? (y/N): " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "❌ Deployment cancelled."
    exit 1
fi
echo "✅ Proceeding with deployment..."

# -------------------------------------------------------------------
# 1. BUILD CLIENT ASSETS
# -------------------------------------------------------------------
echo "1️⃣  Building client…"
pushd client >/dev/null
pnpm install
pnpm run build
popd >/dev/null

# -------------------------------------------------------------------
# 2. CHECK OUT A FRESH SVN WORKING COPY
# -------------------------------------------------------------------
echo "2️⃣  Checking out SVN repo…"
rm -rf "${TMP_SVN}"
svn checkout "${SVN_URL}" "${TMP_SVN}"

# -------------------------------------------------------------------
# 3. SYNC ONLY YOUR PLUGIN FILES INTO trunk/
# -------------------------------------------------------------------
echo "3️⃣  Syncing plugin files to trunk/…"
# wipe any old files in trunk
rm -rf "${TMP_SVN}/trunk/"*

# rsync everything except dev-only stuff
rsync -av --delete \
  --exclude=".git*" \
  --exclude="node_modules/" \
  --exclude="*.idea/" \
  --exclude="*.DS_Store" \
  --exclude="package-lock.json" \
  --exclude="premiums/vendor/" \
  --exclude="client/" \
  --exclude="*.vscode/" \
  --exclude=".github/" \
  ./ "${TMP_SVN}/trunk/"

# copy over the freshly built client assets
echo "   ↪️  Copying built client assets…"
mkdir -p "${TMP_SVN}/trunk/client/build"
rsync -av client/build/ "${TMP_SVN}/trunk/client/build/"

# -------------------------------------------------------------------
# 4. COMMIT TRUNK UPDATES
# -------------------------------------------------------------------
echo "4️⃣  Committing to trunk/…"
cd "${TMP_SVN}"
# add any new files
svn add --force trunk
# remove any files you deleted locally
svn rm $(svn status | awk '/^!/ {print $2}') || true
# push it up
# svn commit -m "Deploy plugin version ${VERSION} to trunk"

# -------------------------------------------------------------------
# 5. TAG THE RELEASE (Haktan's approach)
# -------------------------------------------------------------------
echo "5️⃣  Tagging version ${VERSION}…"
# cheap copy in SVN (records snapshot of trunk/)
svn cp trunk "tags/${VERSION}"
# commit that tag with a clear message
svn ci -m "Completely revamped UI"

# -------------------------------------------------------------------
# DONE
# -------------------------------------------------------------------
echo "✅ Done! ${PLUGIN_SLUG} v${VERSION} is in SVN (trunk & tags)."

# remove the temp svn directory
#rm -rf "${TMP_SVN}"