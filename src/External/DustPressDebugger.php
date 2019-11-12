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
        add_action( 'redipress/debug_query', [ $this, 'debug_query' ], 10, 2 );
    }

    /**
     * Add the data to DustPress Debugger
     *
     * @param object $query The query object.
     * @param array $results Query results.
     * @return void
     */
    public function debug_query( $query, $results ) {
        \DustPress\Debugger::set_debugger_data( 'RediPress', [
            'query'   => $query->redisearch_query,
            'params'  => $query->query_vars,
            'results' => count( $results ),
        ]);
    }
}
