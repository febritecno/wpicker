SHELL := /bin/bash
GO    := go
NPX   := npx
PHP   := php

GO_MODULE  := wpicker
GO_BIN     := bin/wpicker
PLUGIN_DIR := plugin
CLI_DIR    := cli
PHP_TESTS  := $(PLUGIN_DIR)/tests

.DEFAULT_GOAL := help

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

# ------------------------------------------------------------------
# Dev environment (wp-env / Docker)
# ------------------------------------------------------------------
.PHONY: dev stop clean env-cli
dev: ## Start the WordPress dev environment (wp-env)
	$(NPX) wp-env start
	@echo "WP:      http://localhost:8888"
	@echo "Admin:   admin / password"

stop: ## Stop wp-env
	$(NPX) wp-env stop

clean: ## Destroy wp-env (data loss!)
	$(NPX) wp-env destroy

env-cli: ## Open a shell in the wp-env CLI container
	$(NPX) wp-env run cli bash

# ------------------------------------------------------------------
# CLI (Go)
# ------------------------------------------------------------------
.PHONY: build-cli install-cli test-go vet-go
build-cli: ## Build the wpicker CLI into ./bin
	@mkdir -p bin
	cd $(CLI_DIR) && $(GO) build -trimpath -o ../$(GO_BIN) .

install-cli: build-cli ## Install the wpicker CLI into $$GOBIN
	cp $(GO_BIN) $$($(GO) env GOBIN 2>/dev/null || echo $$HOME/.local/bin)/

test-go: ## Run Go tests
	cd $(CLI_DIR) && $(GO) test ./...

vet-go: ## Run go vet
	cd $(CLI_DIR) && $(GO) vet ./...

# ------------------------------------------------------------------
# Plugin (PHP)
# ------------------------------------------------------------------
.PHONY: lint-php test-php composer-install
lint-php: ## Run php -l on plugin sources
	@find $(PLUGIN_DIR)/includes -name '*.php' -print0 | xargs -0 -n1 $(PHP) -l

composer-install: ## Install composer deps (dev only)
	cd $(PLUGIN_DIR) && composer install --no-interaction --prefer-dist

test-php: ## Run PHPUnit (inside wp-env for DB-dependent tests)
	$(NPX) wp-env run cli composer -- -d /var/www/html/wp-content/plugins/wpicker test

# ------------------------------------------------------------------
# Composite targets
# ------------------------------------------------------------------
.PHONY: test lint
test: test-go lint-php ## Run all fast tests

lint: vet-go lint-php ## Run all linters

# ------------------------------------------------------------------
# Packaging
# ------------------------------------------------------------------
.PHONY: package-plugin
package-plugin: ## Zip the plugin for distribution -> dist/wpicker.zip
	@rm -rf dist && mkdir -p dist/staging/wpicker
	@rsync -a --exclude='vendor/' --exclude='tests/' --exclude='.git/' --exclude='node_modules/' $(PLUGIN_DIR)/ dist/staging/wpicker/
	@cd dist/staging && zip -r ../wpicker-1.1.0.zip wpicker >/dev/null
	@rm -rf dist/staging
	@echo "Built: dist/wpicker-1.1.0.zip"

.PHONY: package-cli
package-cli: ## Cross-compile the CLI for darwin/linux -> dist/
	@rm -rf dist && mkdir -p dist
	cd $(CLI_DIR) && \
	  $(GO) build -trimpath -o ../dist/wpicker-darwin-arm64 . && \
	  $(GO) build -trimpath -o ../dist/wpicker-linux-amd64 . && \
	  echo "Built: dist/wpicker-{darwin-arm64,linux-amd64}"

.PHONY: package
package: package-plugin package-cli ## Build all release artifacts
