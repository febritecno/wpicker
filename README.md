<p align="center">
  <img src="wpicker.jpg" alt="WPicker Logo" width="400"/>
</p>

# WPicker: AI-Native WordPress Bridge
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](LICENSE)
[![VirusTotal](https://img.shields.io/badge/VirusTotal-Scanned-success.svg)](https://www.virustotal.com/)
[![Go Version](https://img.shields.io/badge/Go-1.21+-blue.svg)](https://golang.org)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-indigo.svg)](https://php.net)

**WPicker** (a playful nod to the hardworking *woodpecker* 🐦) is a humble little bridge between your local AI agents and live WordPress sites. Just like a woodpecker tapping away at a tree, WPicker helps your AI tap into WordPress environments safely—providing secure auth, file synchronization, page builder integration, and vulnerability scanning right from your terminal.

[🚀 Installation](#-installation) • [✨ Features](#-key-features) • [🔄 Workflow](#-workflow)

> [!WARNING]
> It is highly recommended to use this extension **ONLY on staging, development, or local sites**. Do not use it on critical production environments without proper backups and security measures. **Always deactivate and uninstall the plugin when you are no longer using it** to minimize your site's security footprint.

---

## ✨ Key Features
- **Page Builder Integration** - Native detection and remote page creation for Divi, Elementor, and Gutenberg right from the CLI.
- **Vulnerability Scanner** - Scan your WordPress core, themes, and plugins for security vulnerabilities directly via the CLI, with visual reports available in WP-Admin.
- **10-Second Auto-Refreshing OTP** - Ultra-secure device pairing using a fast-refreshing 6-digit PIN. No need to share your main admin password.
- **Self-Healing Lint Gate** - Automatically runs `php -l` on pushed code, rejecting syntax errors and returning structured data for AI to fix.
- **Atomic Snapshots & Rollback** - Every push creates an atomic snapshot. Instantly restore any prior state if business logic breaks.
- **Seamless Auto-Updates** - The CLI can update itself via `wpicker update`, and the WordPress plugin natively integrates with WordPress Core Updates to fetch the latest GitHub releases.
- **🤖 AI Token Saver** - Forget heavy, slow MCP (Model Context Protocol) servers. WPicker feeds agents ultra-compact, targeted JSON contexts (`wpicker context`). This drastically reduces your LLM token usage and prevents AI hallucination.

## 💻 System Requirements
- **Server:** PHP 7.4+ with CLI `php` available in PATH. Active WordPress Child Theme.
- **Local/Agent:** macOS, Linux, or Windows (Go v1.21+ required only if building from source).

---

## 🤖 Supported AI Agents
WPicker is specifically designed to be easily consumed and driven by modern AI coding assistants. It provides structured JSON responses and strict guardrails for:
- **Cursor IDE** (Seamlessly integrates with Cursor's terminal and AI context)
- **Claude** (Works perfectly with Claude Desktop / Artifacts)
- **GitHub Copilot / OpenAI Codex**
- Any CLI-capable autonomous agent (e.g., AutoGPT, Devin, Antigravity)

---

## 🚀 Installation

Setting up WPicker involves two simple steps: installing the WordPress Plugin and installing the CLI.

### 1. Install the WordPress Plugin
1. Download the latest `wpicker.zip` from the [GitHub Releases](https://github.com/febritecno/wpicker/releases/latest) page.
2. Log in to your WordPress Admin dashboard.
3. Navigate to **Plugins > Add New Plugin > Upload Plugin**.
4. Upload `wpicker.zip` and click **Install Now**, then **Activate**.
5. Once activated, navigate to the new **WPicker** menu in your WordPress dashboard to view your pairing PIN.

### 2. Install the CLI
The quickest way to install the CLI globally for you or your AI agent (macOS/Linux):
```bash
curl -sL https://raw.githubusercontent.com/febritecno/wpicker/main/install.sh | bash -s -- install
```
*(To remove the CLI later, simply change `install` to `remove`)*

---

## 🔄 Workflow

Once both the Plugin and CLI are installed, your workflow looks like this:

### 1. Secure Pairing
Open your terminal and authenticate with your live site. You will need the 6-digit PIN displayed in your WP-Admin WPicker dashboard.
```bash
wpicker login https://your-production-site.com
```

### 2. Context & Discovery
Let your AI agent read the site's environment to understand active themes, plugins, and builder capabilities.
```bash
wpicker context
```

### 3. Build & Synchronize Code
Pull the current child theme code, let your AI modify it locally, and push it safely back. WPicker will automatically block syntax errors.
```bash
wpicker theme pull
# ... (Edit files locally or via AI) ...
wpicker theme push
```

### 4. Create Pages Remotely
Generate fully structured pages using your active Page Builder directly from the terminal.
```bash
wpicker page create --title "AI Landing Page" --content "<h1>Hello</h1>" --slug "ai-landing"
```

### 5. Security Scanning
Run a security audit on the site without leaving the terminal.
```bash
wpicker scan run
wpicker scan status
```

### 6. Rollback & History
If an AI push breaks the site's logic, instantly roll back to a previous snapshot.
```bash
wpicker history
wpicker rollback <manifest-id>
```

## 🛡️ Security & VirusTotal
Security is our top priority. All compiled WPicker CLI binaries (Windows, macOS, Linux) and the WordPress Plugin zip are 100% open-source and can be built directly from this repository. 

We encourage users to scan any downloaded release assets via [VirusTotal](https://www.virustotal.com/) to ensure they are clean, free from false positives (common with new Go binaries on Windows), and have not been tampered with.

---

## 📜 License

All Rights Reserved.
