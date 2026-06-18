<?php
namespace WPicker\Security;

/**
 * Scanner handles vulnerability checks using the wpvulnerability.net API.
 */
class Scanner {
    /**
     * Run a vulnerability scan on all installed plugins.
     *
     * @return array
     */
    public function scan() {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        $report      = [];

        foreach ( $all_plugins as $plugin_file => $plugin_data ) {
            $slug = dirname( $plugin_file );
            if ( $slug === '.' ) {
                $slug = basename( $plugin_file, '.php' );
            }
            
            $installed_version = $plugin_data['Version'] ?? 'unknown';
            $api_url           = 'https://www.wpvulnerability.net/plugin/' . $slug . '/';
            
            $response = wp_remote_get( $api_url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $response ) ) {
                continue;
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! isset( $data['error'] ) || $data['error'] !== 0 || empty( $data['data']['vulnerability'] ) ) {
                continue;
            }

            $vulnerabilities = $data['data']['vulnerability'];
            $affecting       = [];

            foreach ( $vulnerabilities as $vuln ) {
                $operator = $vuln['operator'] ?? [];
                if ( $this->is_affected( $installed_version, $operator ) ) {
                    $severity = 'unknown';
                    $score    = null;

                    // Parse CVSS score/severity
                    $impact = $vuln['impact'] ?? [];
                    foreach ( [ 'cvss4', 'cvss3', 'cvss' ] as $key ) {
                        if ( isset( $impact[ $key ]['severity'] ) ) {
                            $severity = $impact[ $key ]['severity'];
                            $score    = $impact[ $key ]['score'] ?? null;
                            break;
                        }
                    }

                    $affecting[] = [
                        'name'     => $vuln['name'] ?? 'Unknown Vulnerability',
                        'severity' => strtolower( $severity ),
                        'score'    => $score,
                    ];
                }
            }

            if ( ! empty( $affecting ) ) {
                $report[] = [
                    'name'              => $plugin_data['Name'],
                    'slug'              => $slug,
                    'installed_version' => $installed_version,
                    'vulnerabilities'   => $affecting,
                ];
            }
        }

        return $report;
    }

    /**
     * Check if a specific version falls within the vulnerable range.
     */
    private function is_affected( $installed_version, $operator ) {
        if ( $installed_version === 'unknown' ) {
            return true;
        }

        $min_version  = $operator['min_version'] ?? null;
        $min_operator = $operator['min_operator'] ?? null;
        $max_version  = $operator['max_version'] ?? null;
        $max_operator = $operator['max_operator'] ?? null;

        if ( $min_version && $min_operator ) {
            if ( ! version_compare( $installed_version, $min_version, $this->map_operator( $min_operator ) ) ) {
                return false;
            }
        }

        if ( $max_version && $max_operator ) {
            if ( ! version_compare( $installed_version, $max_version, $this->map_operator( $max_operator ) ) ) {
                return false;
            }
        }

        return ! empty( $min_version ) || ! empty( $max_version );
    }

    private function map_operator( $operator ) {
        $op = strtolower( $operator );
        $map = [
            'lt' => '<',
            'le' => '<=',
            'eq' => '==',
            'ne' => '!=',
            'gt' => '>',
            'ge' => '>=',
        ];
        return $map[ $op ] ?? $op;
    }
}
