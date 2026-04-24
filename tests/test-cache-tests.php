<?php

class HSP_Smart_Cache_Tests_Runner_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        HSP_Smart_Cache_Utils::ensure_cache_dirs();
        update_option( HSP_Smart_Cache_Settings::OPTION_KEY, HSP_Smart_Cache_Settings::defaults() );
    }

    public function test_run_returns_expected_result_set() {
        $results = HSP_Smart_Cache_Tests::run();

        $this->assertIsArray( $results );
        $this->assertCount( 5, $results );

        foreach ( $results as $result ) {
            $this->assertArrayHasKey( 'label', $result );
            $this->assertArrayHasKey( 'status', $result );
            $this->assertArrayHasKey( 'details', $result );
            $this->assertIsBool( $result['status'] );
        }
    }
}
