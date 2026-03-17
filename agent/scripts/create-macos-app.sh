#!/bin/bash
# Creates a macOS .app bundle from the pkg binary and zips it for distribution.
# Usage: ./scripts/create-macos-app.sh build/storno-agent-macos-arm64 arm64
#        ./scripts/create-macos-app.sh build/storno-agent-macos-x64 x64

set -euo pipefail

BINARY="$1"
ARCH="${2:-arm64}"
VERSION=$(node -p "require('./package.json').version")

APP_NAME="Storno Agent.app"
APP_DIR="build/${APP_NAME}"
OUTPUT_ZIP="build/storno-agent-macos-${ARCH}.zip"

# Clean previous
rm -rf "$APP_DIR" "$OUTPUT_ZIP"

# Create .app structure
mkdir -p "$APP_DIR/Contents/MacOS"
mkdir -p "$APP_DIR/Contents/Resources"

# Copy binary
cp "$BINARY" "$APP_DIR/Contents/MacOS/storno-agent"
chmod +x "$APP_DIR/Contents/MacOS/storno-agent"

# Copy icon
cp src/icons/icon.icns "$APP_DIR/Contents/Resources/AppIcon.icns"

# Create Info.plist
cat > "$APP_DIR/Contents/Info.plist" << PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key>
    <string>Storno Agent</string>
    <key>CFBundleDisplayName</key>
    <string>Storno Agent</string>
    <key>CFBundleIdentifier</key>
    <string>ro.storno.agent</string>
    <key>CFBundleVersion</key>
    <string>${VERSION}</string>
    <key>CFBundleShortVersionString</key>
    <string>${VERSION}</string>
    <key>CFBundleExecutable</key>
    <string>storno-agent</string>
    <key>CFBundleIconFile</key>
    <string>AppIcon</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>LSMinimumSystemVersion</key>
    <string>11.0</string>
    <key>LSUIElement</key>
    <true/>
    <key>NSHighResolutionCapable</key>
    <true/>
    <key>CFBundleURLTypes</key>
    <array>
        <dict>
            <key>CFBundleURLName</key>
            <string>Storno Agent Protocol</string>
            <key>CFBundleURLSchemes</key>
            <array>
                <string>storno-agent</string>
            </array>
        </dict>
    </array>
</dict>
</plist>
PLIST

# Zip the .app bundle (use -y to preserve symlinks)
cd build
zip -r -y "$(basename "$OUTPUT_ZIP")" "$(basename "$APP_DIR")"
cd ..

# Clean up .app directory
rm -rf "$APP_DIR"

echo "Created ${OUTPUT_ZIP} (v${VERSION})"
