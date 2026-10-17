package client

import (
	"context"
	"net/url"
	"strconv"
)

// MapPlugin is one installed plugin in the site map.
type MapPlugin struct {
	Slug    string `json:"slug"`
	Name    string `json:"name"`
	Version string `json:"version"`
	Active  bool   `json:"active"`
	Origin  string `json:"origin"`
	Update  bool   `json:"update"`
	File    string `json:"file"`
	Linked  bool   `json:"linked"`
}

// MapTheme is one installed theme in the site map.
type MapTheme struct {
	Slug    string `json:"slug"`
	Name    string `json:"name"`
	Version string `json:"version"`
	Active  bool   `json:"active"`
	Parent  string `json:"parent"`
	Origin  string `json:"origin"`
	File    string `json:"file"`
}

// SiteMap mirrors GET /inspect/map.
type SiteMap struct {
	WordPress struct {
		Version   string `json:"version"`
		PHP       string `json:"php"`
		Multisite bool   `json:"multisite"`
		Debug     bool   `json:"debug"`
		WPCron    bool   `json:"wp_cron"`
	} `json:"wordpress"`
	Plugins   []MapPlugin `json:"plugins"`
	MUPlugins []struct {
		Name    string `json:"name"`
		Version string `json:"version"`
		File    string `json:"file"`
	} `json:"mu_plugins"`
	Dropins []struct {
		File string `json:"file"`
		Is   string `json:"is"`
	} `json:"dropins"`
	Themes       []MapTheme `json:"themes"`
	Integrations struct {
		Email       []string `json:"email"`
		Builders    []string `json:"builders"`
		WooCommerce *struct {
			Version  string   `json:"version"`
			Gateways []string `json:"gateways"`
			HPOS     bool     `json:"hpos"`
		} `json:"woocommerce"`
	} `json:"integrations"`
}

// Map fetches the site map.
func (c *Client) Map(ctx context.Context) (*SiteMap, error) {
	var out SiteMap
	if err := c.Do(ctx, "GET", "/inspect/map", nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// Symbol is one place the code defines or hooks something.
type Symbol struct {
	Kind   string `json:"kind"`
	Name   string `json:"name"`
	File   string `json:"file"`
	Line   int    `json:"line"`
	Detail string `json:"detail"`
}

// SymbolsResponse mirrors GET /inspect/symbols.
type SymbolsResponse struct {
	Results  []Symbol `json:"results"`
	Count    int      `json:"count"`
	Total    int      `json:"total"`
	Files    int      `json:"files"`
	Parsed   int      `json:"parsed"`
	Complete bool     `json:"complete"`
	Skipped  []struct {
		Path   string `json:"path"`
		Reason string `json:"reason"`
	} `json:"skipped"`
	Scope string `json:"scope"`
	MS    int    `json:"ms"`
}

// Symbols searches the site's code index.
func (c *Client) Symbols(ctx context.Context, q, kind, in string, limit int, vendor bool) (*SymbolsResponse, error) {
	v := url.Values{
		"q":      {q},
		"kind":   {kind},
		"in":     {in},
		"limit":  {strconv.Itoa(limit)},
		"vendor": {strconv.FormatBool(vendor)},
	}
	var out SymbolsResponse
	if err := c.Do(ctx, "GET", "/inspect/symbols?"+v.Encode(), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}

// DoctorCheck is one line of the plugin's checklist.
type DoctorCheck struct {
	ID     string `json:"id"`
	Group  string `json:"group"`
	Status string `json:"status"`
	Title  string `json:"title"`
	Detail string `json:"detail"`
	Fix    string `json:"fix"`
}

// DoctorResponse mirrors GET /doctor.
type DoctorResponse struct {
	PluginVersion string         `json:"plugin_version"`
	Checks        []DoctorCheck  `json:"checks"`
	Summary       map[string]int `json:"summary"`
}

// Doctor fetches the plugin's checklist. loopback asks the site to request
// itself, which takes a few seconds.
func (c *Client) Doctor(ctx context.Context, loopback bool) (*DoctorResponse, error) {
	var out DoctorResponse
	if err := c.Do(ctx, "GET", "/doctor?loopback="+strconv.FormatBool(loopback), nil, &out); err != nil {
		return nil, err
	}
	return &out, nil
}
