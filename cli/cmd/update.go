package cmd

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"runtime"
	"strings"
	"time"

	"github.com/spf13/cobra"
)

var updateCmd = &cobra.Command{
	Use:   "update",
	Short: "Update the wpicker CLI to the latest version",
	RunE: func(cmd *cobra.Command, args []string) error {
		fmt.Println("Checking for updates...")
		
		client := &http.Client{Timeout: 10 * time.Second}
		req, err := http.NewRequestWithContext(cmd.Context(), "GET", "https://api.github.com/repos/febritecno/wpicker/releases/latest", nil)
		if err != nil {
			return err
		}
		req.Header.Set("Accept", "application/vnd.github.v3+json")

		res, err := client.Do(req)
		if err != nil {
			return fmt.Errorf("failed to check for updates: %w", err)
		}
		defer res.Body.Close()

		if res.StatusCode != 200 {
			return fmt.Errorf("GitHub API returned status %d", res.StatusCode)
		}

		var release struct {
			TagName string `json:"tag_name"`
			Assets  []struct {
				Name               string `json:"name"`
				BrowserDownloadURL string `json:"browser_download_url"`
			} `json:"assets"`
		}

		if err := json.NewDecoder(res.Body).Decode(&release); err != nil {
			return fmt.Errorf("failed to parse release info: %w", err)
		}

		latestVersion := strings.TrimPrefix(release.TagName, "v")
		if latestVersion == Version {
			fmt.Printf("You are already on the latest version (%s).\n", Version)
			return nil
		}

		fmt.Printf("New version available: %s (current: %s)\n", latestVersion, Version)

		targetBinary := fmt.Sprintf("wpicker-%s-%s", runtime.GOOS, runtime.GOARCH)
		var downloadURL string
		for _, asset := range release.Assets {
			if asset.Name == targetBinary {
				downloadURL = asset.BrowserDownloadURL
				break
			}
		}

		if downloadURL == "" {
			return fmt.Errorf("no pre-compiled binary available for your system (%s/%s)", runtime.GOOS, runtime.GOARCH)
		}

		fmt.Printf("Downloading %s...\n", targetBinary)
		dlRes, err := http.Get(downloadURL)
		if err != nil {
			return fmt.Errorf("download failed: %w", err)
		}
		defer dlRes.Body.Close()

		if dlRes.StatusCode != 200 {
			return fmt.Errorf("download failed with status %d", dlRes.StatusCode)
		}

		exePath, err := os.Executable()
		if err != nil {
			return fmt.Errorf("could not determine executable path: %w", err)
		}

		tmpPath := exePath + ".tmp"
		out, err := os.OpenFile(tmpPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
		if err != nil {
			return fmt.Errorf("failed to create temporary file (you may need sudo): %w", err)
		}

		if _, err := io.Copy(out, dlRes.Body); err != nil {
			out.Close()
			os.Remove(tmpPath)
			return fmt.Errorf("failed to write downloaded binary: %w", err)
		}
		out.Close()

		if err := os.Rename(tmpPath, exePath); err != nil {
			os.Remove(tmpPath)
			return fmt.Errorf("failed to replace binary (you may need sudo): %w", err)
		}

		fmt.Println("✓ Update successful! You are now running version", latestVersion)
		return nil
	},
}

func init() {
	rootCmd.AddCommand(updateCmd)
}
