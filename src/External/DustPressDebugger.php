<?php
/**
 * RediPress DustPress Debugger extension
 */

namespace Geniem\RediPress\External;

/**
 * RediPress DustPress Debugger extension class
 */
class DustPressDebugger {

    /**
     * Constructor
     */
    public function __construct() {
        // Add the debug data action
        add_action( 'redipress/debug_query', [ $this, 'debug_query' ], 10, 3 );
    }

    /**
     * Add the data to DustPress Debugger
     *
     * @param object $query The query object.
     * @param array  $results Query results.
     * @param string $type Whether we are dealing with posts or users query.
     * @return void
     */
    public function debug_query( $query, $results, $type ) {
        switch ( $type ) {
            case 'posts':
                \DustPress\Debugger::set_debugger_data( 'RediPress', [
                    'query'   => $query->redisearch_query,
                    'params'  => $query->query_vars,
                    'results' => count( $results ),
                ]);
                break;
            case 'users':
                \DustPress\Debugger::set_debugger_data( 'RediPress', [
                    'query'   => $query->request,
                    'params'  => $query->query_vars,
                    'results' => count( $results ),
                ]);
                break;
        }
    }
}
