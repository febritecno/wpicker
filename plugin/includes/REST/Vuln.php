<?php
namespace WPicker\REST;

use WP_REST_Server;
use WPicker\Security\Scanner;

class Vuln extends Base {
    public function register_routes() {
        register_rest_route( $this->namespace, '/vuln', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_vuln_report' ],
            'permission_callback' => [ $this, 'check_permission' ],
        ] );
    }

    public function get_vuln_report( $request ) {
        $scanner = new Scanner();
        $report  = $scanner->scan();
        
        return rest_ensure_response( [
            'ok'   => true,
            'data' => [
                'count'   => count( $report ),
                'plugins' => $report,
            ]
        ] );
    }
}
