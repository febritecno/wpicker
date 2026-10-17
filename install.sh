#!/bin/bash
set -e

REPO="febritecno/wpicker"
ACTION=${1:-install}
INSTALL_DIR="/usr/local/bin"

# Whether to teach installed AI agents about wpicker right after installing.
# Skip with:  bash -s -- install --no-agents   (or WPICKER_NO_AGENTS=1)
SETUP_AGENTS=true
for arg in "$@"; do
    [ "$arg" = "--no-agents" ] && SETUP_AGENTS=false
done
[ -n "${WPICKER_NO_AGENTS:-}" ] && SETUP_AGENTS=false

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
    echo "=> wpicker is now available globally. To verify, run: wpicker --help"

    if [ "$SETUP_AGENTS" = true ]; then
        echo "=> Setting up your AI agents (Claude Code, Codex, Gemini CLI where installed)..."
        # Never fail the install over this: the binary is already in place.
        # Files must belong to the person, never to root: under sudo, act as them.
        if [ "$(id -u)" = "0" ]; then
            if [ -n "${SUDO_USER:-}" ] && [ "$SUDO_USER" != "root" ]; then
                sudo -u "$SUDO_USER" -H "$INSTALL_DIR/wpicker" connect --global || echo "   (skipped: run 'wpicker connect --global' later)"
            else
                echo "   (skipped: running as root; run 'wpicker connect --global' as your own user)"
            fi
        else
            "$INSTALL_DIR/wpicker" connect --global || echo "   (skipped: run 'wpicker connect --global' later)"
        fi
    fi
    echo "=> Next: wpicker login   then   wpicker connect   inside a project (Cursor, Copilot, Windsurf, Cline)"

elif [ "$ACTION" = "uninstall" ] || [ "$ACTION" = "remove" ]; then
    echo "=> Removing wpicker from $INSTALL_DIR..."
    if [ -w "$INSTALL_DIR" ]; then
        rm -f "$INSTALL_DIR/wpicker"
    else
        sudo rm -f "$INSTALL_DIR/wpicker"
    fi
    echo "=> wpicker removed."

else
    echo "Usage: curl -sL https://raw.githubusercontent.com/$REPO/main/install.sh | bash -s -- [install|remove] [--no-agents]"
    exit 1
fi
