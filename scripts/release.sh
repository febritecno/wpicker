#!/bin/bash
set -e

# WPicker Release Automation Script
# 
# Requires:
# 1. `gh` (GitHub CLI) installed and authenticated (`gh auth login`)
# 2. `zip` and `go` installed
#
# Usage: ./release.sh v1.1.0

VERSION=$1

if [ -z "$VERSION" ]; then
    echo "Error: You must provide a version tag (e.g., v1.1.0)"
    echo "Usage: ./release.sh <tag>"
    exit 1
fi

RAW_VERSION=${VERSION#v} # Removes 'v' prefix if it exists

echo "========================================"
echo " Starting WPicker Release Process: $VERSION"
echo "========================================"

# Check for gh cli
if ! command -v gh &> /dev/null; then
    echo "Error: GitHub CLI (gh) is not installed. Please install it first."
    exit 1
fi

echo ""
echo "=> 0. Bumping version numbers to $RAW_VERSION..."
# Update plugin version
sed -i '' "s/Version:           .*/Version:           $RAW_VERSION/" plugin/wpicker.php
sed -i '' "s/_wpicker_define( 'WPICKER_VERSION', '.*' );/_wpicker_define( 'WPICKER_VERSION', '$RAW_VERSION' );/" plugin/wpicker.php

# Update CLI version
sed -i '' "s/const Version = \".*\"/const Version = \"$RAW_VERSION\"/" cli/cmd/root.go

# Commit the version bump
git add plugin/wpicker.php cli/cmd/root.go
git commit -m "Bump version to $VERSION" || echo "Version already up to date"

# Ensure dist directory exists
mkdir -p dist
rm -rf dist/*

echo ""
echo "=> 1. Packaging WordPress Plugin..."
make package-plugin
# Makefile package-plugin creates dist/wpicker.zip
if [ ! -f "dist/wpicker.zip" ]; then
    echo "Error: Plugin zip was not created in dist/wpicker.zip"
    exit 1
fi

echo ""
echo "=> 2. Cross-Compiling Go CLI..."
cd cli

# Define OS/Arch combinations
TARGETS=(
    "darwin amd64"
    "darwin arm64"
    "linux amd64"
    "linux arm64"
    "windows amd64"
)

for target in "${TARGETS[@]}"; do
    OS=$(echo $target | awk '{print $1}')
    ARCH=$(echo $target | awk '{print $2}')
    
    OUTPUT_NAME="wpicker-${OS}-${ARCH}"
    if [ "$OS" = "windows" ]; then
        OUTPUT_NAME="${OUTPUT_NAME}.exe"
    fi
    
    echo "   Building for ${OS}/${ARCH} -> ${OUTPUT_NAME}"
    env GOOS=$OS GOARCH=$ARCH go build -trimpath -o "../dist/${OUTPUT_NAME}" .
done

cd ..

echo ""
echo "=> 3. Creating GitHub Release..."
# Create the release draft with all the compiled assets
echo "   Pushing tag to GitHub..."
git tag $VERSION || echo "Tag already exists, proceeding..."
git push origin $VERSION || echo "Pushing tag failed, perhaps it already exists remotely."

echo "   Uploading assets to GitHub Release..."
gh release create "$VERSION" dist/* --title "WPicker $VERSION" --notes "Release $VERSION" --draft

echo ""
echo "========================================"
echo "✓ Release Draft Created Successfully!"
echo "========================================"
echo "Go to your GitHub repository to review and publish the release."
