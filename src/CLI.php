<?php
/**
 * RediPress CLI functionalities
 */

namespace Geniem\RediPress;

/**
 * CLI class
 */
class CLI {

    /**
     * Invoke the CLI functionalities
     *
     * @param array $args Command arguments.
     * @return void
     */
    public function __invoke( array $args = [], array $assoc_args = [] ) {
        // Check if we have at least one parameter for the command
        if ( isset( $args[0] ) ) {
            // Check if we have a class that corresponds to the asked command
            $class = __NAMESPACE__ . '\\CLI\\' . ucfirst( $args[0] );

            if ( class_exists( $class ) ) {
                $implements = class_implements( $class );

                if ( in_array( __NAMESPACE__ . '\\CLI\\Command', $implements, true ) ) {
                    $parameters = array_splice( $args, 1 );

                    $min_parameters = $class::get_min_parameters();
                    $max_parameters = $class::get_max_parameters();

                    switch ( true ) {
                        case count( $parameters ) < $min_parameters:
                            \WP_CLI::error( 'RediPress: command "' . $args[0] . '" needs at least ' . $min_parameters . ' parameters.' );
                            exit;
                        case count( $parameters ) > $max_parameters:
                            \WP_CLI::error( 'RediPress: command "' . $args[0] . '" accepts a maximum of ' . $max_parameters . ' parameters.' );
                            exit;
                        default:
                            $command = new $class();

                            $command->run( $parameters, $assoc_args );
                            exit;
                    }
                }
                else {
                    \WP_CLI::error( 'RediPress: class "' . $args[0] . '" found but it is not of correct type.' );
                }
            }
            else {
                \WP_CLI::error( 'RediPress: command "' . $args[0] . '" can not be found.' );
            }
        }
        // If not, ask for more.
        else {
            echo "Usage: wp redipress [command]\n";
            exit;
        }
    }
}
