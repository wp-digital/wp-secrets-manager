<?php

namespace Innocode\SecretsManager;

class SecretsManager
{
    const PREFIX = 'innocode_secrets_manager_';

    /**
     * @var string
     */
    protected $namespace;
    /**
     * @var int
     */
    protected $expiration = 20 * 60;

    /**
     * @param string $namespace
     */
    public function __construct( string $namespace )
    {
        $this->namespace = $namespace;
    }

    /**
     * @return string
     */
    public function get_namespace() : string
    {
        return $this->namespace;
    }

    /**
     * @param int $expiration
     * @return void
     */
    public function set_expiration( int $expiration ) : void
    {
        $this->expiration = $expiration;
    }

    /**
     * @return int
     */
    public function get_expiration() : int
    {
        return $this->expiration;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function key( string $key ) : string
    {
        return "{$this->get_prefix()}$key";
    }

    /**
     * @return string
     */
    public function get_prefix() : string
    {
        return static::PREFIX . "{$this->get_namespace()}_";
    }

    /**
     * @param string $key
     * @return array
     */
    public function init( string $key ) : array
    {
        list( $secret, $hash ) = static::generate();

        $is_set = $this->set( $key, $hash );

        return [ $is_set, $secret ];
    }

    /**
     * @return array
     */
    public static function generate() : array
    {
        $secret = function_exists( 'wp_generate_password' ) ? wp_generate_password( 32 ) : '';
        $hash = function_exists( 'wp_hash_password' ) ? wp_hash_password( $secret ) : '';

        return [ $secret, $hash ];
    }

    /**
     * @param string $key
     * @param string $hash
     *
     * @return bool
     */
    public function set( string $key, string $hash ) : bool
    {
        return $this->force_db_transient( __FUNCTION__, $key, $hash, $this->get_expiration() );
    }

    /**
     * @param string $key
     *
     * @return string|false
     */
    public function get( string $key )
    {
        return $this->force_db_transient( __FUNCTION__, $key );
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    public function delete( string $key ) : bool
    {
        return $this->force_db_transient( __FUNCTION__, $key );
    }

    /**
     * Forces DB transient to make sure that it will be stored and to have possibility to expire.
     *
     * @param string $method
     * @param string $key
     * @param ...$args
     *
     * @return mixed
     */
    protected function force_db_transient( string $method, string $key, ...$args )
    {
        $using_ext_object_cache = function_exists( 'wp_using_ext_object_cache' ) &&
            wp_using_ext_object_cache( false );

        $function = "{$method}_transient";
        $result = $function( $this->key( $key ), ...$args );

        if ( function_exists( 'wp_using_ext_object_cache' ) ) {
            wp_using_ext_object_cache( $using_ext_object_cache );
        }

        return $result;
    }

    /**
     * @return bool
     */
    public function flush() : bool
    {
        global $wpdb;

        return isset( $wpdb ) && $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( "_transient_{$this->get_prefix()}" ) . '%',
                $wpdb->esc_like( "_transient_timeout_{$this->get_prefix()}" ) . '%'
            )
        );
    }

    /**
     * @return bool
     */
    public function flush_expired() : bool
    {
        if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
            return false;
        }

        global $wpdb;

        return isset( $wpdb ) && $wpdb->query(
            $wpdb->prepare(
                "DELETE a, b FROM $wpdb->options a, $wpdb->options b
                WHERE a.option_name LIKE %s
                AND a.option_name NOT LIKE %s
                AND b.option_name = CONCAT( '_transient_timeout_', SUBSTRING( a.option_name, 12 ) )
                AND b.option_value < %d",
                $wpdb->esc_like( "_transient_{$this->get_prefix()}" ) . '%',
                $wpdb->esc_like( "_transient_timeout_{$this->get_prefix()}" ) . '%',
                time()
            )
        );
    }
}
