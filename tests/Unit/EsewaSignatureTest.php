<?php
use BookstoreNepal\Gateway\EsewaSignature;

class EsewaSignatureTest extends WP_UnitTestCase {

	private const TEST_SECRET = '8gBm/:&EnhH.1/q';

	/**
	 * Message and secret are verbatim from the eSewa ePay docs
	 * (developer.esewa.com.np/pages/Epay, "HMAC/SHA256" section, fetched 2026-11-19).
	 *
	 * The docs print "4Ov7pCI1zIOdwtV2BRMUNjz1upIlT/COTxfLhWvVurE=" as the result, but that value
	 * is not the HMAC of that message: eSewa's own sandbox rejects it ("ES104 Invalid payload
	 * signature") and accepts the value below. Expected value is from an independent run of
	 * printf '%s' "<message>" | openssl dgst -sha256 -hmac '8gBm/:&EnhH.1/q' -binary | base64
	 */
	public function test_sign_matches_esewa_doc_example(): void {
		$fields = array(
			'total_amount'     => '100',
			'transaction_uuid' => '11-201-13',
			'product_code'     => 'EPAYTEST',
		);
		$this->assertSame(
			'5DZywcrTKD0gia/rsSMcrRHmJl+4Tbol6S+lWgdJ94E=',
			EsewaSignature::sign( $fields, array( 'total_amount', 'transaction_uuid', 'product_code' ), self::TEST_SECRET )
		);
	}

	public function test_sign_uses_given_field_order(): void {
		$fields = array(
			'product_code'     => 'EPAYTEST',
			'transaction_uuid' => '11-201-13',
			'total_amount'     => '100',
		);
		$this->assertSame(
			'5DZywcrTKD0gia/rsSMcrRHmJl+4Tbol6S+lWgdJ94E=',
			EsewaSignature::sign( $fields, array( 'total_amount', 'transaction_uuid', 'product_code' ), self::TEST_SECRET )
		);
	}

	/**
	 * A callback payload shaped like the docs' decoded success response, signed with the test secret.
	 */
	private function callback_payload(): array {
		$payload = array(
			'transaction_code'   => '0LD5CEH',
			'status'             => 'COMPLETE',
			'total_amount'       => '1,000.0',
			'transaction_uuid'   => '240613-134231',
			'product_code'       => 'EPAYTEST',
			'signed_field_names' => 'transaction_code,status,total_amount,transaction_uuid,product_code,signed_field_names',
		);
		$payload['signature'] = EsewaSignature::sign( $payload, explode( ',', $payload['signed_field_names'] ), self::TEST_SECRET );
		return $payload;
	}

	public function test_verify_accepts_untouched_payload(): void {
		$payload = $this->callback_payload();
		// Independent oracle: openssl over the message with commas inside total_amount.
		$this->assertSame( 'Hs5EUgK7jwV2ix63Pd0aUrzoHmpqeu9f50qonJRRx5o=', $payload['signature'] );
		$this->assertTrue( EsewaSignature::verify( $payload, self::TEST_SECRET ) );
	}

	public function test_verify_rejects_modified_amount(): void {
		$payload                 = $this->callback_payload();
		$payload['total_amount'] = '10.0';
		$this->assertFalse( EsewaSignature::verify( $payload, self::TEST_SECRET ) );
	}

	public function test_verify_rejects_wrong_secret(): void {
		$this->assertFalse( EsewaSignature::verify( $this->callback_payload(), 'not-the-secret' ) );
	}

	public function test_verify_rejects_missing_or_malformed_fields(): void {
		$payload = $this->callback_payload();
		unset( $payload['signature'] );
		$this->assertFalse( EsewaSignature::verify( $payload, self::TEST_SECRET ), 'No signature.' );

		$payload = $this->callback_payload();
		unset( $payload['status'] );
		$this->assertFalse( EsewaSignature::verify( $payload, self::TEST_SECRET ), 'Signed field missing.' );

		$payload           = $this->callback_payload();
		$payload['status'] = array( 'COMPLETE' );
		$this->assertFalse( EsewaSignature::verify( $payload, self::TEST_SECRET ), 'Non-scalar value.' );

		$payload                       = $this->callback_payload();
		$payload['signed_field_names'] = '';
		$this->assertFalse( EsewaSignature::verify( $payload, self::TEST_SECRET ), 'No signed fields.' );
	}
}
