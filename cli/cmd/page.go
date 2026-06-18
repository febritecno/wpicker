package cmd

import (
	"fmt"
	"os"

	"github.com/spf13/cobra"
	"wpicker/internal/client"
)

var (
	pageTitle   string
	pageBuilder string
	pageFile    string
)

var pageCmd = &cobra.Command{
	Use:   "page",
	Short: "Manage pages remotely",
}

var pageCreateCmd = &cobra.Command{
	Use:   "create",
	Short: "Create a page remotely for a specific builder",
	Long: `Creates a page remotely on the live site using a specific page builder structure (divi, elementor, gutenberg).
You must provide a file containing the raw payload (shortcodes, JSON, or block HTML).`,
	RunE: func(cmd *cobra.Command, args []string) error {
		if pageTitle == "" || pageBuilder == "" || pageFile == "" {
			return fmt.Errorf("missing required flags: --title, --builder, --file")
		}

		c, _, err := mustClient()
		if err != nil {
			return err
		}

		payload, err := os.ReadFile(pageFile)
		if err != nil {
			return fmt.Errorf("could not read file %s: %w", pageFile, err)
		}

		fmt.Printf("Creating page '%s' using builder '%s'...\n", pageTitle, pageBuilder)

		req := client.PageCreateRequest{
			Title:   pageTitle,
			Builder: pageBuilder,
			Payload: string(payload),
		}

		res, err := c.CreatePage(ctxFrom(cmd), req)
		if err != nil {
			return fmt.Errorf("failed to create page: %w", err)
		}

		fmt.Printf("✓ Page created successfully!\n")
		fmt.Printf("  Post ID: %d\n", res.PostID)
		fmt.Printf("  URL: %s\n", res.URL)
		
		return nil
	},
}

var pageUpdateCmd = &cobra.Command{
	Use:   "update [post-id]",
	Short: "Update an existing page remotely for a specific builder",
	Args:  cobra.ExactArgs(1),
	RunE: func(cmd *cobra.Command, args []string) error {
		if pageBuilder == "" || pageFile == "" {
			return fmt.Errorf("missing required flags: --builder, --file")
		}

		var postID int
		if _, err := fmt.Sscanf(args[0], "%d", &postID); err != nil {
			return fmt.Errorf("invalid post ID: %s", args[0])
		}

		c, _, err := mustClient()
		if err != nil {
			return err
		}

		payload, err := os.ReadFile(pageFile)
		if err != nil {
			return fmt.Errorf("could not read file %s: %w", pageFile, err)
		}

		fmt.Printf("Updating page %d using builder '%s'...\n", postID, pageBuilder)

		req := client.PageCreateRequest{
			Title:   pageTitle, // Optional for updates
			Builder: pageBuilder,
			Payload: string(payload),
		}

		res, err := c.UpdatePage(ctxFrom(cmd), postID, req)
		if err != nil {
			return fmt.Errorf("failed to update page: %w", err)
		}

		fmt.Printf("✓ Page updated successfully!\n")
		fmt.Printf("  URL: %s\n", res.URL)
		
		return nil
	},
}

func init() {
	pageCreateCmd.Flags().StringVar(&pageTitle, "title", "", "Title of the page")
	pageCreateCmd.Flags().StringVar(&pageBuilder, "builder", "", "Target page builder (divi, elementor, gutenberg)")
	pageCreateCmd.Flags().StringVar(&pageFile, "file", "", "Path to a file containing the raw payload for the builder")

	pageUpdateCmd.Flags().StringVar(&pageTitle, "title", "", "Optional new title of the page")
	pageUpdateCmd.Flags().StringVar(&pageBuilder, "builder", "", "Target page builder (divi, elementor, gutenberg)")
	pageUpdateCmd.Flags().StringVar(&pageFile, "file", "", "Path to a file containing the raw payload for the builder")

	pageCmd.AddCommand(pageCreateCmd)
	pageCmd.AddCommand(pageUpdateCmd)
	rootCmd.AddCommand(pageCmd)
}
