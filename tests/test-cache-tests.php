<?php

class HSPSC_Tests_Runner_Test extends WP_UnitTestCase {
    public function set_up(): void {
        parent::set_up();
        HSPSC_Utils::ensure_cache_dirs();
        update_option( HSPSC_Settings::OPTION_KEY, HSPSC_Settings::defaults() );
    }

    public function test_run_returns_expected_result_set() {
        $results = HSPSC_Tests::run();

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
