#!/bin/bash
set -e

REPO="febritecno/wpicker"
ACTION=${1:-install}
INSTALL_DIR="/usr/local/bin"

# Check if we are running locally inside the cloned repository
if [ -d "$(dirname "$0")/cli" ] && [ -f "$(dirname "$0")/Makefile" ]; then
    LOCAL_BUILD=true
else
    LOCAL_BUILD=false
fi

if [ "$ACTION" = "install" ]; then
    if [ "$LOCAL_BUILD" = true ]; then
        echo "=> Building wpicker CLI from source..."
        cd "$(dirname "$0")/cli"
        go build -trimpath -o wpicker .
        BINARY_PATH="wpicker"
    else
        echo "=> Downloading latest wpicker CLI from GitHub..."
        OS=$(uname -s | tr '[:upper:]' '[:lower:]')
        ARCH=$(uname -m)
        if [ "$ARCH" = "x86_64" ]; then ARCH="amd64"; fi
        if [ "$ARCH" = "aarch64" ]; then ARCH="arm64"; fi
        
        BINARY_NAME="wpicker-${OS}-${ARCH}"
        DOWNLOAD_URL="https://github.com/${REPO}/releases/latest/download/${BINARY_NAME}"
        
        echo "=> Fetching ${DOWNLOAD_URL} ..."
        curl -sL -f -o wpicker "$DOWNLOAD_URL" || { 
            echo "Error: Failed to download release. Are you sure a release exists for ${OS}-${ARCH}?"
            exit 1
        }
        chmod +x wpicker
        BINARY_PATH="./wpicker"
    fi

    echo "=> Installing wpicker globally..."
    if [ -w "$INSTALL_DIR" ]; then
        mv "$BINARY_PATH" "$INSTALL_DIR/wpicker"
    else
        echo "=> Sudo privileges required..."
        sudo mv "$BINARY_PATH" "$INSTALL_DIR/wpicker"
    fi
    
    echo "=> Installed to $INSTALL_DIR/wpicker"
    echo "=> AI Agent Context: wpicker is now available globally."
    echo "=> To verify, run: wpicker --help"

elif [ "$ACTION" = "uninstall" ] || [ "$ACTION" = "remove" ]; then
    echo "=> Removing wpicker from $INSTALL_DIR..."
    if [ -w "$INSTALL_DIR" ]; then
        rm -f "$INSTALL_DIR/wpicker"
    else
        sudo rm -f "$INSTALL_DIR/wpicker"
    fi
    echo "=> wpicker removed."

else
    echo "Usage: curl -sL https://raw.githubusercontent.com/$REPO/main/install.sh | bash -s -- [install|remove]"
    exit 1
fi
