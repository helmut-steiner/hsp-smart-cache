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
        protected $cache = array();
        protected $global_groups = array();
        protected $non_persistent_groups = array();
        protected $cache_dir;
        protected $cleanup_lock_file;

        protected function get_filesystem() {
            return null;
        }

        protected function fs_is_dir( $path ) {
            return is_dir( $path );
        }

        protected function fs_mkdir( $path, $mode = 0755, $recursive = true ) {
            if ( $recursive ) {
                return wp_mkdir_p( $path );
            }
            return mkdir( $path, $mode );
        }

        protected function fs_put_contents( $path, $contents ) {
            return file_put_contents( $path, $contents ) !== false;
        }

        protected function fs_exists( $path ) {
            return file_exists( $path );
        }

        protected function fs_get_contents( $path ) {
            return file_get_contents( $path );
        }

        protected function fs_delete( $path ) {
            return ! file_exists( $path ) || unlink( $path );
        }

        protected function fs_rmdir( $path, $recursive = false ) {
            return rmdir( $path );
        }

        public function __construct() {
            $this->cache_dir = WP_CONTENT_DIR . '/cache/hspsc/object';
            $this->cleanup_lock_file = $this->cache_dir . '/.gc-lock';
            if ( ! $this->fs_is_dir( $this->cache_dir ) ) {
                $this->fs_mkdir( $this->cache_dir, 0755, true );
            }
        }

        public function add( $key, $data, $group = 'default', $expire = 0 ) {
            if ( $this->get( $key, $group, false, $found ) && $found ) {
                return false;
            }
            return $this->set( $key, $data, $group, $expire );
        }

        public function set( $key, $data, $group = 'default', $expire = 0 ) {
            $group = $this->sanitize_group( $group );
            $key   = $this->sanitize_key( $key );
            $id    = $this->cache_key( $key, $group );
            $expire = $this->normalize_expire( $expire );

            $this->cache[ $id ] = $data;

            if ( $this->is_non_persistent_group( $group ) ) {
                return true;
            }

            $file = $this->get_file_path( $key, $group );
            $payload = array(
                'expire' => $expire > 0 ? ( time() + (int) $expire ) : 0,
                'value'  => $data,
            );

            $dir = dirname( $file );
            if ( ! $this->fs_is_dir( $dir ) ) {
                $this->fs_mkdir( $dir, 0755, true );
            }

            $saved = $this->fs_put_contents( $file, serialize( $payload ) );
            if ( $saved ) {
                $this->maybe_cleanup_expired_cache();
            }

            return $saved;
        }

        public function get( $key, $group = 'default', $force = false, &$found = null ) {
            $group = $this->sanitize_group( $group );
            $key   = $this->sanitize_key( $key );
            $id    = $this->cache_key( $key, $group );

            if ( isset( $this->cache[ $id ] ) && ! $force ) {
                $found = true;
                return $this->cache[ $id ];
            }

            if ( $this->is_non_persistent_group( $group ) ) {
                $found = false;
                return false;
            }

            $file = $this->get_file_path( $key, $group );
            if ( ! $this->fs_exists( $file ) ) {
                $found = false;
                return false;
            }

            $raw = $this->fs_get_contents( $file );
            if ( $raw === false ) {
                $found = false;
                return false;
            }

            $payload = @unserialize( $raw );
            if ( ! is_array( $payload ) || ! array_key_exists( 'value', $payload ) ) {
                $found = false;
                return false;
            }

            if ( ! empty( $payload['expire'] ) && time() > $payload['expire'] ) {
                $this->fs_delete( $file );
                $found = false;
                return false;
            }

            if ( empty( $payload['expire'] ) && $this->is_legacy_entry_expired( $file ) ) {
                $this->fs_delete( $file );
                $found = false;
                return false;
            }

            $this->cache[ $id ] = $payload['value'];
            $found = true;
            return $payload['value'];
        }

        public function delete( $key, $group = 'default' ) {
            $group = $this->sanitize_group( $group );
            $key   = $this->sanitize_key( $key );
            $id    = $this->cache_key( $key, $group );

            unset( $this->cache[ $id ] );

            if ( $this->is_non_persistent_group( $group ) ) {
                return true;
            }

            $file = $this->get_file_path( $key, $group );
            if ( $this->fs_exists( $file ) ) {
                return $this->fs_delete( $file );
            }
            return true;
        }

        public function flush() {
            $this->cache = array();
            $this->delete_dir_contents( $this->cache_dir );
            if ( $this->fs_exists( $this->cleanup_lock_file ) ) {
                $this->fs_delete( $this->cleanup_lock_file );
            }
            return true;
        }

        public function incr( $key, $offset = 1, $group = 'default' ) {
            $value = $this->get( $key, $group, true, $found );
            if ( ! $found || ! is_numeric( $value ) ) {
                return false;
            }
            $value += $offset;
            $this->set( $key, $value, $group );
            return $value;
        }

        public function decr( $key, $offset = 1, $group = 'default' ) {
            return $this->incr( $key, -1 * abs( $offset ), $group );
        }

        public function cleanup_expired_cache() {
            return $this->delete_expired_files( $this->cache_dir );
        }

        public function add_global_groups( $groups ) {
            $groups = (array) $groups;
            $this->global_groups = array_merge( $this->global_groups, $groups );
        }

        public function add_non_persistent_groups( $groups ) {
            $groups = (array) $groups;
            $this->non_persistent_groups = array_merge( $this->non_persistent_groups, $groups );
        }

        protected function cache_key( $key, $group ) {
            $group = $this->sanitize_group( $group );
            $key   = $this->sanitize_key( $key );
            if ( $this->is_global_group( $group ) ) {
                return $group . ':' . $key;
            }
            return $group . ':' . $key;
        }

        protected function get_file_path( $key, $group ) {
            $group = $this->sanitize_group( $group );
            $hash  = md5( $key );
            return $this->cache_dir . '/' . $group . '/' . $hash . '.cache';
        }

        protected function normalize_expire( $expire ) {
            $expire = max( 0, intval( $expire ) );
            $default_ttl = max( 0, intval( HSPSC_Settings::get( 'object_cache_default_ttl', 604800 ) ) );
            $max_ttl = max( 0, intval( HSPSC_Settings::get( 'object_cache_max_ttl', 2592000 ) ) );

            if ( $expire <= 0 ) {
                $expire = $default_ttl;
            }

            if ( $max_ttl > 0 && $expire > $max_ttl ) {
                $expire = $max_ttl;
            }

            return $expire;
        }

        protected function is_legacy_entry_expired( $file ) {
            $max_ttl = max( 0, intval( HSPSC_Settings::get( 'object_cache_max_ttl', 2592000 ) ) );
            if ( $max_ttl <= 0 ) {
                return false;
            }

            $mtime = filemtime( $file );
            if ( $mtime === false ) {
                return false;
            }

            return ( time() - $mtime ) > $max_ttl;
        }

        protected function maybe_cleanup_expired_cache() {
            if ( ! $this->should_run_cleanup() ) {
                return;
            }

            $this->touch_cleanup_lock();
            $this->cleanup_expired_cache();
        }

        protected function should_run_cleanup() {
            if ( ! $this->fs_exists( $this->cleanup_lock_file ) ) {
                return true;
            }

            $mtime = filemtime( $this->cleanup_lock_file );
            if ( $mtime === false ) {
                return true;
            }

            return ( time() - $mtime ) >= HOUR_IN_SECONDS;
        }

        protected function touch_cleanup_lock() {
            $dir = dirname( $this->cleanup_lock_file );
            if ( ! $this->fs_is_dir( $dir ) ) {
                $this->fs_mkdir( $dir, 0755, true );
            }

            $this->fs_put_contents( $this->cleanup_lock_file, (string) time() );
        }

        protected function delete_expired_files( $dir ) {
            if ( ! is_dir( $dir ) ) {
                return 0;
            }

            $deleted = 0;
            $items = scandir( $dir );
            if ( ! $items ) {
                return 0;
            }

            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) {
                    continue;
                }

                if ( $item === '.gc-lock' ) {
                    continue;
                }

                $path = $dir . '/' . $item;
                if ( is_dir( $path ) ) {
                    $deleted += $this->delete_expired_files( $path );
                    $remaining = scandir( $path );
                    if ( $remaining && count( array_diff( $remaining, array( '.', '..' ) ) ) === 0 ) {
                        $this->fs_rmdir( $path, false );
                    }
                    continue;
                }

                $raw = $this->fs_get_contents( $path );
                if ( $raw === false ) {
                    continue;
                }

                $payload = @unserialize( $raw );
                if ( ! is_array( $payload ) || ! array_key_exists( 'value', $payload ) ) {
                    if ( $this->fs_delete( $path ) ) {
                        $deleted++;
                    }
                    continue;
                }

                if ( ! empty( $payload['expire'] ) && time() > $payload['expire'] ) {
                    if ( $this->fs_delete( $path ) ) {
                        $deleted++;
                    }
                    continue;
                }

                if ( empty( $payload['expire'] ) && $this->is_legacy_entry_expired( $path ) ) {
                    if ( $this->fs_delete( $path ) ) {
                        $deleted++;
                    }
                }
            }

            return $deleted;
        }

        protected function sanitize_key( $key ) {
            return preg_replace( '/[^A-Za-z0-9_\-:]/', '', (string) $key );
        }

        protected function sanitize_group( $group ) {
            return preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $group );
        }

        protected function is_global_group( $group ) {
            return in_array( $group, $this->global_groups, true );
        }

        protected function is_non_persistent_group( $group ) {
            return in_array( $group, $this->non_persistent_groups, true );
        }

        protected function delete_dir_contents( $dir ) {
            if ( ! is_dir( $dir ) ) {
                return;
            }
            $items = scandir( $dir );
            if ( ! $items ) {
                return;
            }
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) {
                    continue;
                }
                if ( $item === '.gc-lock' ) {
                    continue;
                }
                $path = $dir . '/' . $item;
                if ( is_dir( $path ) ) {
                    $this->delete_dir_contents( $path );
                    $this->fs_rmdir( $path, false );
                } else {
                    $this->fs_delete( $path );
                }
            }
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
