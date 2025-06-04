<?php
/**
 * RediPress Redis client wrapper file.
 */

namespace Geniem\RediPress\Redis;

use Predis\Client as Predis;

/**
 * RediPress wrapper for the Predis client.
 */
class Client {

    /**
     * The Predis client
     *
     * @var Predis
     */
    public $predis;

    /**
     * Connect to Redis with the Predis client.
     *
     * @param string  $hostname Redis hostname.
     * @param integer $port     Redis port.
     * @param integer $db       Redis database number.
     * @param string  $password Redis password (optional).
     * @return self
     */
    public function connect( string $hostname, int $port, int $db, string $password = null ): self {
        $this->predis = new Predis([
            'scheme'   => 'tcp',
            'host'     => $hostname,
            'port'     => $port,
            'database' => $db,
            'password' => $password,
        ]);

        $this->predis->connect();

        // You can use this in your theme to get a Client instance.
        add_filter( 'redipress/client', function ( $client = null ) {
            return $this;
        });

        return $this;
    }

    /**
     * Flush the redis database
     *
     * @return void
     */
    public function flush_all() {
        $this->predis->flushAll();
    }

    /**
     * Call Predis pipeline function
     *
     * @param boolean $use_pipeline Placeholder parameter.
     * @return mixed
     */
    public function multi( bool $use_pipeline = false ) {
        return $this->predis->pipeline();
    }

    /**
     * Run a raw command to Redis.
     *
     * @param string $command   The command to run.
     * @param array  $arguments The arguments for the command.
     * @return mixed The return value.
     */
    public function raw_command( string $command, array $arguments = [] ) {
        $prepared_arguments = $this->prepare_raw_command_arguments( $command, $arguments );

        $raw_result = $this->predis->executeRaw( $prepared_arguments );

        return $this->normalize_raw_command_result( $raw_result );
    }

    /**
     * Prepare raw command arguments for appropriate format.
     *
     * @param string $command   The command to run.
     * @param array  $arguments The arguments to prepare.
     * @return array Prepared arguments with the command in front.
     */
    public function prepare_raw_command_arguments( string $command, array $arguments ): array {
        $arguments = array_map( function ( $argument ) {
            return is_scalar( $argument ) ? $argument : (string) $argument;
        }, $arguments );

        array_unshift( $arguments, $command );

        return $arguments;
    }

    /**
     * Convert an associative array to one with alternating keys and values.
     *
     * @param array $array The array to convert.
     * @return array
     */
    public function convert_associative( array $array ): array {
        $return = [];

        foreach ( $array as $key => $value ) {
            $return[] = $key;
            $return[] = $value;
        }

        return $return;
    }

    /**
     * Normalize the raw command return value.
     *
     * @param mixed $raw_result The result to normalize.
     * @return mixed The normalized result.
     */
    public function normalize_raw_command_result( $raw_result ) {
        return $raw_result === 'OK' ? true : $raw_result;
    }
}
