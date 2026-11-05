<?php
/**
 * Drop-in object cache for HSP Smart Cache.
 * File-based persistent cache.
 *
 * @package HSPSC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'HSPSC_File_Object_Cache' ) ) {
	/**
	 * Provides authenticated file-backed object caching during WordPress bootstrap.
	 */
	class HSPSC_File_Object_Cache {
		const FILE_HEADER = "<?php exit; __halt_compiler();\nHSPSC2\n";

		/**
		 * Request-local values indexed by scoped cache key.
		 *
		 * @var array
		 */
		protected $cache = array();

		/**
		 * Absolute expiration timestamps for request-local values.
		 *
		 * @var int[]
		 */
		protected $expires = array();

		/**
		 * Groups whose entries are shared across sites.
		 *
		 * @var string[]
		 */
		protected $global_groups = array();

		/**
		 * Groups that must remain request-local.
		 *
		 * @var string[]
		 */
		protected $non_persistent_groups = array();

		/**
		 * Absolute path to the protected object-cache root.
		 *
		 * @var string
		 */
		protected $cache_dir;

		/**
		 * Path to the last successful garbage-collection scheduling marker.
		 *
		 * @var string
		 */
		protected $cleanup_lock_file;

		/**
		 * Current multisite blog identifier used for key isolation.
		 *
		 * @var int
		 */
		protected $blog_id = 0;

		/**
		 * Whether the cache root and access protections are available.
		 *
		 * @var bool
		 */
		protected $storage_ready = false;

		/**
		 * Stable lock paths currently held by this cache instance.
		 *
		 * @var bool[]
		 */
		protected $held_locks = array();

		/**
		 * Request-local signing key; null until derived and empty when unavailable.
		 *
		 * @var string|null
		 */
		protected $signing_key = null;

		/**
		 * Earliest timestamp for another garbage-collection marker check.
		 *
		 * @var int
		 */
		protected $next_cleanup_check = 0;

		/**
		 * Whether a settings lookup is in progress, preventing recursive option loads.
		 *
		 * @var bool
		 */
		protected $loading_settings = false;

		/**
		 * Initializes storage protections, site scope, and one-time legacy cleanup.
		 */
		public function __construct() {
			$this->cache_dir         = WP_CONTENT_DIR . '/cache/hspsc/object';
			$this->cleanup_lock_file = $this->cache_dir . '/.gc-lock';
			$this->blog_id           = $this->get_current_blog_id();
			$this->storage_ready     = $this->protect_storage();
			$marker                  = $this->cache_dir . '/.protected-format.php';
			if ( $this->storage_ready && ! $this->fs_exists( $marker ) ) {
				$this->remove_legacy_files( $this->cache_dir );
				$this->fs_put_contents( $marker, "<?php exit;\n" );
			}
		}

		/**
		 * Retains the filesystem hook without depending on late WordPress initialization.
		 *
		 * @return null No WordPress filesystem adapter is used during early bootstrap.
		 */
		protected function get_filesystem() {
			return null;
		}

		/**
		 * Checks whether a directory exists without accepting a symbolic link.
		 *
		 * @param string $path Absolute filesystem path.
		 * @return bool Whether the path is a regular directory.
		 */
		protected function fs_is_dir( $path ) {
			return is_dir( $path ) && ! is_link( $path );
		}

		/**
		 * Creates an early-bootstrap directory unless the target is a symbolic link.
		 *
		 * @param string $path Absolute filesystem path.
		 * @param int    $mode Filesystem permission bits.
		 * @param bool   $recursive Whether missing parent directories may be created.
		 * @return bool Whether the directory exists or was created.
		 */
		protected function fs_mkdir( $path, $mode = 0755, $recursive = true ) {
			if ( is_link( $path ) ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- The object-cache drop-in runs before WordPress filesystem APIs are available; the local path is checked by the storage helpers.
			return is_dir( $path ) || mkdir( $path, $mode, $recursive );
		}

		/**
		 * Writes a protected cache file using atomic replacement.
		 *
		 * @param string $path Absolute filesystem path.
		 * @param string $contents Exact bytes to write.
		 * @return bool Whether replacement succeeded.
		 */
		protected function fs_put_contents( $path, $contents ) {
			return $this->atomic_write( $path, $contents );
		}

		/**
		 * Checks for a regular cache file without accepting symbolic links.
		 *
		 * @param string $path Absolute filesystem path.
		 * @return bool Whether a regular file exists.
		 */
		protected function fs_exists( $path ) {
			return is_file( $path ) && ! is_link( $path );
		}

		/**
		 * Reads exact bytes from a regular cache file.
		 *
		 * @param string $path Absolute filesystem path.
		 * @return string|false File contents, or false when unavailable.
		 */
		protected function fs_get_contents( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- The object-cache drop-in runs before WordPress filesystem APIs are available; the local path is checked by the storage helpers.
			return $this->fs_exists( $path ) ? file_get_contents( $path ) : false;
		}

		/**
		 * Deletes a cache file, treating an already absent file as success.
		 *
		 * @param string $path Absolute filesystem path.
		 * @return bool Whether the file is absent after deletion.
		 */
		protected function fs_delete( $path ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- The object-cache drop-in runs before WordPress filesystem APIs are available; the local path is checked by the storage helpers.
			return ! file_exists( $path ) || unlink( $path );
		}

		/**
		 * Removes an empty cache directory without following symbolic links.
		 *
		 * @param string $path Absolute filesystem path.
		 * @param bool   $recursive Unused compatibility argument; deletion is always nonrecursive.
		 * @return bool Whether the empty directory was removed.
		 */
		protected function fs_rmdir( $path, $recursive = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Retains the filesystem-hook signature for subclasses; this implementation removes only empty directories.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The object-cache drop-in runs before WordPress filesystem APIs are available; the local path is checked by the storage helpers.
			return ! is_link( $path ) && rmdir( $path );
		}

		/**
		 * Stores a value only when the key is absent, under its persistent lock.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
		 * @param string $group Cache group name.
		 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
		 * @return bool Whether the value was added.
		 */
		public function add( $key, $data, $group = 'default', $expire = 0 ) {
			$result = $this->with_lock(
				$key,
				$group,
				function () use ( $key, $data, $group, $expire ) {
					$this->get( $key, $group, true, $found );
					return $found ? false : $this->store( $key, $data, $group, $this->expiration_time( $expire ) );
				}
			);
			if ( $result && $this->can_persist( $group ) ) {
				$this->maybe_cleanup_expired_cache();
			}
			return $result;
		}

		/**
		 * Stores a value only when the key already exists, under its persistent lock.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
		 * @param string $group Cache group name.
		 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
		 * @return bool Whether the value was replaced.
		 */
		public function replace( $key, $data, $group = 'default', $expire = 0 ) {
			$result = $this->with_lock(
				$key,
				$group,
				function () use ( $key, $data, $group, $expire ) {
					$this->get( $key, $group, true, $found );
					return $found ? $this->store( $key, $data, $group, $this->expiration_time( $expire ) ) : false;
				}
			);
			if ( $result && $this->can_persist( $group ) ) {
				$this->maybe_cleanup_expired_cache();
			}
			return $result;
		}

		/**
		 * Stores a value in the request cache and eligible persistent storage.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
		 * @param string $group Cache group name.
		 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
		 * @return bool Whether the value was stored.
		 */
		public function set( $key, $data, $group = 'default', $expire = 0 ) {
			$result = $this->with_lock(
				$key,
				$group,
				function () use ( $key, $data, $group, $expire ) {
					return $this->store( $key, $data, $group, $this->expiration_time( $expire ) );
				}
			);
			if ( $result && $this->can_persist( $group ) ) {
				$this->maybe_cleanup_expired_cache();
			}
			return $result;
		}

		/**
		 * Stores a cloned value and writes an authenticated persistent envelope when allowed.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
		 * @param string $group Cache group name.
		 * @param int    $expiration Absolute expiration timestamp, or zero for no expiration.
		 * @return bool Whether request-local or persistent storage succeeded.
		 */
		protected function store( $key, $data, $group, $expiration ) {
			$group                = $this->normalize_group( $group );
			$id                   = $this->cache_key( $key, $group );
			$this->cache[ $id ]   = is_object( $data ) ? clone $data : $data;
			$this->expires[ $id ] = $expiration;
			if ( ! $this->can_persist( $group ) ) {
				return true;
			}
			$file = $this->get_file_path( $key, $group );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- The authenticated cache format must preserve PHP value types and legitimate WordPress object classes.
			$serialized = serialize( $this->cache[ $id ] );
			$signature  = $this->payload_signature( $file, $expiration, $serialized );
			return $this->fs_put_contents( $file, self::FILE_HEADER . $expiration . '|' . $signature . "\n" . $serialized );
		}

		/**
		 * Reads a nonexpired value and verifies persistent bytes before deserialization.
		 *
		 * @param mixed     $key Cache key, preserving distinct raw scalar values.
		 * @param string    $group Cache group name.
		 * @param bool      $force Whether to bypass a persistent group's request-local copy.
		 * @param bool|null $found Receives whether the key exists, including falsey values.
		 * @return mixed Cached value, or false for a miss.
		 */
		public function get( $key, $group = 'default', $force = false, &$found = null ) {
			$group      = $this->normalize_group( $group );
			$id         = $this->cache_key( $key, $group );
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
			$file    = $this->get_file_path( $key, $group );
			$raw     = $this->fs_get_contents( $file );
			$payload = false === $raw ? false : $this->decode_payload( $raw, $file );
			if ( false === $payload || ( $payload['expire'] > 0 && time() >= $payload['expire'] ) ) {
				unset( $this->cache[ $id ], $this->expires[ $id ] );
				if ( false !== $raw ) {
					$this->delete_if_unchanged( $file, $raw );
				}
				$found = false;
				return false;
			}
			$this->cache[ $id ]   = $payload['value'];
			$this->expires[ $id ] = $payload['expire'];
			$found                = true;
			return is_object( $payload['value'] ) ? clone $payload['value'] : $payload['value'];
		}

		/**
		 * Deletes a key from memory and eligible persistent storage under its lock.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param string $group Cache group name.
		 * @return bool Whether deletion succeeded.
		 */
		public function delete( $key, $group = 'default' ) {
			return $this->with_lock(
				$key,
				$group,
				function () use ( $key, $group ) {
					$id = $this->cache_key( $key, $group );
					unset( $this->cache[ $id ], $this->expires[ $id ] );
					return ! $this->can_persist( $group ) || $this->fs_delete( $this->get_file_path( $key, $group ) );
				}
			);
		}

		/**
		 * Clears cached values while retaining access protections and stable lock files.
		 *
		 * @return bool Always true after the flush attempt.
		 */
		public function flush() {
			$this->cache              = array();
			$this->expires            = array();
			$this->next_cleanup_check = 0;
			$this->delete_dir_contents( $this->cache_dir );
			$this->storage_ready = $this->protect_storage();
			return true;
		}

		/**
		 * Atomically adjusts an existing numeric value while preserving its expiration.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param int    $offset Amount by which to change the cached number.
		 * @param string $group Cache group name.
		 * @return int|false Updated nonnegative value, or false when unavailable.
		 */
		public function incr( $key, $offset = 1, $group = 'default' ) {
			$result = $this->with_lock(
				$key,
				$group,
				function () use ( $key, $offset, $group ) {
					$value = $this->get( $key, $group, true, $found );
					if ( ! $found || ! is_numeric( $value ) ) {
						return false;
					}
					$value = max( 0, (int) $value + (int) $offset );
					$id    = $this->cache_key( $key, $group );
					return $this->store( $key, $value, $group, $this->expires[ $id ] ) ? $value : false;
				}
			);
			if ( false !== $result && $this->can_persist( $group ) ) {
				$this->maybe_cleanup_expired_cache();
			}
			return $result;
		}

		/**
		 * Decreases an existing numeric value without going below zero.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param int    $offset Amount by which to change the cached number.
		 * @param string $group Cache group name.
		 * @return int|false Updated nonnegative value, or false when unavailable.
		 */
		public function decr( $key, $offset = 1, $group = 'default' ) {
			return $this->incr( $key, -abs( (int) $offset ), $group );
		}

		/**
		 * Removes expired or invalid authenticated entries from persistent storage.
		 *
		 * @return int Number of entries deleted.
		 */
		public function cleanup_expired_cache() {
			return $this->delete_expired_files( $this->cache_dir );
		}

		/**
		 * Registers groups that share keys across sites in a multisite installation.
		 *
		 * @param string|string[] $groups Names of groups to register as global.
		 * @return void
		 */
		public function add_global_groups( $groups ) {
			$this->global_groups = array_unique( array_merge( $this->global_groups, array_map( array( $this, 'normalize_group' ), (array) $groups ) ) );
		}

		/**
		 * Registers groups that must remain request-local.
		 *
		 * @param string|string[] $groups Names of groups to exclude from persistent storage.
		 * @return void
		 */
		public function add_non_persistent_groups( $groups ) {
			$this->non_persistent_groups = array_unique( array_merge( $this->non_persistent_groups, array_map( array( $this, 'normalize_group' ), (array) $groups ) ) );
		}

		/**
		 * Changes the active site scope and discards request-local values.
		 *
		 * @param int $blog_id WordPress site identifier.
		 * @return void
		 */
		public function switch_to_blog( $blog_id ) {
			$this->blog_id = max( 0, (int) $blog_id );
			$this->cache   = array();
			$this->expires = array();
		}

		/**
		 * Builds an unambiguous scoped key from the raw key and group.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param string $group Cache group name.
		 * @return string Request-cache key including site or global scope.
		 */
		protected function cache_key( $key, $group ) {
			$group = $this->normalize_group( $group );
			return $this->get_blog_prefix( $group ) . strlen( $group ) . ':' . $group . ':' . $this->normalize_key( $key );
		}

		/**
		 * Maps a scoped key to a guarded, sharded cache-file path.
		 *
		 * @param mixed  $key Cache key, preserving distinct raw scalar values.
		 * @param string $group Cache group name.
		 * @return string Absolute cache-file path.
		 */
		protected function get_file_path( $key, $group ) {
			$group     = $this->normalize_group( $group );
			$directory = preg_replace( '/[^A-Za-z0-9_\-]/', '_', $group );
			if ( $directory !== $group ) {
				$directory .= '-' . md5( $group );
			}
			$hash = md5( $this->cache_key( $key, $group ) );
			return $this->cache_dir . '/' . $directory . '/' . substr( $hash, 0, 2 ) . '/' . $hash . '.php';
		}

		/**
		 * Converts a requested lifetime into an absolute expiration timestamp.
		 *
		 * @param int $expire Requested lifetime in seconds; zero uses the configured default.
		 * @return int Expiration timestamp, or zero when unlimited.
		 */
		protected function expiration_time( $expire ) {
			$expire = $this->normalize_expire( $expire );
			return $expire > 0 ? time() + $expire : 0;
		}

		/**
		 * Applies configured default and maximum lifetimes without settings recursion.
		 *
		 * @param int $expire Requested lifetime in seconds; zero uses the configured default.
		 * @return int Effective lifetime in seconds.
		 */
		protected function normalize_expire( $expire ) {
			$expire      = max( 0, (int) $expire );
			$default_ttl = max( 0, (int) $this->get_setting( 'object_cache_default_ttl', 604800 ) );
			$max_ttl     = max( 0, (int) $this->get_setting( 'object_cache_max_ttl', 2592000 ) );
			if ( $expire <= 0 ) {
				$expire = $default_ttl;
			}
			if ( $max_ttl > 0 && ( $expire <= 0 || $expire > $max_ttl ) ) {
				$expire = $max_ttl;
			}
			return $expire;
		}

		/**
		 * Reads a cache setting with an early-bootstrap and reentry fallback.
		 *
		 * @param string $key Setting name.
		 * @param mixed  $default Value used before settings become available.
		 * @return mixed Configured setting or fallback.
		 */
		protected function get_setting( $key, $default ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Preserves the existing settings-hook signature for compatible subclasses and named calls.
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

		/**
		 * Returns the request-local signing key derived from immutable configuration.
		 *
		 * @return string Binary key, or empty when no suitable secret exists.
		 */
		protected function get_signing_key() {
			if ( null === $this->signing_key ) {
				// wp-config constants cannot change after this request has loaded them.
				$this->signing_key = $this->derive_signing_key();
			}
			return $this->signing_key;
		}

		/**
		 * Derives a stable signing key from sufficiently strong WordPress secrets.
		 *
		 * @return string Binary key, or empty to keep storage memory-only.
		 */
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

		/**
		 * Checks storage readiness, group policy, and signing-key availability.
		 *
		 * @param string $group Cache group name.
		 * @return bool Whether the group may use persistent storage.
		 */
		protected function can_persist( $group ) {
			return $this->storage_ready && ! $this->is_non_persistent_group( $this->normalize_group( $group ) ) && $this->get_signing_key() !== '';
		}

		/**
		 * Authenticates a cache payload together with its filename and expiration.
		 *
		 * @param string $file Absolute cache-file path.
		 * @param int    $expiration Absolute expiration timestamp.
		 * @param string $serialized Exact serialized payload bytes.
		 * @return string Hexadecimal HMAC signature.
		 */
		protected function payload_signature( $file, $expiration, $serialized ) {
			return hash_hmac( 'sha256', basename( $file ) . '|' . $expiration . "\n" . $serialized, $this->get_signing_key() );
		}

		/**
		 * Authenticates a guarded cache envelope before optionally decoding its value.
		 *
		 * @param string $raw Exact stored file bytes.
		 * @param string $file Absolute cache-file path used for signature binding.
		 * @param bool   $deserialize Whether to instantiate the authenticated cached value.
		 * @return array|false Verified expiration and optional value, or false for invalid storage.
		 */
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
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- The keyed signature is verified first; malformed authenticated payloads become misses while legitimate cached WordPress objects retain their types.
			$value = @unserialize( $parts[1], array( 'allowed_classes' => true ) );
			if ( false === $value && 'b:0;' !== $parts[1] ) {
				return false;
			}
			return array(
				'expire' => $expiration,
				'value'  => $value,
			);
		}

		/**
		 * Runs a key mutation under its persistent lock when persistence is available.
		 *
		 * @param mixed    $key Cache key, preserving distinct raw scalar values.
		 * @param string   $group Cache group name.
		 * @param callable $callback Operation to execute after obtaining the lock.
		 * @return mixed Callback result, or false if the lock cannot be acquired.
		 */
		protected function with_lock( $key, $group, $callback ) {
			if ( ! $this->can_persist( $group ) ) {
				return $callback();
			}
			return $this->with_file_lock( $this->get_file_path( $key, $group ), $callback );
		}

		/**
		 * Runs a cache-file operation under a stable, reentrant shard lock.
		 *
		 * @param string   $data_file Absolute data-file path selecting the lock shard.
		 * @param callable $callback Operation to execute while holding the lock.
		 * @return mixed Callback result, or false if locking fails.
		 */
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A stable local file descriptor is required for flock during early object-cache bootstrap.
			$handle = fopen( $file, 'c+b' );
			if ( ! $handle ) {
				return false;
			}
			if ( ! flock( $handle, LOCK_EX ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the local stream used by early-bootstrap locking or atomic writes.
				fclose( $handle );
				return false;
			}
			try {
				$this->held_locks[ $file ] = true;
				return $callback();
			} finally {
				unset( $this->held_locks[ $file ] );
				flock( $handle, LOCK_UN );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the local stream used by early-bootstrap locking or atomic writes.
				fclose( $handle );
			}
		}

		/**
		 * Deletes a file only when its bytes still match the observed value.
		 *
		 * @param string       $file Absolute cache-file path.
		 * @param string|false $observed Previously read bytes, or false for a failed read.
		 * @return bool Whether the unchanged file was deleted.
		 */
		protected function delete_if_unchanged( $file, $observed ) {
			return $this->with_file_lock(
				$file,
				function () use ( $file, $observed ) {
					return $this->fs_get_contents( $file ) === $observed && $this->fs_delete( $file );
				}
			);
		}

		/**
		 * Creates access-deny files and an exit guard without following symbolic links.
		 *
		 * @return bool Whether all required storage protections are present.
		 */
		protected function protect_storage() {
			if ( ! $this->safe_directory( $this->cache_dir ) ) {
				return false;
			}
			$files = array(
				'index.php'  => "<?php exit;\n",
				'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
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

		/**
		 * Ensures a directory stays under the cache root and contains no symlink components.
		 *
		 * @param string $dir Absolute cache directory path.
		 * @return bool Whether the directory is safe and available.
		 */
		protected function safe_directory( $dir ) {
			$root = rtrim( str_replace( '\\', '/', $this->cache_dir ), '/' );
			$dir  = rtrim( str_replace( '\\', '/', $dir ), '/' );
			if ( $dir !== $root && strpos( $dir, $root . '/' ) !== 0 ) {
				return false;
			}
			$cursor = $root;
			if ( is_link( $cursor ) || ( ! is_dir( $cursor ) && ! $this->fs_mkdir( $cursor, 0755, true ) ) ) {
				return false;
			}
			foreach ( explode( '/', ltrim( substr( $dir, strlen( $root ) ), '/' ) ) as $part ) {
				if ( '' === $part ) {
					continue;
				}
				$cursor .= '/' . $part;
				if ( is_link( $cursor ) || ( ! is_dir( $cursor ) && ! $this->fs_mkdir( $cursor, 0755, false ) ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Schedules occasional expiration cleanup using a persistent marker.
		 *
		 * @return void
		 */
		protected function maybe_cleanup_expired_cache() {
			$now = time();
			if ( $now < $this->next_cleanup_check ) {
				return;
			}
			$mtime = is_file( $this->cleanup_lock_file ) ? filemtime( $this->cleanup_lock_file ) : false;
			if ( false !== $mtime && $now - $mtime < 3600 ) {
				$this->next_cleanup_check = $mtime + 3600;
				return;
			}
			if ( ! is_link( $this->cleanup_lock_file ) && $this->fs_put_contents( $this->cleanup_lock_file, (string) $now ) ) {
				// A failed marker write must not make every subsequent write scan the cache.
				$this->next_cleanup_check = $now + 3600;
				$this->cleanup_expired_cache();
			}
		}

		/**
		 * Recursively removes old .cache files while preserving links and stable locks.
		 *
		 * @param string $dir Absolute cache directory path.
		 * @return void
		 */
		protected function remove_legacy_files( $dir ) {
			if ( ! is_dir( $dir ) || is_link( $dir ) ) {
				return;
			}
			foreach ( (array) scandir( $dir ) as $item ) {
				if ( '.' === $item || '..' === $item || '.locks' === $item ) {
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

		/**
		 * Removes invalid or expired cache envelopes without instantiating stored classes.
		 *
		 * @param string $dir Absolute cache directory path.
		 * @return int Number of entries deleted.
		 */
		protected function delete_expired_files( $dir ) {
			if ( ! is_dir( $dir ) || is_link( $dir ) ) {
				return 0;
			}
			$deleted = 0;
			foreach ( (array) scandir( $dir ) as $item ) {
				if ( '.' === $item || '..' === $item || '.locks' === $item ) {
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
				$raw     = $this->fs_get_contents( $path );
				$payload = false === $raw ? false : $this->decode_payload( $raw, $path, false );
				if ( false === $payload || ( $payload['expire'] > 0 && time() >= $payload['expire'] ) ) {
					if ( $this->delete_if_unchanged( $path, $raw ) ) {
						++$deleted;
					}
				}
			}
			return $deleted;
		}

		/**
		 * Preserves scalar keys and hashes structured keys without collapsing their values.
		 *
		 * @param mixed $key Cache key, preserving distinct raw scalar values.
		 * @return string Normalized key representation.
		 */
		protected function normalize_key( $key ) {
			if ( is_scalar( $key ) || ( is_object( $key ) && method_exists( $key, '__toString' ) ) ) {
				return (string) $key;
			}
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Serialization distinguishes structured keys; these bytes are hashed and are never deserialized.
			return md5( serialize( $key ) );
		}

		/**
		 * Normalizes an empty cache group to the WordPress default group.
		 *
		 * @param string $group Cache group name.
		 * @return string Effective group name.
		 */
		protected function normalize_group( $group ) {
			return '' !== (string) $group ? (string) $group : 'default';
		}

		/**
		 * Selects global, multisite, or single-site scope for a group.
		 *
		 * @param string $group Cache group name.
		 * @return string Scope prefix.
		 */
		protected function get_blog_prefix( $group ) {
			if ( $this->is_global_group( $group ) ) {
				return 'global:';
			}
			return function_exists( 'is_multisite' ) && is_multisite() ? 'blog:' . $this->blog_id . ':' : 'site:';
		}

		/**
		 * Reads the current site identifier when the early WordPress API is available.
		 *
		 * @return int Site identifier, or zero before it is available.
		 */
		protected function get_current_blog_id() {
			return function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		}

		/**
		 * Checks whether a group is shared across sites.
		 *
		 * @param string $group Cache group name.
		 * @return bool Whether the group has global scope.
		 */
		protected function is_global_group( $group ) {
			return in_array( $group, $this->global_groups, true );
		}

		/**
		 * Checks whether a group is restricted to the current request.
		 *
		 * @param string $group Cache group name.
		 * @return bool Whether persistence is disabled for the group.
		 */
		protected function is_non_persistent_group( $group ) {
			return in_array( $group, $this->non_persistent_groups, true );
		}

		/**
		 * Deletes cache entries while preserving access rules and stable lock files.
		 *
		 * @param string $dir Absolute cache directory path.
		 * @return void
		 */
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

		/**
		 * Replaces a guarded cache file with a private, same-directory temporary file.
		 *
		 * @param string $path Absolute filesystem path.
		 * @param string $contents Exact bytes to write.
		 * @return bool Whether every byte was written and replacement succeeded.
		 */
		protected function atomic_write( $path, $contents ) {
			if ( is_link( $path ) || ! $this->safe_directory( dirname( $path ) ) ) {
				return false;
			}
			// Temporary cache payloads also have a PHP extension and the same exit guard.
			$tmp = dirname( $path ) . '/.hspsc-' . bin2hex( random_bytes( 12 ) ) . '.php';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation of the guarded temporary file is required before atomic local replacement.
			$handle = fopen( $tmp, 'x+b' );
			if ( ! $handle ) {
				return false;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Writes the guarded payload through the exclusively created local stream; the complete byte count is checked below.
			$written = fwrite( $handle, $contents );
			fflush( $handle );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the local stream used by early-bootstrap locking or atomic writes.
			fclose( $handle );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Sets private permissions when supported; the PHP exit guard and storage deny rules also protect the temporary file.
			@chmod( $tmp, 0600 );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- Same-directory rename is the atomic commit; a checked failure deletes the temporary file and reports failure.
			if ( strlen( $contents ) === $written && @rename( $tmp, $path ) ) {
				return true;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of this write's exclusively created temporary file must not interrupt WordPress bootstrap.
			@unlink( $tmp );
			return false;
		}
	}
}

global $wp_object_cache;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.Files.SeparateFunctionsFromOO.Mixed -- WordPress requires these global wp_cache_* entry points beside the implementation in object-cache.php.
if ( ! function_exists( 'wp_cache_init' ) ) {
	/**
	 * Initializes the object-cache instance required by WordPress.
	 *
	 * @return void
	 */
	function wp_cache_init() {
		global $wp_object_cache;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- WordPress requires wp_cache_init() to assign the active object-cache implementation to this global.
		$wp_object_cache = new HSPSC_File_Object_Cache();
	}
}
if ( ! function_exists( 'wp_cache_add' ) ) {
	/**
	 * Implements the WordPress object-cache add API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
	 * @param string $group Cache group name.
	 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
	 * @return bool Whether the value was added.
	 */
	function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->add( $key, $data, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * Implements the WordPress object-cache set API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
	 * @param string $group Cache group name.
	 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
	 * @return bool Whether the value was stored.
	 */
	function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->set( $key, $data, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_replace' ) ) {
	/**
	 * Implements the WordPress object-cache replace API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param mixed  $data Value to cache; objects are cloned for request-local storage.
	 * @param string $group Cache group name.
	 * @param int    $expire Requested lifetime in seconds; zero uses the configured default.
	 * @return bool Whether the value was replaced.
	 */
	function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
		global $wp_object_cache;
		return $wp_object_cache->replace( $key, $data, $group, $expire );
	}
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * Implements the WordPress object-cache get API.
	 *
	 * @param mixed     $key Cache key, preserving distinct raw scalar values.
	 * @param string    $group Cache group name.
	 * @param bool      $force Whether to bypass a persistent group's request-local copy.
	 * @param bool|null $found Receives whether the key exists, including falsey values.
	 * @return mixed Cached value, or false for a miss.
	 */
	function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
		global $wp_object_cache;
		return $wp_object_cache->get( $key, $group, $force, $found );
	}
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * Implements the WordPress object-cache delete API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param string $group Cache group name.
	 * @return bool Whether deletion succeeded.
	 */
	function wp_cache_delete( $key, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->delete( $key, $group );
	}
}
if ( ! function_exists( 'wp_cache_flush' ) ) {
	/**
	 * Implements the WordPress object-cache flush API.
	 *
	 * @return bool Always true after the flush attempt.
	 */
	function wp_cache_flush() {
		global $wp_object_cache;
		return $wp_object_cache->flush();
	}
}
if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
	/**
	 * Implements the WordPress object-cache add global groups API.
	 *
	 * @param string|string[] $groups Names of groups to register as global.
	 * @return void
	 */
	function wp_cache_add_global_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_global_groups( $groups );
	}
}
if ( ! function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
	/**
	 * Implements the WordPress object-cache add non persistent groups API.
	 *
	 * @param string|string[] $groups Names of groups to exclude from persistent storage.
	 * @return void
	 */
	function wp_cache_add_non_persistent_groups( $groups ) {
		global $wp_object_cache;
		$wp_object_cache->add_non_persistent_groups( $groups );
	}
}
if ( ! function_exists( 'wp_cache_incr' ) ) {
	/**
	 * Implements the WordPress object-cache incr API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param int    $offset Amount by which to change the cached number.
	 * @param string $group Cache group name.
	 * @return int|false Updated nonnegative value, or false when unavailable.
	 */
	function wp_cache_incr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->incr( $key, $offset, $group );
	}
}
if ( ! function_exists( 'wp_cache_decr' ) ) {
	/**
	 * Implements the WordPress object-cache decr API.
	 *
	 * @param mixed  $key Cache key, preserving distinct raw scalar values.
	 * @param int    $offset Amount by which to change the cached number.
	 * @param string $group Cache group name.
	 * @return int|false Updated nonnegative value, or false when unavailable.
	 */
	function wp_cache_decr( $key, $offset = 1, $group = '' ) {
		global $wp_object_cache;
		return $wp_object_cache->decr( $key, $offset, $group );
	}
}
if ( ! function_exists( 'wp_cache_close' ) ) {
	/**
	 * Completes the WordPress object-cache shutdown API without additional work.
	 *
	 * @return bool Always true; writes are completed during individual operations.
	 */
	function wp_cache_close() {
		return true;
	}
}
if ( ! function_exists( 'wp_cache_switch_to_blog' ) ) {
	/**
	 * Implements the WordPress object-cache switch to blog API.
	 *
	 * @param int $blog_id WordPress site identifier.
	 * @return bool Always true after requesting the site switch.
	 */
	function wp_cache_switch_to_blog( $blog_id ) {
		global $wp_object_cache;
		if ( is_object( $wp_object_cache ) && method_exists( $wp_object_cache, 'switch_to_blog' ) ) {
			$wp_object_cache->switch_to_blog( $blog_id );
		}
		return true;
	}
}
// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound,Universal.Files.SeparateFunctionsFromOO.Mixed
