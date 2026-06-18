# WPicker Deployment Guide

This guide covers how to deploy and configure WPicker for use in a production environment. 

WPicker consists of two parts:
1. **The Plugin ("The Eyes")**: Installed on your production WordPress site.
2. **The CLI ("The Hands")**: Installed on your local machine, inside a CI/CD pipeline, or configured for an AI agent.

---

## 1. Build the Release Artifacts

Before deploying, build the production-ready packages for the plugin and CLI.

Run the packaging command from the project root:

```bash
make package
```

This command will output two types of artifacts to the `dist/` folder:
- **Plugin:** `dist/wpicker-1.1.0.zip`
- **CLI binaries:** `dist/wpicker-darwin-arm64` (for macOS M-series) and `dist/wpicker-linux-amd64` (for Linux).

---

## 2. Install the WordPress Plugin (Production Site)

1. Log in to your production WordPress Admin Dashboard as an Administrator.
2. Navigate to **Plugins > Add New** and click **Upload Plugin**.
3. Select the `dist/wpicker-1.1.0.zip` file you built in the previous step and click **Install Now**.
4. Once installed, click **Activate Plugin**.

### Important Server Requirements
- **PHP version:** 7.4 or newer is recommended.
- **PHP CLI:** The `php` binary must be available on your server's `PATH` to allow WPicker to run `php -l` for syntax validation (the self-healing lint gate) before applying any changes.
- **Child Theme:** You MUST have an active Child Theme on your site. WPicker operates strictly on the active child theme and protects the parent theme from modifications.

---

## 3. Install the CLI (Local / AI Agent Environment)

Select the binary appropriate for your operating system from the `dist/` directory.

### Linux / AI Agent Environment (Ubuntu/Debian)
```bash
# Rename the binary
mv dist/wpicker-linux-amd64 wpicker
# Make it executable
chmod +x wpicker
# Move it to your local bin
sudo mv wpicker /usr/local/bin/wpicker

# Verify installation
wpicker --help
```

### macOS (Apple Silicon)
```bash
mv dist/wpicker-darwin-arm64 wpicker
chmod +x wpicker
sudo mv wpicker /usr/local/bin/wpicker

wpicker --help
```

### Automated Installation and Removal
We provide an automated script to install or remove the CLI globally in one line. It intelligently detects if you are running it locally or remotely.

**To Install via GitHub (Recommended for Agents/CI):**
```bash
curl -sL https://raw.githubusercontent.com/febritecno/wpicker/main/install.sh | bash -s -- install
```
*(This automatically downloads the latest compiled binary for your OS/Arch from GitHub Releases and installs it)*

**To Remove via GitHub:**
```bash
curl -sL https://raw.githubusercontent.com/febritecno/wpicker/main/install.sh | bash -s -- remove
```

**If you have cloned the repository locally:**
- Install: `./install.sh install` *(Builds from source)*
- Remove: `./install.sh remove`

### AI Agent Integration (Cursor, Claude, Devin)
To help AI agents seamlessly interact with WPicker, this repository includes a `.cursorrules` file. 
When an AI agent like Cursor opens this repository, it automatically reads these instructions. The rules teach the AI:
- How to use `wpicker context` to understand the environment.
- To always use `wpicker theme pull` before editing files.
- To use `wpicker theme push` to deploy and how to self-heal if the syntax gate fails.

---

## 4. Connect the CLI to the Live Site

The initial connection uses a secure 6-digit PIN and automatically provisions a WordPress Application Password, ensuring you never have to expose your primary WordPress admin password.

1. **On your Live WordPress Site:**
   - Go to **WPicker** in the admin sidebar.
   - You will see a randomly generated 6-digit PIN (e.g., `452 891`).
   - *Note: This PIN is temporary and expires automatically.*

2. **On your Local Machine (or AI Agent prompt):**
   - Run the login command, replacing the URL with your live site URL:
     ```bash
     wpicker login https://your-production-site.com
     ```
   - When prompted, enter the 6-digit PIN from the WordPress dashboard.

3. **Verify the connection:**
   - Fetch the live site context to ensure everything is hooked up properly:
     ```bash
     wpicker context
     ```

---

## 5. Typical Workflow

Once connected, you can manage the child theme directly from your local environment or prompt your AI agent to do so.

- **Pull existing theme files:**
  ```bash
  wpicker theme pull
  ```
- **Push updates:**
  ```bash
  wpicker theme push
  ```
  *(If there are syntax errors, the push will fail safely, returning a structured error to the CLI/Agent so it can heal the code and try again.)*
- **View History:**
  ```bash
  wpicker history
  ```
- **Rollback to a previous state:**
  ```bash
  wpicker rollback <manifest-id>
  ```
