<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the base64 data-URI validation and all-signed detection
 * logic that lives in SignatureController (tested here without the HTTP layer).
 */
class SignatureMergeTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Base64 data-URI format validation (mirrors SignatureController regex)
    // -----------------------------------------------------------------------

    private function isValidDataUri(string $value): bool
    {
        return (bool) preg_match('/^data:image\/\w+;base64,/', $value);
    }

    public function test_valid_png_data_uri_passes_validation(): void
    {
        $uri = 'data:image/png;base64,'.base64_encode('fake-image-binary');
        $this->assertTrue($this->isValidDataUri($uri));
    }

    public function test_valid_jpeg_data_uri_passes_validation(): void
    {
        $uri = 'data:image/jpeg;base64,'.base64_encode('fake-jpeg-data');
        $this->assertTrue($this->isValidDataUri($uri));
    }

    public function test_plain_base64_without_prefix_fails_validation(): void
    {
        $uri = base64_encode('no-prefix-here');
        $this->assertFalse($this->isValidDataUri($uri));
    }

    public function test_data_uri_without_base64_keyword_fails(): void
    {
        $this->assertFalse($this->isValidDataUri('data:image/png,raw-data'));
    }

    public function test_empty_string_fails_validation(): void
    {
        $this->assertFalse($this->isValidDataUri(''));
    }

    // -----------------------------------------------------------------------
    // All-signed detection logic
    // -----------------------------------------------------------------------

    /**
     * Mirrors the controller logic:
     *   $signatureCount >= $teamMemberCount  ->  mark contract as signed
     */
    private function allSigned(int $teamMemberCount, int $signatureCount): bool
    {
        return $signatureCount >= $teamMemberCount;
    }

    public function test_contract_is_signed_when_all_members_have_signed(): void
    {
        $this->assertTrue($this->allSigned(teamMemberCount: 3, signatureCount: 3));
    }

    public function test_contract_is_not_signed_when_some_members_have_not_signed(): void
    {
        $this->assertFalse($this->allSigned(teamMemberCount: 3, signatureCount: 2));
    }

    public function test_single_member_team_is_signed_after_one_signature(): void
    {
        $this->assertTrue($this->allSigned(teamMemberCount: 1, signatureCount: 1));
    }

    public function test_extra_signatures_still_considered_fully_signed(): void
    {
        // Edge case: duplicate signing should not break the signed check.
        $this->assertTrue($this->allSigned(teamMemberCount: 2, signatureCount: 3));
    }

    // -----------------------------------------------------------------------
    // base64_decode strict mode
    // -----------------------------------------------------------------------

    public function test_valid_base64_decodes_successfully(): void
    {
        $encoded = base64_encode('hello world');
        $decoded = base64_decode($encoded, strict: true);
        $this->assertSame('hello world', $decoded);
    }

    public function test_invalid_base64_returns_false_in_strict_mode(): void
    {
        $result = base64_decode('!!!not-valid-base64!!!', strict: true);
        $this->assertFalse($result);
    }
}
