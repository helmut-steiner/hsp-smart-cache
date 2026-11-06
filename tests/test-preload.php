<?php

class HSPSC_Preload_Test extends WP_UnitTestCase {
    public function test_run_returns_not_ok_when_preload_disabled() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge( HSPSC_Settings::defaults(), array( 'preload_enabled' => false ) )
        );

        $result = HSPSC_Preload::run();

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 0, $result['count'] );
    }

    public function test_run_warms_urls_from_sitemap_up_to_limit() {
        $sitemap_url = home_url( '/sitemap.xml' );
        $warmed_urls = array();

        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'page_cache'         => true,
                    'preload_enabled'    => true,
                    'preload_sitemap_url'=> $sitemap_url,
                    'preload_limit'      => 2,
                    'preload_timeout'    => 5,
                )
            )
        );

        $mock_http = static function( $preempt, $args, $url ) use ( &$warmed_urls, $sitemap_url ) {
            if ( $url === $sitemap_url ) {
                return array(
                    'headers'  => array(),
                    'body'     => '<urlset><url><loc>' . esc_url_raw( home_url( '/a/' ) ) . '</loc></url><url><loc>https://example.net/private/</loc></url><url><loc>' . esc_url_raw( home_url( '/b/' ) ) . '</loc></url><url><loc>' . esc_url_raw( home_url( '/c/' ) ) . '</loc></url></urlset>',
                    'response' => array( 'code' => 200, 'message' => 'OK' ),
                    'cookies'  => array(),
                    'filename' => null,
                );
            }

            $warmed_urls[] = $url;

            return array(
                'headers'  => array(),
                'body'     => 'ok',
                'response' => array( 'code' => 200, 'message' => 'OK' ),
                'cookies'  => array(),
                'filename' => null,
            );
        };

        add_filter( 'pre_http_request', $mock_http, 10, 3 );

        try {
            $result = HSPSC_Preload::run();
        } finally {
            remove_filter( 'pre_http_request', $mock_http, 10 );
        }

        $this->assertTrue( $result['ok'] );
        $this->assertSame( 2, $result['count'] );
        $this->assertCount( 2, $warmed_urls );
        $this->assertContains( home_url( '/a/' ), $warmed_urls );
        $this->assertContains( home_url( '/b/' ), $warmed_urls );
        $this->assertNotContains( 'https://example.net/private/', $warmed_urls );
    }

    public function test_run_rejects_external_sitemap_url() {
        update_option(
            HSPSC_Settings::OPTION_KEY,
            array_merge(
                HSPSC_Settings::defaults(),
                array(
                    'page_cache'          => true,
                    'preload_enabled'     => true,
                    'preload_sitemap_url' => 'https://example.net/sitemap.xml',
                )
            )
        );

        $result = HSPSC_Preload::run();

        $this->assertFalse( $result['ok'] );
        $this->assertSame( 'invalid_sitemap_url', $result['error'] );
        $this->assertSame( 0, $result['count'] );
    }

	/**
	 * Sitemap XML entities are decoded without losing language query arguments.
	 */
	public function test_sitemap_urls_decode_xml_entities_and_preserve_language_queries() {
		$sitemap_url  = home_url( '/sitemap.xml' );
		$expected_url = add_query_arg( array( 'page' => 2, 'lang' => 'de' ), home_url( '/catalog/' ) );
		$warmed_urls  = array();
		$sitemap_args = array();

		update_option(
			HSPSC_Settings::OPTION_KEY,
			array_merge(
				HSPSC_Settings::defaults(),
				array(
					'page_cache'          => true,
					'preload_enabled'     => true,
					'preload_sitemap_url' => $sitemap_url,
				)
			)
		);

		$mock_http = static function ( $preempt, $args, $url ) use ( &$warmed_urls, &$sitemap_args, $sitemap_url, $expected_url ) {
			$body = 'ok';
			if ( $sitemap_url === $url ) {
				$sitemap_args = $args;
				$body         = '<urlset><url><loc>' . htmlspecialchars( $expected_url, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc></url></urlset>';
			} else {
				$warmed_urls[] = $url;
			}

			return array(
				'headers'  => array(),
				'body'     => $body,
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $mock_http, 10, 3 );

		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock_http, 10 );
		}

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 1, $result['count'] );
		$this->assertSame( array( $expected_url ), $warmed_urls );
		$this->assertSame( 0, $sitemap_args['redirection'] );
	}

	/** Index files are traversed once and only leaf page URLs are warmed. */
	public function test_sitemap_indexes_follow_safe_children_and_deduplicate_pages() {
		$root   = home_url( '/sitemap.xml' );
		$nested = home_url( '/nested-sitemap.xml' );
		$leaf   = home_url( '/page-sitemap.xml' );
		$page_a = home_url( '/about/' );
		$page_b = add_query_arg( array( 'lang' => 'de', 'page' => 2 ), home_url( '/catalog/' ) );
		$bodies = array(
			$root   => '<sitemapindex><sitemap><loc>' . $nested . '</loc></sitemap><sitemap><loc>https://example.net/external.xml</loc></sitemap></sitemapindex>',
			$nested => '<sitemapindex><sitemap><loc>' . $root . '</loc></sitemap><sitemap><loc>' . $leaf . '</loc></sitemap></sitemapindex>',
			$leaf   => '<urlset><url><loc>' . $page_a . '</loc><image:loc>https://example.net/image.jpg</image:loc></url><url><loc>' . $page_a . '</loc></url><url><loc>' . htmlspecialchars( $page_b, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</loc></url><url><loc>' . home_url( '/not-warmed/' ) . '</loc></url></urlset>',
		);
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'preload_enabled' => true, 'preload_sitemap_url' => $root, 'preload_limit' => 2,
		) ) );
		$requests = array();
		$warmed   = array();
		$mock = static function ( $preempt, $args, $url ) use ( $bodies, &$requests, &$warmed ) {
			$requests[] = $url;
			if ( ! isset( $bodies[ $url ] ) ) {
				$warmed[] = $url;
			}
			return array( 'headers' => array(), 'body' => $bodies[ $url ] ?? 'ok', 'response' => array( 'code' => 200 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( 2, $result['count'] );
		$this->assertSame( array( $root, $nested, $leaf, $page_a, $page_b ), $requests );
		$this->assertSame( array( $page_a, $page_b ), $warmed );
	}

	/** A blank setting resolves WordPress's actual core sitemap index URL. */
	public function test_preload_without_custom_sitemap_uses_the_core_index() {
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'preload_enabled' => true, 'preload_sitemap_url' => '',
		) ) );
		$requests = array();
		$mock = static function ( $preempt, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			return array( 'headers' => array(), 'body' => '<urlset></urlset>', 'response' => array( 'code' => 200 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( array( wp_sitemaps_get_server()->index->get_index_url() ), $requests );
	}

	/** Fetch count and response sizes remain bounded even for hostile indexes. */
	public function test_preload_bounds_index_requests_and_rejects_oversized_documents() {
		$root = home_url( '/sitemap.xml' );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'preload_enabled' => true, 'preload_sitemap_url' => $root,
		) ) );
		$body = '<sitemapindex>';
		for ( $index = 0; $index < 60; ++$index ) {
			$body .= '<sitemap><loc>' . home_url( '/child-' . $index . '.xml' ) . '</loc></sitemap>';
		}
		$body .= '</sitemapindex>';
		$requests = array();
		$mock = static function ( $preempt, $args, $url ) use ( $root, $body, &$requests ) {
			$requests[] = $url;
			return array( 'headers' => array(), 'body' => $url === $root ? $body : '', 'response' => array( 'code' => $url === $root ? 200 : 404 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( 0, $result['count'] );
		$this->assertCount( 50, $requests );

		$seen_args = array();
		$mock = static function ( $preempt, $args, $url ) use ( &$seen_args ) {
			$seen_args = $args;
			return array( 'headers' => array(), 'body' => str_repeat( 'x', 2 * MB_IN_BYTES + 1 ), 'response' => array( 'code' => 200 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( 0, $result['count'] );
		$this->assertSame( 2 * MB_IN_BYTES + 1, $seen_args['limit_response_size'] );
		$this->assertSame( 0, $seen_args['redirection'] );
	}

	/** Nested indexes have a depth ceiling and redirects never become page URLs. */
	public function test_preload_bounds_index_depth_and_ignores_redirect_responses() {
		$root = home_url( '/sitemap-0.xml' );
		update_option( HSPSC_Settings::OPTION_KEY, array_merge( HSPSC_Settings::defaults(), array(
			'preload_enabled' => true, 'preload_sitemap_url' => $root,
		) ) );
		$requests = array();
		$mock = static function ( $preempt, $args, $url ) use ( &$requests ) {
			$requests[] = $url;
			preg_match( '/sitemap-(\d+)\.xml/', $url, $matches );
			$next = home_url( '/sitemap-' . ( (int) $matches[1] + 1 ) . '.xml' );
			return array( 'headers' => array(), 'body' => '<sitemapindex><sitemap><loc>' . $next . '</loc></sitemap></sitemapindex>', 'response' => array( 'code' => 200 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( 0, $result['count'] );
		$this->assertCount( 6, $requests );
		$mock = static function () {
			return array( 'headers' => array( 'location' => 'https://example.net/' ), 'body' => '<urlset><url><loc>' . home_url( '/should-not-warm/' ) . '</loc></url></urlset>', 'response' => array( 'code' => 301 ), 'cookies' => array() );
		};
		add_filter( 'pre_http_request', $mock, 10, 3 );
		try {
			$result = HSPSC_Preload::run();
		} finally {
			remove_filter( 'pre_http_request', $mock, 10 );
		}
		$this->assertSame( 0, $result['count'] );
	}
}
