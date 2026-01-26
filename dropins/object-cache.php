<?php
/**
 * Drop-in object cache for HSP Smart Cache.
 * File-based persistent cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'HSP_Smart_Cache_File_Object_Cache' ) ) {
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
    class HSP_Smart_Cache_File_Object_Cache {
        protected $cache = array();
        protected $global_groups = array();
        protected $non_persistent_groups = array();
        protected $cache_dir;

        protected function get_filesystem() {
            global $wp_filesystem;

            if ( ! $wp_filesystem ) {
                if ( ! function_exists( 'WP_Filesystem' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if ( function_exists( 'WP_Filesystem' ) ) {
                    WP_Filesystem();
                }
            }

            return $wp_filesystem;
        }

        public function __construct() {
            $this->cache_dir = WP_CONTENT_DIR . '/cache/hsp-cache/object';
            $fs = $this->get_filesystem();
            if ( $fs && ! $fs->is_dir( $this->cache_dir ) ) {
                $fs->mkdir( $this->cache_dir, 0755, true );
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

            $this->cache[ $id ] = $data;

            if ( $this->is_non_persistent_group( $group ) ) {
                return true;
            }

            $file = $this->get_file_path( $key, $group );
            $payload = array(
                'expire' => $expire > 0 ? ( time() + (int) $expire ) : 0,
                'value'  => $data,
            );

            $fs = $this->get_filesystem();
            if ( ! $fs ) {
                return true;
            }

            $dir = dirname( $file );
            if ( ! $fs->is_dir( $dir ) ) {
                $fs->mkdir( $dir, 0755, true );
            }

            $chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
            return (bool) $fs->put_contents( $file, serialize( $payload ), $chmod );
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
            $fs = $this->get_filesystem();
            if ( ! $fs || ! $fs->exists( $file ) ) {
                $found = false;
                return false;
            }

            $raw = $fs->get_contents( $file );
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
                $fs->delete( $file );
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
            $fs = $this->get_filesystem();
            if ( $fs && $fs->exists( $file ) ) {
                return $fs->delete( $file );
            }
            return true;
        }

        public function flush() {
            $this->cache = array();
            $this->delete_dir_contents( $this->cache_dir );
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
            $fs = $this->get_filesystem();
            if ( ! $fs ) {
                return;
            }
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
                $path = $dir . '/' . $item;
                if ( is_dir( $path ) ) {
                    $this->delete_dir_contents( $path );
                    $fs->rmdir( $path, false );
                } else {
                    $fs->delete( $path );
                }
            }
        }
    }
}

global $wp_object_cache;

function wp_cache_init() {
    global $wp_object_cache;
    $wp_object_cache = new HSP_Smart_Cache_File_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->add( $key, $data, $group, $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
    global $wp_object_cache;
    return $wp_object_cache->set( $key, $data, $group, $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
    global $wp_object_cache;
    return $wp_object_cache->get( $key, $group, $force, $found );
}

function wp_cache_delete( $key, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->delete( $key, $group );
}

function wp_cache_flush() {
    global $wp_object_cache;
    return $wp_object_cache->flush();
}

function wp_cache_add_global_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
    global $wp_object_cache;
    $wp_object_cache->add_non_persistent_groups( $groups );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
    global $wp_object_cache;
    return $wp_object_cache->decr( $key, $offset, $group );
}

function wp_cache_close() {
    return true;
}
