<?php
/**
 * Interface for RediPress CLI commands
 */

namespace Geniem\RediPress\CLI;

/**
 * RediPress CLI Command interface
 */
interface Command {

    /**
     * The command itself
     *
     * @param array $args The command parameters.
     * @param array $assoc_args The optional command parameters.
     * @return boolean Whether the command succeeded or not.
     */
    public function run( array $args = [], array $assoc_args = [] ) : bool;

    /**
     * Must return the minimum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_min_parameters() : int;

    /**
     * Must return the maximum amount of parameters the command accepts.
     *
     * @return integer
     */
    public static function get_max_parameters() : int;
}
