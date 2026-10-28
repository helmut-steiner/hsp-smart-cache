<?php
/**
 * Drop-in object cache for HSP Smart Cache.
 * File-based persistent cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'HSPSC_File_Object_Cache' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
    class HSPSC_File_Object_Cache {
        const FILE_HEADER = "<?php exit; __halt_compiler();\nHSPSC2\n";

        protected $cache = array();
        protected $expires = array();
        protected $global_groups = array();
        protected $non_persistent_groups = array();
        protected $cache_dir;
        protected $cleanup_lock_file;
        protected $blog_id = 0;
        protected $storage_ready = false;
        protected $held_locks = array();
        protected $signing_key = null;
        protected $next_cleanup_check = 0;
        protected $loading_settings = false;

        public function __construct() {
            $this->cache_dir = WP_CONTENT_DIR . '/cache/hspsc/object';
            $this->cleanup_lock_file = $this->cache_dir . '/.gc-lock';
            $this->blog_id = $this->get_current_blog_id();
            $this->storage_ready = $this->protect_storage();
            $marker = $this->cache_dir . '/.protected-format.php';
            if ( $this->storage_ready && ! $this->fs_exists( $marker ) ) {
                $this->remove_legacy_files( $this->cache_dir );
                $this->fs_put_contents( $marker, "<?php exit;\n" );
            }
        }

        protected function get_filesystem() {
            return null;
        }

        protected function fs_is_dir( $path ) {
            return is_dir( $path ) && ! is_link( $path );
        }

        protected function fs_mkdir( $path, $mode = 0755, $recursive = true ) {
            if ( is_link( $path ) ) {
                return false;
            }
            return is_dir( $path ) || mkdir( $path, $mode, $recursive );
        }

        protected function fs_put_contents( $path, $contents ) {
            return $this->atomic_write( $path, $contents );
        }

        protected function fs_exists( $path ) {
            return is_file( $path ) && ! is_link( $path );
        }

        protected function fs_get_contents( $path ) {
            return $this->fs_exists( $path ) ? file_get_contents( $path ) : false;
        }

        protected function fs_delete( $path ) {
            return ! file_exists( $path ) || unlink( $path );
        }

        protected function fs_rmdir( $path, $recursive = false ) {
            return ! is_link( $path ) && rmdir( $path );
        }

        public function add( $key, $data, $group = 'default', $expire = 0 ) {
            $result = $this->with_lock( $key, $group, function () use ( $key, $data, $group, $expire ) {
                $this->get( $key, $group, true, $found );
                return $found ? false : $this->store( $key, $data, $group, $this->expiration_time( $expire ) );
            } );
            if ( $result && $this->can_persist( $group ) ) {
                $this->maybe_cleanup_expired_cache();
            }
            return $result;
        }

        public function replace( $key, $data, $group = 'default', $expire = 0 ) {
            $result = $this->with_lock( $key, $group, function () use ( $key, $data, $group, $expire ) {
                $this->get( $key, $group, true, $found );
                return $found ? $this->store( $key, $data, $group, $this->expiration_time( $expire ) ) : false;
            } );
            if ( $result && $this->can_persist( $group ) ) {
                $this->maybe_cleanup_expired_cache();
            }
            return $result;
        }

        public function set( $key, $data, $group = 'default', $expire = 0 ) {
            $result = $this->with_lock( $key, $group, function () use ( $key, $data, $group, $expire ) {
                return $this->store( $key, $data, $group, $this->expiration_time( $expire ) );
            } );
            if ( $result && $this->can_persist( $group ) ) {
                $this->maybe_cleanup_expired_cache();
            }
            return $result;
        }

        protected function store( $key, $data, $group, $expiration ) {
            $group = $this->normalize_group( $group );
            $id = $this->cache_key( $key, $group );
            $this->cache[ $id ] = is_object( $data ) ? clone $data : $data;
            $this->expires[ $id ] = $expiration;
            if ( ! $this->can_persist( $group ) ) {
                return true;
            }
            $file = $this->get_file_path( $key, $group );
            $serialized = serialize( $this->cache[ $id ] );
            $signature = $this->payload_signature( $file, $expiration, $serialized );
            return $this->fs_put_contents( $file, self::FILE_HEADER . $expiration . '|' . $signature . "\n" . $serialized );
        }

        public function get( $key, $group = 'default', $force = false, &$found = null ) {
            $group = $this->normalize_group( $group );
            $id = $this->cache_key( $key, $group );
            $persistent = $this->can_persist( $group );
            if ( array_key_exists( $id, $this->cache ) && ( ! $force || ! $persistent ) ) {
                if ( empty( $this->expires[ $id ] ) || time() < $this->expires[ $id ] ) {
                    $found = true;
                    return is_object( $this->cache[ $id ] ) ? clone $this->cache[ $id ] : $this->cache[ $id ];
                }
                unset( $this->cache[ $id ], $this->expires[ $id ] );
            }
            if ( ! $persistent ) {
                $found = false;
                return false;
            }
            $file = $this->get_file_path( $key, $group );
            $raw = $this->fs_get_contents( $file );
            $payload = $raw === false ? false : $this->decode_payload( $raw, $file );
            if ( $payload === false || ( $payload['expire'] > 0 && time() >= $payload['expire'] ) ) {
                unset( $this->cache[ $id ], $this->expires[ $id ] );
                if ( $raw !== false ) {
                    $this->delete_if_unchanged( $file, $raw );
                }
                $found = false;
                return false;
            }
            $this->cache[ $id ] = $payload['value'];
            $this->expires[ $id ] = $payload['expire'];
            $found = true;
            return is_object( $payload['value'] ) ? clone $payload['value'] : $payload['value'];
        }

        public function delete( $key, $group = 'default' ) {
            return $this->with_lock( $key, $group, function () use ( $key, $group ) {
                $id = $this->cache_key( $key, $group );
                unset( $this->cache[ $id ], $this->expires[ $id ] );
                return ! $this->can_persist( $group ) || $this->fs_delete( $this->get_file_path( $key, $group ) );
            } );
        }

        public function flush() {
            $this->cache = array();
            $this->expires = array();
            $this->next_cleanup_check = 0;
            $this->delete_dir_contents( $this->cache_dir );
            $this->storage_ready = $this->protect_storage();
            return true;
        }

        public function incr( $key, $offset = 1, $group = 'default' ) {
            $result = $this->with_lock( $key, $group, function () use ( $key, $offset, $group ) {
                $value = $this->get( $key, $group, true, $found );
                if ( ! $found || ! is_numeric( $value ) ) {
                    return false;
                }
                $value = max( 0, (int) $value + (int) $offset );
                $id = $this->cache_key( $key, $group );
                return $this->store( $key, $value, $group, $this->expires[ $id ] ) ? $value : false;
            } );
            if ( $result !== false && $this->can_persist( $group ) ) {
                $this->maybe_cleanup_expired_cache();
            }
            return $result;
        }

        public function decr( $key, $offset = 1, $group = 'default' ) {
            return $this->incr( $key, -abs( (int) $offset ), $group );
        }

        public function cleanup_expired_cache() {
            return $this->delete_expired_files( $this->cache_dir );
        }

        public function add_global_groups( $groups ) {
            $this->global_groups = array_unique( array_merge( $this->global_groups, array_map( array( $this, 'normalize_group' ), (array) $groups ) ) );
        }

        public function add_non_persistent_groups( $groups ) {
            $this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, array_map( array( $this, 'normalize_group' ), (array) $groups ) ) );
        }

        public function switch_to_blog( $blog_id ) {
            $this->blog_id = max( 0, (int) $blog_id );
            $this->cache = array();
            $this->expires = array();
        }

        protected function cache_key( $key, $group ) {
            $group = $this->normalize_group( $group );
            return $this->get_blog_prefix( $group ) . strlen( $group ) . ':' . $group . ':' . $this->normalize_key( $key );
        }

        protected function get_file_path( $key, $group ) {
            $group = $this->normalize_group( $group );
            $directory = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $group );
            if ( $directory !== $group ) {
                $directory .= '-' . md5( $group );
            }
            $hash = md5( $this->cache_key( $key, $group ) );
            return $this->cache_dir . '/' . $directory . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.php';
        }

        protected function expiration_time( $expire ) {
            $expire = $this->normalize_expire( $expire );
            return $expire > 0 ? time() + $expire : 0;
        }

        protected function normalize_expire( $expire ) {
            $expire = max( 0, (int) $expire );
            $default_ttl = max( 0, (int) $this->get_setting( 'object_cache_default_ttl', 604800 ) );
            $max_ttl = max( 0, (int) $this->get_setting( 'object_cache_max_ttl', 2592000 ) );
            if ( $expire <= 0 ) {
                $expire = $default_ttl;
            }
            if ( $max_ttl > 0 && ( $expire <= 0 || $expire > $max_ttl ) ) {
                $expire = $max_ttl;
            }
            return $expire;
        }

        protected function get_setting( $key, $default ) {
            if ( $this->loading_settings || ! class_exists( 'HSPSC_Settings', false ) ) {
                return $default;
            }
            // Loading options can itself cache alloptions before the settings memo exists.
            $this->loading_settings = true;
            try {
                return HSPSC_Settings::get( $key, $default );
            } finally {
                $this->loading_settings = false;
            }
        }

        protected function get_signing_key() {
            if ( null === $this->signing_key ) {
                // wp-config constants cannot change after this request has loaded them.
                $this->signing_key = $this->derive_signing_key();
            }
            return $this->signing_key;
        }

        protected function derive_signing_key() {
            $parts = array();
            foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
                if ( defined( $name ) && is_string( constant( $name ) ) && strlen( constant( $name ) ) >= 32 && constant( $name ) !== 'put your unique phrase here' ) {
                    $parts[] = $name . '=' . constant( $name );
                }
            }
            // No stable, secret wp-config keys: keep this request memory-only.
            return $parts ? hash( 'sha256', implode( '|', $parts ), true ) : '';
        }

        protected function can_persist( $group ) {
            return $this->storage_ready && ! $this->is_non_persistent_group( $this->normalize_group( $group ) ) && $this->get_signing_key() !== '';
        }

        protected function payload_signature( $file, $expiration, $serialized ) {
            return hash_hmac( 'sha256', basename( $file ) . '|' . $expiration . "\n" . $serialized, $this->get_signing_key() );
        }

        protected function decode_payload( $raw, $file, $deserialize = true ) {
            if ( $this->get_signing_key() === '' || strpos( $raw, self::FILE_HEADER ) !== 0 ) {
                return false;
            }
            $parts = explode( "\n", substr( $raw, strlen( self::FILE_HEADER ) ), 2 );
            if ( count( $parts ) !== 2 || ! preg_match( '/^([0-9]+)\|([a-f0-9]{64})$/D', $parts[0], $matches ) ) {
                return false;
            }
            $expiration = (int) $matches[1];
            if ( ! hash_equals( $matches[2], $this->payload_signature( $file, $expiration, $parts[1] ) ) ) {
                return false;
            }
            if ( ! $deserialize ) {
                return array( 'expire' => $expiration );
            }
            // Authentication is checked first, preserving legitimate WordPress cached objects.
            $value = @unserialize( $parts[1], array( 'allowed_classes' => true ) );
            if ( $value === false && $parts[1] !== 'b:0;' ) {
                return false;
            }
            return array( 'expire' => $expiration, 'value' => $value );
        }

        protected function with_lock( $key, $group, $callback ) {
            if ( ! $this->can_persist( $group ) ) {
                return $callback();
            }
            return $this->with_file_lock( $this->get_file_path( $key, $group ), $callback );
        }

        protected function with_file_lock( $data_file, $callback ) {
            $dir = $this->cache_dir . '/.locks';
            if ( ! $this->safe_directory( $dir ) ) {
                return false;
            }
            // A bounded set of stable lock files avoids inode replacement races and unbounded growth.
            $file = $dir . '/' . substr( basename( $data_file ), 0, 2 ) . '.php';
            if ( isset( $this->held_locks[ $file ] ) ) {
                return $callback();
            }
            if ( is_link( $file ) ) {
                return false;
            }
            $handle = fopen( $file, 'c+b' );
            if ( ! $handle ) {
                return false;
            }
            if ( ! flock( $handle, LOCK_EX ) ) {
                fclose( $handle );
                return false;
            }
            try {
                $this->held_locks[ $file ] = true;
                return $callback();
            } finally {
                unset( $this->held_locks[ $file ] );
                flock( $handle, LOCK_UN );
                fclose( $handle );
            }
        }

        protected function delete_if_unchanged( $file, $observed ) {
            return $this->with_file_lock( $file, function () use ( $file, $observed ) {
                return $this->fs_get_contents( $file ) === $observed && $this->fs_delete( $file );
            } );
        }

        protected function protect_storage() {
            if ( ! $this->safe_directory( $this->cache_dir ) ) {
                return false;
            }
            $files = array(
                'index.php' => "<?php exit;\n",
                '.htaccess' => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
                'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n",
            );
            foreach ( $files as $name => $contents ) {
                $path = $this->cache_dir . '/' . $name;
                if ( is_link( $path ) ) {
                    return false;
                }
                if ( ! is_file( $path ) && ! $this->fs_put_contents( $path, $contents ) ) {
                    return false;
                }
            }
            return true;
        }

        protected function safe_directory( $dir ) {
            $root = rtrim( str_replace( '\\', '/', $this->cache_dir ), '/' );
            $dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
            if ( $dir !== $root && strpos( $dir, $root . '/' ) !== 0 ) {
                return false;
            }
            $cursor = $root;
            if ( is_link( $cursor ) || ( ! is_dir( $cursor ) && ! $this->fs_mkdir( $cursor, 0755, true ) ) ) {
                return false;
            }
            foreach ( explode( '/', ltrim( substr( $dir, strlen( $root ) ), '/' ) ) as $part ) {
                if ( $part === '' ) {
                    continue;
                }
                $cursor .= '/' . $part;
                if ( is_link( $cursor ) || ( ! is_dir( $cursor ) && ! $this->fs_mkdir( $cursor, 0755, false ) ) ) {
                    return false;
                }
            }
            return true;
        }

        protected function maybe_cleanup_expired_cache() {
            $now = time();
            if ( $now < $this->next_cleanup_check ) {
                return;
            }
            $mtime = is_file( $this->cleanup_lock_file ) ? filemtime( $this->cleanup_lock_file ) : false;
            if ( $mtime !== false && $now - $mtime < 3600 ) {
                $this->next_cleanup_check = $mtime + 3600;
                return;
            }
            if ( ! is_link( $this->cleanup_lock_file ) && $this->fs_put_contents( $this->cleanup_lock_file, (string) $now ) ) {
                // A failed marker write must not make every subsequent write scan the cache.
                $this->next_cleanup_check = $now + 3600;
                $this->cleanup_expired_cache();
            }
        }

        protected function remove_legacy_files( $dir ) {
            if ( ! is_dir( $dir ) || is_link( $dir ) ) {
                return;
            }
            foreach ( (array) scandir( $dir ) as $item ) {
                if ( $item === '.' || $item === '..' || $item === '.locks' ) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if ( is_link( $path ) ) {
                    continue;
                }
                if ( is_dir( $path ) ) {
                    $this->remove_legacy_files( $path );
                } elseif ( substr( $item, -6 ) === '.cache' ) {
                    $this->fs_delete( $path );
                }
            }
        }

        protected function delete_expired_files( $dir ) {
            if ( ! is_dir( $dir ) || is_link( $dir ) ) {
                return 0;
            }
            $deleted = 0;
            foreach ( (array) scandir( $dir ) as $item ) {
                if ( $item === '.' || $item === '..' || $item === '.locks' ) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if ( is_link( $path ) ) {
                    continue;
                }
                if ( is_dir( $path ) ) {
                    $deleted += $this->delete_expired_files( $path );
                    continue;
                }
                if ( ! preg_match( '/^[a-f0-9]{32}\.(?:php|cache)$/D', $item ) ) {
                    continue;
                }
                // GC authenticates the envelope without ever instantiating cached classes.
                $raw = $this->fs_get_contents( $path );
                $payload = $raw === false ? false : $this->decode_payload( $raw, $path, false );
                if ( $payload === false || ( $payload['expire'] > 0 && time() >= $payload['expire'] ) ) {
                    if ( $this->delete_if_unchanged( $path, $raw ) ) {
                        $deleted++;
                    }
                }
            }
            return $deleted;
        }

        protected function normalize_key( $key ) {
            if ( is_scalar( $key ) || ( is_object( $key ) && method_exists( $key, '__toString' ) ) ) {
                return (string) $key;
            }
            return md5( serialize( $key ) );
        }

        protected function normalize_group( $group ) {
            return (string) $group !== '' ? (string) $group : 'default';
        }

        protected function get_blog_prefix( $group ) {
            if ( $this->is_global_group( $group ) ) {
                return 'global:';
            }
            return function_exists( 'is_multisite' ) && is_multisite() ? 'blog:' . $this->blog_id . ':' : 'site:';
        }

        protected function get_current_blog_id() {
            return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
        }

        protected function is_global_group( $group ) {
            return in_array( $group, $this->global_groups, true );
        }

        protected function is_non_persistent_group( $group ) {
            return in_array( $group, $this->non_persistent_groups, true );
        }

        protected function delete_dir_contents( $dir ) {
            if ( ! is_dir( $dir ) || is_link( $dir ) ) {
                return;
            }
            foreach ( (array) scandir( $dir ) as $item ) {
                if ( in_array( $item, array( '.', '..', '.locks', '.protected-format.php', 'index.php', '.htaccess', 'web.config' ), true ) ) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if ( is_link( $path ) ) {
                    continue;
                }
                if ( is_dir( $path ) ) {
                    $this->delete_dir_contents( $path );
                    $this->fs_rmdir( $path );
                } else {
                    $this->fs_delete( $path );
                }
            }
        }

        protected function atomic_write( $path, $contents ) {
            if ( is_link( $path ) || ! $this->safe_directory( dirname( $path ) ) ) {
                return false;
            }
            // Temporary cache payloads also have a PHP extension and the same exit guard.
            $tmp = dirname( $path ) . '/.hspsc-' . bin2hex( random_bytes( 12 ) ) . '.php';
            $handle = fopen( $tmp, 'x+b' );
            if ( ! $handle ) {
                return false;
            }
            $written = fwrite( $handle, $contents );
            fflush( $handle );
            fclose( $handle );
            @chmod( $tmp, 0600 );
            if ( $written === strlen( $contents ) && @rename( $tmp, $path ) ) {
                return true;
            }
            @unlink( $tmp );
            return false;
        }
    }
}

global $wp_object_cache;

if ( ! function_exists( 'wp_cache_init' ) ) {
    function wp_cache_init() {
        global $wp_object_cache;
        $wp_object_cache = new HSPSC_File_Object_Cache();
    }
}
if ( ! function_exists( 'wp_cache_add' ) ) {
    function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
        global $wp_object_cache;
        return $wp_object_cache->add( $key, $data, $group, $expire );
    }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
    function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
        global $wp_object_cache;
        return $wp_object_cache->set( $key, $data, $group, $expire );
    }
}
if ( ! function_exists( 'wp_cache_replace' ) ) {
    function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
        global $wp_object_cache;
        return $wp_object_cache->replace( $key, $data, $group, $expire );
    }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
    function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
        global $wp_object_cache;
        return $wp_object_cache->get( $key, $group, $force, $found );
    }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group = '' ) {
        global $wp_object_cache;
        return $wp_object_cache->delete( $key, $group );
    }
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
    function wp_cache_flush() {
        global $wp_object_cache;
        return $wp_object_cache->flush();
    }
}
if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
    function wp_cache_add_global_groups( $groups ) {
        global $wp_object_cache;
        $wp_object_cache->add_global_groups( $groups );
    }
}
if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
    function wp_cache_add_non_persistent_groups( $groups ) {
        global $wp_object_cache;
        $wp_object_cache->add_non_persistent_groups( $groups );
    }
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
    function wp_cache_incr( $key, $offset = 1, $group = '' ) {
        global $wp_object_cache;
        return $wp_object_cache->incr( $key, $offset, $group );
    }
}
if ( ! function_exists( 'wp_cache_decr' ) ) {
    function wp_cache_decr( $key, $offset = 1, $group = '' ) {
        global $wp_object_cache;
        return $wp_object_cache->decr( $key, $offset, $group );
    }
}
if ( ! function_exists( 'wp_cache_close' ) ) {
    function wp_cache_close() {
        return true;
    }
}
if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
    function wp_cache_switch_to_blog( $blog_id ) {
        global $wp_object_cache;
        if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'switch_to_blog' ) ) {
            $wp_object_cache->switch_to_blog( $blog_id );
        }
        return true;
    }
}
