<?php
declare(strict_types=1);

namespace Two\Gateway\Test\Unit\Model;

use Magento\Framework\Phrase;
use PHPUnit\Framework\TestCase;
use Two\Gateway\Api\BrandRegistryInterface;
use Two\Gateway\Api\Config\RepositoryInterface as ConfigRepository;
use Two\Gateway\Model\Two;

/**
 * Tests for error-handling logic in Two::getErrorFromResponse()
 * and Two::getFieldNameFromLoc().
 */
class TwoErrorHandlingTest extends TestCase
{
    /** @var Two */
    private $model;

    protected function setUp(): void
    {
        $this->model = $this->getMockBuilder(Two::class)
            ->disableOriginalConstructor()
            ->onlyMethods([]) // we test real implementations
            ->getMock();

        // Inject a configRepository so that PROVIDER constant is accessible.
        $configRepo = $this->createMock(ConfigRepository::class);
        $ref = new \ReflectionClass(Two::class);
        $prop = $ref->getProperty('configRepository');
        $prop->setAccessible(true);
        $prop->setValue($this->model, $configRepo);

        // Inject a brandRegistry so error messages can reference the brand name.
        $brand = $this->createMock(BrandRegistryInterface::class);
        $brand->method('getProductName')->willReturn('Two');
        $brandProp = $ref->getProperty('brandRegistry');
        $brandProp->setAccessible(true);
        $brandProp->setValue($this->model, $brand);
    }

    // ── getErrorFromResponse ────────────────────────────────────────────

    public function testSuccessfulResponseReturnsNull(): void
    {
        $response = ['status' => 'APPROVED', 'id' => 'abc-123'];
        $this->assertNull($this->model->getErrorFromResponse($response));
    }

    public function testEmptyResponseReturnsGeneralError(): void
    {
        $result = $this->model->getErrorFromResponse([]);
        $this->assertInstanceOf(Phrase::class, $result);
        $rendered = $result->render();
        $this->assertStringContainsString('Something went wrong', $rendered);
        $this->assertStringContainsString('Two', $rendered);
    }

    // ── Validation errors (400 + error_json) ────────────────────────────

    public function testValidationErrorWithKnownFieldAndMessage(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                [
                    'loc' => ['buyer', 'representative', 'phone_number'],
                    'msg' => 'Invalid phone number',
                ],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringContainsString('Phone Number', $rendered);
        $this->assertStringContainsString('Invalid phone number', $rendered);
        // Validation errors must NOT include a trace ID
        $this->assertStringNotContainsString('Trace ID', $rendered);
    }

    public function testValidationErrorFieldOnlyNoMessage(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                ['loc' => ['buyer', 'representative', 'email']],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringContainsString('Email Address', $rendered);
        $this->assertStringContainsString('is not valid', $rendered);
    }

    public function testValidationErrorUnknownLocWithMessage(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                ['loc' => ['some', 'unknown', 'field'], 'msg' => 'Bad value'],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $this->assertStringContainsString('Bad value', $result->render());
    }

    public function testValidationErrorMultipleErrors(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                ['loc' => ['buyer', 'representative', 'first_name'], 'msg' => 'Required'],
                ['loc' => ['buyer', 'representative', 'last_name'], 'msg' => 'Required'],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringContainsString('First Name', $rendered);
        $this->assertStringContainsString('Last Name', $rendered);
    }

    public function testValidationErrorCleansPydanticPrefix(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                ['loc' => ['some', 'field'], 'msg' => 'Value error, bad input'],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringNotContainsString('Value error,', $rendered);
        $this->assertStringContainsString('bad input', $rendered);
    }

    public function testValidationErrorCleansPydanticSuffix(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                ['loc' => ['some', 'field'], 'msg' => 'bad value [type=value_error]'],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringNotContainsString('[type=', $rendered);
        $this->assertStringContainsString('bad value', $rendered);
    }

    public function testValidationErrorNoDoublePeriods(): void
    {
        $response = [
            'http_status' => 400,
            'error_json' => [
                [
                    'loc' => ['buyer', 'representative', 'phone_number'],
                    'msg' => 'Invalid phone number.',
                ],
            ],
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        // rtrim(msg, '.') then append '.' → single period
        $this->assertStringNotContainsString('..', $rendered);
    }

    // ── User errors, and every refusal at order create ──────────────────

    /**
     * Elsewhere a 400 user error shows its own message with no trace ID. At
     * order create (TWO-26259) any status of 400 or above shows the field
     * messages, else the same-company message, else the generic notice, as
     * the WooCommerce plugin does; no API message or trace ID reaches the
     * buyer. A call with no HTTP response shows the general error.
     *
     * @return array<string, array{0: array, 1: bool, 2: string}>
     */
    public static function userErrorCases(): array
    {
        $generic = 'Invoice purchase with Two is not available for this order.';
        $sameCompany = 'The buyer and the seller are the same company.';
        $general = 'Something went wrong with your request to Two. Please try again later.';
        $phone = 'Phone Number: Invalid phone number.';
        $err = static fn(int $status, string $code, array $extra = []): array => [
            'http_status' => $status,
            'error_code' => $code,
            'error_message' => 'api text',
            'error_trace_id' => 'abc-123-trace',
        ] + $extra;
        $fieldJson = ['error_json' => [['loc' => ['buyer', 'representative', 'phone_number'], 'msg' => 'Invalid phone number.']]];
        return [
            // [response, atOrderCreate, expected message]
            'SCHEMA_ERROR elsewhere shows the API message' => [$err(400, 'SCHEMA_ERROR'), false, 'api text'],
            'ORDER_INVALID elsewhere shows the API message' => [$err(400, 'ORDER_INVALID'), false, 'api text'],
            'SAME_BUYER_SELLER_ERROR elsewhere shows same company' => [$err(400, 'SAME_BUYER_SELLER_ERROR'), false, $sameCompany],
            'SCHEMA_ERROR at create shows the generic notice' => [$err(400, 'SCHEMA_ERROR'), true, $generic],
            'ORDER_INVALID at create shows the generic notice' => [$err(400, 'ORDER_INVALID'), true, $generic],
            '400 with another code at create shows the generic notice' => [$err(400, 'SOME_OTHER_ERROR'), true, $generic],
            '422 at create shows the generic notice' => [$err(422, 'UNPROCESSABLE'), true, $generic],
            '500 at create shows the generic notice' => [$err(500, 'INTERNAL_ERROR'), true, $generic],
            '503 without a body at create shows the generic notice' => [['http_status' => 503], true, $generic],
            'SAME_BUYER_SELLER_ERROR at create shows same company' => [$err(400, 'SAME_BUYER_SELLER_ERROR'), true, $sameCompany],
            '400 with error_json at create names the field' => [$err(400, 'SCHEMA_ERROR', $fieldJson), true, $phone],
            '422 with error_json at create names the field' => [$err(422, 'SCHEMA_ERROR', $fieldJson), true, $phone],
            'no HTTP response at create shows the general error' => [['error_code' => 400, 'error_message' => 'Could not resolve host'], true, $general],
        ];
    }

    /**
     * @dataProvider userErrorCases
     */
    public function testUserError(array $response, bool $atOrderCreate, string $expected): void
    {
        $rendered = $this->model->getErrorFromResponse($response, $atOrderCreate)->render();
        $this->assertSame($expected, $rendered, $this->dataName());
    }

    public function testSuccessAtCreateReturnsNull(): void
    {
        $this->assertNull($this->model->getErrorFromResponse(['status' => 'APPROVED', 'id' => 'abc-123'], true));
    }

    // ── System errors (non-400 + error_code) ────────────────────────────

    public function testSystemErrorWithTraceId(): void
    {
        $response = [
            'http_status' => 500,
            'error_code' => 'INTERNAL_ERROR',
            'error_message' => 'Something broke',
            'error_trace_id' => 'abc-123-trace',
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringContainsString('failed', $rendered);
        $this->assertStringContainsString('Something broke', $rendered);
        $this->assertStringContainsString('abc-123-trace', $rendered);
        $this->assertStringContainsString('Trace ID', $rendered);
    }

    public function testSystemErrorWithoutTraceId(): void
    {
        $response = [
            'http_status' => 500,
            'error_code' => 'INTERNAL_ERROR',
            'error_message' => 'Something broke',
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        $this->assertStringContainsString('failed', $rendered);
        $this->assertStringNotContainsString('Trace ID', $rendered);
        $this->assertStringNotContainsString('[', $rendered);
    }

    public function testNon400WithErrorJsonSkipsValidationPath(): void
    {
        $response = [
            'http_status' => 500,
            'error_json' => [
                ['loc' => ['buyer', 'representative', 'email'], 'msg' => 'bad'],
            ],
            'error_code' => 'INTERNAL_ERROR',
            'error_message' => 'Server error',
        ];
        $result = $this->model->getErrorFromResponse($response);
        $rendered = $result->render();
        // Should hit the system-error path, not validation
        $this->assertStringContainsString('failed', $rendered);
        $this->assertStringNotContainsString('Email Address', $rendered);
    }

    // ── getFieldNameFromLoc ─────────────────────────────────────────────

    /**
     * @dataProvider knownFieldMappingsProvider
     */
    public function testKnownFieldMappings(string $locJson, string $expectedField): void
    {
        $result = $this->model->getFieldNameFromLoc($locJson);
        $this->assertNotNull($result);
        $this->assertEquals($expectedField, $result->render());
    }

    public function knownFieldMappingsProvider(): array
    {
        return [
            'phone'    => ['["buyer","representative","phone_number"]', 'Phone Number'],
            'org_num'  => ['["buyer","company","organization_number"]', 'Company Number'],
            'fname'    => ['["buyer","representative","first_name"]', 'First Name'],
            'lname'    => ['["buyer","representative","last_name"]', 'Last Name'],
            'email'    => ['["buyer","representative","email"]', 'Email Address'],
            'street'   => ['["billing_address","street_address"]', 'Street Address'],
            'city'     => ['["billing_address","city"]', 'City'],
            'country'  => ['["billing_address","country"]', 'Country'],
            'postcode' => ['["billing_address","postal_code"]', 'Zip/Postal Code'],
        ];
    }

    public function testUnknownLocReturnsNull(): void
    {
        $this->assertNull($this->model->getFieldNameFromLoc('["unknown","field"]'));
    }

    public function testWhitespaceInLocIsNormalised(): void
    {
        $locWithSpaces = '[ "buyer" , "representative" , "phone_number" ]';
        $result = $this->model->getFieldNameFromLoc($locWithSpaces);
        $this->assertNotNull($result);
        $this->assertEquals('Phone Number', $result->render());
    }
}
