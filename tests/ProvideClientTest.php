<?php

class ProvideClientTest extends PHPUnit_Framework_TestCase {
    /**
     * @var \GuzzleHttp\Client
     */
    private $client;

    /**
     * @var \GuzzleHttp\Handler\MockHandler
     */
    private $mock;

    /**
     * Baseline customer fixture to use in tests
     * @var array
     */
    private $customer;

    /**
     * Baseline payment method fixture to use in tests
     * @var array
     */
    private $payment_method;

    /**
     * Baseline recipient fixture to use in tests
     * @var array
     */
    private $recipient;

    /**
     * Baseline order fixture to use in getOrderDetails() tests
     * @var array
     */
    private $order_for_details;

    /**
     * Baseline order fixture to use in createOrder() tests
     * @var array
     */
    private $order_for_creation;

    /**
     * Mock API response from Provide from getCustomer()/getCustomerByEmail()
     * @var array
     */
    private $mock_provide_guest_customer_json;

    // Note: there's no rhyme or reason to these values, besides being strings
    const EMAIL                = 'test@example.com';
    const PASSWORD             = 'testing123';
    const CUSTOMER_ID          = '1234567890A';
    const PAYMENT_METHOD_ID    = '9876543210B';
    const PAYMENT_METHOD_TOKEN = '1010101010Z';
    const PRODUCT_ID           = '111222333CC';
    const ORDER_ID             = '7777777780A';
    const AUTHENTICATION_TOKEN = 'abcdefghijklmnopqrstuvwxyz1234567890';
    const APPLICATION_TOKEN    = 'xxxxxxxxxxx';

    // There is a rhyme and a reason to these values being JSON, and not, respectively
    const ODD_JSON             = '{"foo":"bar"}';
    const BAD_JSON             = "these aren't the JSONs you're looking for";

    /**
     * What's happening here is crucially important.
     */
    public function setUp() {
        // 1. Create a MockHandler and wrap it as a HandlerStack
        $this->mock = new \GuzzleHttp\Handler\MockHandler;
        $handler = \GuzzleHttp\HandlerStack::create($this->mock);

        // 2. Create a GuzzleHTTP Client, and pass it the HandlerStack-wrapped MockHandler
        $client = new \GuzzleHttp\Client(['handler' => $handler]);

        // 3. Create a mock PSR-3 logger
        $logger = $this->getMock('\Psr\Log\AbstractLogger');

        // 4. "inject" the Guzzle HTTP client this into our Provide\Client (the "system under test")
        $this->client = new Provide\Client($client, $logger, self::APPLICATION_TOKEN);

        // Reset baseline fixtures
        $this->customer = [
            'firstname'   => 'String',
            'lastname'    => 'String',
            'company'     => 'String',
            'street1'     => 'String',
            'street2'     => 'String',
            'city'        => 'String',
            'state'       => 'String',
            'postalcode'  => 'String',
            'countrycode' => 'String',
            'phone'       => 'String',
        ];
        $this->payment_method = [
            'card_number'   => 'String',
            'expiry'        => 'String',
            'security_code' => 'String',
        ];
        $this->recipient = $this->customer;
        $this->recipient['location_type'] = 'Residental';
        $this->order_for_details = [
            'delivery_date'        => '2015-01-01',
            'latest_delivery_date' => '2015-01-01',
            'gift_message'         => 'String',
            'product_id'           => 'String',
            'promo_code'           => 'String',
        ];
        $this->order_for_creation = [
            'delivery_date' => '2015-01-01',
            'gift_message'  => 'String',
            'product_id'    => 'String',
            'promo_code'    => 'String',
            'payment_token' => 'String',
            'po_number'     => 'String',
        ];

        $this->mock_provide_guest_customer_json = [
            'Customer' => [
                'CustomerId' => self::CUSTOMER_ID,
                'Details' => null,
            ],
        ];

    }

    /**
     * Helper function to queue a mock HTTP response with Guzzle
     *
     * @param  mixed   $body        Defaults to null
     * @param  integer $status_code Defaults to 200
     * @param  array   $headers     Defaults to an empty array
     *
     * @return null
     */
    private function queueResponse($body = null, $status_code = '200', array $headers = []) {
        if (is_null($body)) {
            $body = '';
        } elseif (is_array($body)) {
            $body = json_encode($body, JSON_FORCE_OBJECT);
        }

        $this->mock->append(
            new \GuzzleHttp\Psr7\Response($status_code, $headers, $body)
        );
    }

    /**
     * Handy function to generate array structure to represent the JSON of a Provide API get_order_totals() response
     *
     * @param  float $item_amount
     * @param  float $shipping_amount
     * @param  float $tax_amount
     *
     * @return array
     */
    private function createProvideGetOrderTotalsResponse($item_amount, $shipping_amount, $tax_amount) {
        $total_amount = $item_amount + $shipping_amount + $tax_amount;

        // The response includes other details, but these are the only fields we're concerned with.
        return [
            'Order' => [
                'Deliveries' => [
                    [
                        'LineItems' => [
                            [
                                'Details' => [
                                    'Price' => $item_amount,
                                ],
                            ],
                        ],
                        'SurchargeDetails' => [
                            [
                                'Amount' => $shipping_amount,
                            ],
                        ],
                    ],
                ],
                'Details' => [
                    'GrandTotal' => $total_amount,
                    'Tax'        => $tax_amount,
                ],
                'Payments' => [
                ],
                'SurchargeDetails' => [
                ],
            ],
        ];
    }

    private function createAddress(array $params = []) {
        $good_address = [
            'firstname'   => 'Firstname',
            'lastname'    => 'Lastname',
            'company'     => 'Company',
            'street1'     => 'Street 1',
            'street2'     => 'Street 2',
            'city'        => 'City',
            'state'       => 'CA',
            'postalcode'  => '11111',
            'countrycode' => 'US',
            'phone'       => '',
        ];

        return array_merge($good_address, $params);
    }

    public function testFixGiftMessage() {
        $tests = [
            // One word, fits on one line
            [
                "xxx",

                "xxx                                         ",

                "xxx",
            ],

            // One word, with leading spaces is maintained
            [
                "   xxx",

                "   xxx                                      ",

                "xxx",
            ],

            // One word, fits on one line exactly
            [
                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
            ],

            // One word with too many characters to fit on one line exactly is forced to break
            [
                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n" .
                "x                                           ",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
            ],

            // Multiple words, fits on one line
            [
                "xxx xxx",

                "xxx xxx                                     ",

                "xxx xxx",
            ],

            // Multiple words with extra internal/trailing spaces, fits on one line
            [
                "xxx  xxx  ",

                "xxx  xxx                                    ",

                "xxx xxx",
            ],

            // Multiple words, wraps onto next line, without leading spaces on second line
            [
                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx xxx",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n" .
                "xxx                                         ",

                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx xxx",
            ],

            // Multiple words, wraps onto next line, second word breaks onto 3rd line
            [
                "xxx xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",

                "xxx                                         \n" .
                "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx\n" .
                "x                                           ",

                "xxx xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
            ],

            // Multiple lines, no wrapping
            [
                "xxx\n" .
                "xxx",

                "xxx                                         \n" .
                "xxx                                         ",

                "xxx xxx",
            ],

            // Multiple lines with one blank line in between
            [
                "xxx\n" .
                "\n" .
                "xxx",

                "xxx                                         \n" .
                "                                            \n" .
                "xxx                                         ",

                "xxx xxx",
            ],
            // Leading newlines (with leading spaces)
            [
                "\n" .
                "  xxx",

                "  xxx                                       ",

                "xxx",
            ],

            // Trailing newlines
            [
                "xxx\n" .
                "  \n",

                "xxx                                         ",

                "xxx",
            ],

            // Multiple lines with multiple words, each line wraps onto next line
            [
                "xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx\n" .
                "xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx",

                "xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx \n" .
                "xxx                                         \n" .
                "xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx \n" .
                "xxx                                         ",

                "xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx xxx",
            ],

            // Accented characters get de-accented, invalid characters are replaced with spaces
            [
                "Iñtërnâtiônàlizætiøn^|~",

                "Internationalizaetion                       ",

                "Internationalizaetion",
            ],

            // Uncurl quotes
            [
                "“Mother’s Day”",

                '"Mother\'s Day"                              ',

                '"Mother\'s Day"',
            ],
        ];

        foreach ($tests as $test) {
            $this->assertEquals($test[1], $this->client->fixGiftMessage($test[0], 44, true));  // true = does support virtual newlines
            $this->assertEquals($test[2], $this->client->fixGiftMessage($test[0], 44, false)); // false = does not support virtual newlines
        }
    }

    public function testValidateCustomerBaseline() {
        $this->assertTrue($this->client->validateCustomer($this->createAddress()));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingFirstname() {
        $this->client->validateCustomer($this->createAddress(['firstname' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongFirstname() {
        $this->client->validateCustomer($this->createAddress(['firstname' => str_repeat('X', 21)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadFirstname() {
        $this->client->validateCustomer($this->createAddress(['firstname' => 'Badname*']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingLastname() {
        $this->client->validateCustomer($this->createAddress(['lastname' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongLastname() {
        $this->client->validateCustomer($this->createAddress(['lastname' => str_repeat('X', 41)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadLastname() {
        $this->client->validateCustomer($this->createAddress(['lastname' => 'Badname*']));
    }

    public function testValidateCustomerMissingCompanySucceeds() {
        $this->assertTrue($this->client->validateCustomer($this->createAddress(['company' => ''])));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongCompany() {
        $this->client->validateCustomer($this->createAddress(['company' => str_repeat('X', 41)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadCompany() {
        $this->client->validateCustomer($this->createAddress(['lastname' => '[Badname]']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingStreet1() {
        $this->client->validateCustomer($this->createAddress(['street1' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongStreet1() {
        $this->client->validateCustomer($this->createAddress(['street1' => str_repeat('X', 41)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadStreet1() {
        $this->client->validateCustomer($this->createAddress(['street1' => '[Badname]']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerPoBoxStreet1() {
        $this->client->validateCustomer($this->createAddress(['street1' => 'PO Box 12345']));
    }

    public function testValidateCustomerMissingStreet2Succeeds() {
        $this->assertTrue($this->client->validateCustomer($this->createAddress(['street2' => ''])));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongStreet2() {
        $this->client->validateCustomer($this->createAddress(['street2' => str_repeat('X', 41)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadStreet2() {
        $this->client->validateCustomer($this->createAddress(['street2' => '[Badname]']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerPoBoxStreet2() {
        $this->client->validateCustomer($this->createAddress(['street2' => 'PO Box 12345']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingCity() {
        $this->client->validateCustomer($this->createAddress(['city' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerTooLongCity() {
        $this->client->validateCustomer($this->createAddress(['city' => str_repeat('X', 41)]));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadCity() {
        $this->client->validateCustomer($this->createAddress(['city' => '[Badname]']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadCountry() {
        $this->client->validateCustomer($this->createAddress(['countrycode' => 'USA']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingState() {
        $this->client->validateCustomer($this->createAddress(['countrycode' => 'US', 'state' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadState() {
        $this->client->validateCustomer($this->createAddress(['countrycode' => 'US', 'state' => 'California']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerMissingPostalcode() {
        $this->client->validateCustomer($this->createAddress(['countrycode' => 'US', 'postalcode' => '']));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testValidateCustomerBadPostalcode() {
        $this->client->validateCustomer($this->createAddress(['countrycode' => 'US', 'postalcode' => 'abcef']));
    }

    public function testDoesCustomerExist() {
        $data = [
            'CustomerExists' => false,
        ];
        $this->queueResponse($data);
        $this->assertEquals(false, $this->client->doesCustomerExist(self::EMAIL));

        $data = [
            'CustomerExists' => true,
        ];
        $this->queueResponse($data);
        $this->assertEquals(true, $this->client->doesCustomerExist(self::EMAIL));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testDoesCustomerExistBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->doesCustomerExist(self::EMAIL);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testDoesCustomerExistOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->doesCustomerExist(self::EMAIL);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testDoesCustomerExistFailure() {
        $this->queueResponse('', 500);
        $this->client->doesCustomerExist(self::EMAIL);
    }

    public function testCreateCustomer() {
        $data = [
            'CustomerId' => self::CUSTOMER_ID,
            'AuthenticationContext' => [
                'AuthenticationToken' => self::AUTHENTICATION_TOKEN,
            ],
        ];
        $this->queueResponse($data);

        $response = $this->client->createCustomer(self::EMAIL, self::PASSWORD);
        $this->assertArrayHasKey('customer_id', $response);
        $this->assertArrayHasKey('authentication_token', $response);

        $this->assertEquals(self::CUSTOMER_ID, $response['customer_id']);
        $this->assertEquals(self::AUTHENTICATION_TOKEN, $response['authentication_token']);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateCustomerFailure() {
        $data = [
            'FaultType' => 'Unknown',
        ];
        $this->queueResponse($data, 500);
        $this->client->createCustomer(self::EMAIL, self::PASSWORD);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateCustomerBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->createCustomer(self::EMAIL, self::PASSWORD);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateCustomerOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->createCustomer(self::EMAIL, self::PASSWORD);
    }

    public function testLogin() {
        $data = [
            'AuthenticationContext' => [
                'AuthenticationToken' => self::AUTHENTICATION_TOKEN,
            ],
        ];
        $this->queueResponse($data);
        $this->assertEquals(self::AUTHENTICATION_TOKEN, $this->client->login(self::EMAIL, self::PASSWORD));

        $data = [
            'FaultType' => 'LoginFailed',
        ];
        $this->queueResponse($data, 403);
        $this->assertEquals(false, $this->client->login(self::EMAIL, self::PASSWORD));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testLoginBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->login(self::EMAIL, self::PASSWORD);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testLoginOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->login(self::EMAIL, self::PASSWORD);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testLoginFailure() {
        $this->queueResponse('', 500);
        $this->client->login(self::EMAIL, self::PASSWORD);
    }

    public function testGetCustomerById() {
        $data = [
            'Customer' => [
                'CustomerId' => self::CUSTOMER_ID,
                'Details' => [
                    'Address1'    => 'String',
                    'Address2'    => 'String',
                    'City'        => 'String',
                    'CompanyName' => 'String',
                    'CountryCode' => 'String',
                    'EmailOptIn'  => true,
                    'FirstName'   => 'String',
                    'LastName'    => 'String',
                    'Phone1'      => 'String',
                    'Phone2'      => 'String',
                    'State'       => 'String',
                    'Zip'         => 'String',
                ],
            ],
        ];
        $this->queueResponse($data);

        $expected = [
            'customer_id' => self::CUSTOMER_ID,
            'firstname'   => 'String',
            'lastname'    => 'String',
            'company'     => 'String',
            'street1'     => 'String',
            'street2'     => 'String',
            'city'        => 'String',
            'state'       => 'String',
            'postalcode'  => 'String',
            'countrycode' => 'String',
            'phone'       => 'String',
        ];

        $this->assertEquals($expected, $this->client->getCustomerById(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * If the customer refers to a "guest account", the Details object comes back null
     */
    public function testGetCustomerByIdWithNullDetails() {
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $expected = [
            'customer_id' => self::CUSTOMER_ID,
            'firstname'   => '',
            'lastname'    => '',
            'company'     => '',
            'street1'     => '',
            'street2'     => '',
            'city'        => '',
            'state'       => '',
            'postalcode'  => '',
            'countrycode' => '',
            'phone'       => '',
        ];

        $this->assertEquals($expected, $this->client->getCustomerById(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerByIdBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getCustomerById(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerByIdOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getCustomerById(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    public function testGetCustomerByEmail() {
        $data = [
            'Customer' => [
                'CustomerId' => self::CUSTOMER_ID,
                'Details' => [
                    'Address1'    => 'String',
                    'Address2'    => 'String',
                    'City'        => 'String',
                    'CompanyName' => 'String',
                    'CountryCode' => 'String',
                    'EmailOptIn'  => true,
                    'FirstName'   => 'String',
                    'LastName'    => 'String',
                    'Phone1'      => 'String',
                    'Phone2'      => 'String',
                    'State'       => 'String',
                    'Zip'         => 'String',
                ],
            ],
        ];
        $this->queueResponse($data);

        $expected = [
            'customer_id' => self::CUSTOMER_ID,
            'firstname'   => 'String',
            'lastname'    => 'String',
            'company'     => 'String',
            'street1'     => 'String',
            'street2'     => 'String',
            'city'        => 'String',
            'state'       => 'String',
            'postalcode'  => 'String',
            'countrycode' => 'String',
            'phone'       => 'String',
        ];

        $this->assertEquals($expected, $this->client->getCustomerByEmail(self::EMAIL, self::AUTHENTICATION_TOKEN));
    }

    /**
     * If the customer refers to a "guest account", the Details object comes back null
     */
    public function testGetCustomerByEmailWithNullDetails() {
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $expected = [
            'customer_id' => self::CUSTOMER_ID,
            'firstname'   => '',
            'lastname'    => '',
            'company'     => '',
            'street1'     => '',
            'street2'     => '',
            'city'        => '',
            'state'       => '',
            'postalcode'  => '',
            'countrycode' => '',
            'phone'       => '',
        ];

        $this->assertEquals($expected, $this->client->getCustomerByEmail(self::EMAIL, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerByEmailBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getCustomerByEmail(self::EMAIL, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerByEmailOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getCustomerByEmail(self::EMAIL, self::AUTHENTICATION_TOKEN);
    }

    public function testUpdateCustomer() {
        $this->queueResponse([]);
        $this->assertEquals(true, $this->client->updateCustomer(self::CUSTOMER_ID, $this->customer, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testUpdateCustomerValidationFailed() {
        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'String',
        ];

        $this->queueResponse($data, 403);
        $this->client->updateCustomer(self::CUSTOMER_ID, $this->customer, self::AUTHENTICATION_TOKEN);
    }


    public function testGetCustomerRecipients() {
        $data = [
            'Recipients' => [
                [
                    'Address1'         => 'String',
                    'Address2'         => 'String',
                    'BirthDate'        => 'String',
                    'City'             => 'String',
                    'CompanyName'      => 'String',
                    'CountryCode'      => 'String',
                    'Email'            => 'String',
                    'FirstName'        => 'String',
                    'LastName'         => 'String',
                    'LocationType'     => 'String',
                    'Phone'            => 'String',
                    'RecipientId'      => 'String',
                    'RelationshipType' => 'String',
                    'State'            => 'String',
                    'Zip'              => 'String',
                    'RelationshipId'   => 'String',
                ],
            ],
        ];

        $expected = [
            [
                'recipient_id' => 'String',
                'firstname'    => 'String',
                'lastname'     => 'String',
                'company'      => 'String',
                'street1'      => 'String',
                'street2'      => 'String',
                'city'         => 'String',
                'state'        => 'String',
                'postalcode'   => 'String',
                'countrycode'  => 'String',
                'phone'        => 'String',
            ],
        ];

        $this->queueResponse($data);
        $this->assertEquals($expected, $this->client->getCustomerRecipients(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * Ensure that a null CountryCode from Provide (due to a bug) gets returns as 'US'
     */
    public function testGetCustomerRecipientsNullCountryCode() {
        $data = [
            'Recipients' => [
                [
                    'Address1'         => 'String',
                    'Address2'         => 'String',
                    'BirthDate'        => 'String',
                    'City'             => 'String',
                    'CompanyName'      => 'String',
                    'CountryCode'      => null,
                    'Email'            => 'String',
                    'FirstName'        => 'String',
                    'LastName'         => 'String',
                    'LocationType'     => 'String',
                    'Phone'            => 'String',
                    'RecipientId'      => 'String',
                    'RelationshipType' => 'String',
                    'State'            => 'String',
                    'Zip'              => 'String',
                    'RelationshipId'   => 'String',
                ],
            ],
        ];

        $expected = [
            [
                'recipient_id' => 'String',
                'firstname'    => 'String',
                'lastname'     => 'String',
                'company'      => 'String',
                'street1'      => 'String',
                'street2'      => 'String',
                'city'         => 'String',
                'state'        => 'String',
                'postalcode'   => 'String',
                'countrycode'  => 'US',
                'phone'        => 'String',
            ],
        ];

        $this->queueResponse($data);
        $this->assertEquals($expected, $this->client->getCustomerRecipients(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerRecipientsBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getCustomerRecipients(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetCustomerRecipientsOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getCustomerRecipients(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    public function testSendForgotPasswordEmail() {
        $this->queueResponse([]);
        $this->assertEquals(true, $this->client->sendForgotPasswordEmail(self::EMAIL));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testSendForgotPasswordEmailFailed() {
        $data = [
            'FaultType' => 'Unknown',
            'Message'   => 'String',
        ];

        $this->queueResponse($data, 403);
        $this->client->sendForgotPasswordEmail(self::EMAIL);
    }

    public function testCreatePaymentMethod() {
        $month = 12;
        $year  = date('Y') + 1;

        $data = [
            'PaymentMethodId' => self::PAYMENT_METHOD_ID,
            'PaymentMethod' => [
                'PaymentId'       => 'String', // Is this the same as PaymentMethodId above? Dunno...
                'Token'           => self::PAYMENT_METHOD_TOKEN,
                'CardHolderName'  => 'String',
                'CardType'        => 'String',
                'ExpirationMonth' => $month,
                'ExpirationYear'  => $year,
                'LastFour'        => 'String'
            ],
        ];
        $this->queueResponse($data);
        $this->assertEquals(self::PAYMENT_METHOD_TOKEN, $this->client->createPaymentMethod(self::CUSTOMER_ID, $this->payment_method, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreatePaymentMethodBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->createPaymentMethod(self::CUSTOMER_ID, $this->payment_method, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreatePaymentMethodOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->createPaymentMethod(self::CUSTOMER_ID, $this->payment_method, self::AUTHENTICATION_TOKEN);
    }

    public function testGetPaymentMethods() {
        // Ensure that the expiration is always in the future
        $month = 12;
        $year  = date('Y') + 1;
        $mmyy  = $month . substr($year, -2);

        $data = [
            'PaymentMethods' => [
                [
                    'PaymentId'       => self::PAYMENT_METHOD_ID,
                    'Token'           => self::PAYMENT_METHOD_TOKEN,
                    'CardHolderName'  => 'String',
                    'CardType'        => 'String',
                    'ExpirationMonth' => $month,
                    'ExpirationYear'  => $year,
                    'LastFour'        => 'String'
                ],
            ],
        ];
        $this->queueResponse($data);

        $expected = [
            [
                'payment_id'      => self::PAYMENT_METHOD_ID,
                'token'           => self::PAYMENT_METHOD_TOKEN,
                'cardholder_name' => 'String',
                'cc_type'         => 'String',
                'expiry'          => $mmyy,
                'lastfour'        => 'String',
            ],
        ];

        $this->assertEquals($expected, $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    public function testGetPaymentMethodsMultiple() {
        // Ensure that the expiration is always in the future
        $month = 12;
        $year  = date('Y') + 1;
        $mmyy  = $month . substr($year, -2);

        $data = [
            'PaymentMethods' => [
                [
                    'PaymentId'       => self::PAYMENT_METHOD_ID,
                    'Token'           => self::PAYMENT_METHOD_TOKEN,
                    'CardHolderName'  => 'String',
                    'CardType'        => 'String',
                    'ExpirationMonth' => $month,
                    'ExpirationYear'  => $year,
                    'LastFour'        => 'String'
                ],
                [
                    'PaymentId'       => self::PAYMENT_METHOD_ID . '2',
                    'Token'           => self::PAYMENT_METHOD_TOKEN . '2',
                    'CardHolderName'  => 'String 2',
                    'CardType'        => 'String 2',
                    'ExpirationMonth' => $month,
                    'ExpirationYear'  => $year,
                    'LastFour'        => 'String 2'
                ],

            ],
        ];
        $this->queueResponse($data);

        $expected = [
            [
                'payment_id'      => self::PAYMENT_METHOD_ID,
                'token'           => self::PAYMENT_METHOD_TOKEN,
                'cardholder_name' => 'String',
                'cc_type'         => 'String',
                'expiry'          => $mmyy,
                'lastfour'        => 'String',
            ],
            [
                'payment_id'      => self::PAYMENT_METHOD_ID . '2',
                'token'           => self::PAYMENT_METHOD_TOKEN .'2',
                'cardholder_name' => 'String 2',
                'cc_type'         => 'String 2',
                'expiry'          => $mmyy,
                'lastfour'        => 'String 2',
            ],
        ];

        $this->assertEquals($expected, $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    public function testGetPaymentMethodsMultipleWithExpired() {
        // Ensure that the expiration is always in the future
        $month = 12;
        $year  = date('Y') + 1;
        $mmyy  = $month . substr($year, -2);

        $data = [
            'PaymentMethods' => [
                [
                    'PaymentId'       => self::PAYMENT_METHOD_ID,
                    'Token'           => self::PAYMENT_METHOD_TOKEN,
                    'CardHolderName'  => 'String',
                    'CardType'        => 'String',
                    'ExpirationMonth' => $month,
                    'ExpirationYear'  => $year,
                    'LastFour'        => 'String',
                ],
                [
                    'PaymentId'       => self::PAYMENT_METHOD_ID . '2',
                    'Token'           => self::PAYMENT_METHOD_TOKEN . '2',
                    'CardHolderName'  => 'String 2',
                    'CardType'        => 'String 2',
                    'ExpirationMonth' => $month,
                    'ExpirationYear'  => date('Y') - 1, // Cards in the past should not be returned
                    'LastFour'        => 'String 2',
                ],
            ],
        ];
        $this->queueResponse($data);

        $expected = [
            [
                'payment_id'      => self::PAYMENT_METHOD_ID,
                'token'           => self::PAYMENT_METHOD_TOKEN,
                'cardholder_name' => 'String',
                'cc_type'         => 'String',
                'expiry'          => $mmyy,
                'lastfour'        => 'String',
            ],
        ];

        $this->assertEquals($expected, $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    public function testGetPaymentMethodsNone() {
        $data = '
            {
                "PaymentMethods": []
            }
        ';
        $this->queueResponse($data);
        $this->assertEquals([], $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * In order to prevent failing on non-credit card payment types that might be returned in the future (e.g. PayPal),
     * instead we expect that an unexpected payment method structure will simply be passed over.
     */
    public function testGetPaymentMethodsUnexpectedJson() {
        $data = [
            'PaymentMethods' => [
                [
                    'foo' => 'bar',
                ],
            ],
        ];
        $this->queueResponse($data);
        $this->assertEquals([], $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetPaymentMethodsBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetPaymentMethodsOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getPaymentMethods(self::CUSTOMER_ID, self::AUTHENTICATION_TOKEN);
    }

    public function testGetProductAvailability() {
        $data = [
            'ProductAvailabilities' => [
                [
                    'Date' => '/Date(1487635200000)/',
                ],
                [
                    'Date' => '/Date(1487721600000)/',
                ],
                [
                    'Date' => '/Date(1487808000000)/',
                ],
            ],
        ];
        $this->queueResponse($data);

        $expected = [
            '2017-02-21',
            '2017-02-22',
            '2017-02-23',
        ];

        $this->assertEquals($expected, $this->client->getProductAvailability(self::PRODUCT_ID, '11111'));
    }

    public function testGetProductAvailabilityNoResults() {
        $data = [
            'ProductAvailabilities' => [],
        ];
        $this->queueResponse($data);

        $expected = [];

        $this->assertEquals($expected, $this->client->getProductAvailability(self::PRODUCT_ID));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetProductAvailabilityBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getProductAvailability(self::PRODUCT_ID);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetProductAvailabilityOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getProductAvailability(self::PRODUCT_ID);
    }

    public function testGetOrderDetailsWithPromoCode() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue a promo code failure
        // The response includes other details, but these are the only fields we're concerned with.
        $data = [
            'Order' => [
                'Deliveries' => [
                    [
                        'LineItems' => [
                            [
                                'Details' => [
                                    'Price' => 30.00, // item_amount
                                ],
                            ],
                        ],
                        'SurchargeDetails' => [
                            [
                                'Amount' => 10.00, // shipping_amount
                            ],
                            [
                                'Amount' => 5.00,  // shipping_amount
                            ],
                        ],
                    ],
                ],
                'Details' => [
                    'GrandTotal' => 45.00, // total_amount (does not include the $10 payment)
                    'Tax'        => 5.00,  // tax_amount
                ],
                'Payments' => [
                    [
                        'Details' => [
                            'Amount' => 10.00, // discount_amount
                        ],
                    ],
                ],
                'SurchargeDetails' => [
                    [
                        'Amount' => -5.00, // discount_amount (after negation)
                    ],
                ],
            ],
        ];

        $this->queueResponse($data);

        $expected = [
            'item_amount'     => 3000,
            'shipping_amount' => 1500,
            'tax_amount'      => 500,
            'discount_amount' => 1500,
            'total_amount'    => 3500,
            'promo_code'      => 'String',
            'delivery_date'   => '2015-01-01',
        ];

        $this->assertEquals($expected, $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    public function testGetOrderDetailsWithPromoCodeFailure() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue a promo code failure
        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'Invalid Promo Code',
        ];
        $this->queueResponse($data, 403);

        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = $this->createProvideGetOrderTotalsResponse(30.00, 15.00, 5.00);
        $this->queueResponse($data);

        $expected = [
            'item_amount'     => 3000,
            'shipping_amount' => 1500,
            'tax_amount'      => 500,
            'discount_amount' => 0,
            'total_amount'    => 5000,
            'promo_code'      => '', // Failed promo should result in a second http request, which is indicated by it not being returned
            'delivery_date'   => '2015-01-01',
        ];

        $this->assertEquals($expected, $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    public function testGetOrderDetailsWithDeliveryDateFailureThatUltimatelySucceeds() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue a failure
        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'Validation failed for Delivery: ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDate', // This is really the error
        ];
        $this->queueResponse($data, 403);

        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = $this->createProvideGetOrderTotalsResponse(30.00, 15.00, 5.00);
        $this->queueResponse($data);

        // Modify standard order_for_details fixture to try multiple dates
        $this->order_for_details['delivery_date']        = '2015-01-01';
        $this->order_for_details['latest_delivery_date'] = '2015-01-02';

        $expected = [
            'item_amount'     => 3000,
            'shipping_amount' => 1500,
            'tax_amount'      => 500,
            'discount_amount' => 0,
            'total_amount'    => 5000,
            'promo_code'      => 'String',
            'delivery_date'   => '2015-01-02', // The response should have the second date, due to a failure with first date
        ];

        $this->assertEquals($expected, $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrderDetailsWithDeliveryDateFailureThatUltimatelyFails() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue a failure
        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'Validation failed for Delivery: ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDate', // This is really the error
        ];
        $this->queueResponse($data, 403);
        $this->queueResponse($this->mock_provide_guest_customer_json);
        $this->queueResponse($data, 403);

        // Modify standard order_for_details fixture to try multiple dates (but both should fail)
        $this->order_for_details['delivery_date']        = '2015-01-01';
        $this->order_for_details['latest_delivery_date'] = '2015-01-02';

        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrderDetailsBadJson() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $this->queueResponse(self::BAD_JSON);
        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrderDetailsOddJson() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $this->queueResponse(self::ODD_JSON);
        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrderDetailsFailed() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'FaultType' => 'Unknown',
            'Message'   => 'String',
        ];
        $this->queueResponse($data, 403);
        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     * @expectedExceptionMessage foobar
     */
    public function testGetOrderDetailsFailedNewStyle() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'ValidationErrors',
            'ValidationErrors' => [
                [
                    'ErrorMessage' => 'foobar',
                    'PropertyName' => 'foobar.item[0]',
                ],
                [
                    'ErrorMessage' => 'snafu',
                    'PropertyName' => 'snafu.item[0]',
                ],
            ],
        ];
        $this->queueResponse($data, 403);
        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * We throw a special exception here that Snapi can catch and recover from
     * @expectedException Provide\MissingPhoneNumberException
     */
    public function testGetOrderDetailsThrowsMissingPhoneNumberException() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => "\r\nCustomer Phone cannot be blank.",
        ];
        $this->queueResponse($data, 403);
        $this->client->getOrderDetails(self::CUSTOMER_ID, self::EMAIL, $this->order_for_details, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    public function testCreateOrder() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'OrderId' => self::ORDER_ID,
        ];
        $this->queueResponse($data);
        $this->assertEquals(self::ORDER_ID, $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    public function testCreateOrderWithPromoCodeFailure() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue a promo code failure
        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'Invalid Promo Code',
        ];
        $this->queueResponse($data, 403);

        $this->queueResponse($this->mock_provide_guest_customer_json);

        // Then queue the second successful response
        $data = [
            'OrderId' => self::ORDER_ID,
        ];
        $this->queueResponse($data);
        $this->assertEquals(self::ORDER_ID, $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateOrderBadJson() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $this->queueResponse(self::BAD_JSON);
        $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateOrderOddJson() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $this->queueResponse(self::ODD_JSON);
        $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testCreateOrderFailed() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'FaultType' => 'Unknown',
            'Message'   => 'String',
        ];
        $this->queueResponse($data, 403);
        $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     * @expectedExceptionMessage foobar
     */
    public function testCreateOrderFailedNewStyle() {
        // First queue response from getCustomer
        $this->queueResponse($this->mock_provide_guest_customer_json);

        $data = [
            'FaultType' => 'ValidationFailed',
            'Message'   => 'ValidationErrors',
            'ValidationErrors' => [
                [
                    'ErrorMessage' => 'foobar',
                    'PropertyName' => 'foobar.item[0]',
                ],
                [
                    'ErrorMessage' => 'snafu',
                    'PropertyName' => 'snafu.item[0]',
                ],
            ],
        ];
        $this->queueResponse($data, 403);
        $this->client->createOrder(self::CUSTOMER_ID, self::EMAIL, $this->order_for_creation, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    public function testGetOrders() {
        $data = [
            'OrderHistoryList' => [
                [
                    'Deliveries' => [
                        [
                            'DeliveryDate' => '/Date(1415088000000-0800]/',
                            'GiftMessage' => [
                                'Message'   => 'Message',
                                'Signature' => 'Signature',
                            ],
                            'LineItems' => [
                                [
                                    'Details' => [
                                        'Name'     => 'Product Name',
                                        'ImageUrl' => 'http://example.com/image.jpg',
                                    ],
                                    'ProductId' => self::PRODUCT_ID,
                                    'Quantity'  => 1,
                                ],
                            ],
                            'Recipient' => [
                                'CompanyName' => $this->recipient['company'],
                                'FirstName'   => $this->recipient['firstname'],
                                'LastName'    => $this->recipient['lastname'],
                            ],
                        ],
                    ],
                    'Details' => [
                        'OrderDate' => '/Date(1413915487270-0700]/',
                    ],
                    'OrderId' => self::ORDER_ID,
                ],
            ],
        ];

        $expected = [
            [
                'id' => self::ORDER_ID,
                'date' => '2014-10-21',
                'recipients' => [
                    [
                        'firstname' => $this->recipient['firstname'],
                        'lastname' => $this->recipient['lastname'],
                        'company' => $this->recipient['company'],
                        'gift_message' => "Message\nSignature",
                        'delivery_date' => '2014-11-04',
                        'items' => [
                            [
                                'product_id' => self::PRODUCT_ID,
                                'quantity' => 1,
                                'name' => 'Product Name',
                                'image_url' => 'http://example.com/image.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->queueResponse($data);
        $this->assertEquals($expected, $this->client->getOrders(self::CUSTOMER_ID, 1, 25, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    /**
     * Test response from order with 2 "deliveries" aka recipients, one of whom has two items
     * @return [type] [description]
     */
    public function testGetOrdersComplex() {
        $data = [
            'OrderHistoryList' => [
                [
                    'Deliveries' => [
                        [
                            'DeliveryDate' => '/Date(1415088000000-0800]/',
                            'GiftMessage' => [
                                'Message'   => 'Message',
                                'Signature' => 'Signature',
                            ],
                            'LineItems' => [
                                [
                                    'Details' => [
                                        'Name'     => 'Product Name',
                                        'ImageUrl' => 'http://example.com/image.jpg',
                                    ],
                                    'ProductId' => self::PRODUCT_ID,
                                    'Quantity'  => 1,
                                ],
                            ],
                            'Recipient' => [
                                'CompanyName' => $this->recipient['company'],
                                'FirstName'   => $this->recipient['firstname'],
                                'LastName'    => $this->recipient['lastname'],
                            ],
                        ],
                        [
                            'DeliveryDate' => '/Date(1415088000000-0800]/',
                            'GiftMessage' => [
                                'Message'   => 'Message',
                                'Signature' => 'Signature',
                            ],
                            'LineItems' => [
                                [
                                    'Details' => [
                                        'Name'     => 'Product Name',
                                        'ImageUrl' => 'http://example.com/image.jpg',
                                    ],
                                    'ProductId' => self::PRODUCT_ID,
                                    'Quantity'  => 1,
                                ],
                                [
                                    'Details' => [
                                        'Name'     => 'Product Name',
                                        'ImageUrl' => 'http://example.com/image.jpg',
                                    ],
                                    'ProductId' => self::PRODUCT_ID,
                                    'Quantity'  => 1,
                                ],
                            ],
                            'Recipient' => [
                                'CompanyName' => $this->recipient['company'],
                                'FirstName'   => $this->recipient['firstname'],
                                'LastName'    => $this->recipient['lastname'],
                            ],
                        ],
                    ],
                    'Details' => [
                        'OrderDate' => '/Date(1413915487270-0700]/',
                    ],
                    'OrderId' => self::ORDER_ID,
                ],
            ],
        ];

        $expected = [
            [
                'id' => self::ORDER_ID,
                'date' => '2014-10-21',
                'recipients' => [
                    [
                        'firstname' => $this->recipient['firstname'],
                        'lastname' => $this->recipient['lastname'],
                        'company' => $this->recipient['company'],
                        'gift_message' => "Message\nSignature",
                        'delivery_date' => '2014-11-04',
                        'items' => [
                            [
                                'product_id' => self::PRODUCT_ID,
                                'quantity' => 1,
                                'name' => 'Product Name',
                                'image_url' => 'http://example.com/image.jpg',
                            ],
                        ],
                    ],
                    [
                        'firstname' => $this->recipient['firstname'],
                        'lastname' => $this->recipient['lastname'],
                        'company' => $this->recipient['company'],
                        'gift_message' => "Message\nSignature",
                        'delivery_date' => '2014-11-04',
                        'items' => [
                            [
                                'product_id' => self::PRODUCT_ID,
                                'quantity' => 1,
                                'name' => 'Product Name',
                                'image_url' => 'http://example.com/image.jpg',
                            ],
                            [
                                'product_id' => self::PRODUCT_ID,
                                'quantity' => 1,
                                'name' => 'Product Name',
                                'image_url' => 'http://example.com/image.jpg',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->queueResponse($data);
        $this->assertEquals($expected, $this->client->getOrders(self::CUSTOMER_ID, 1, 25, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    public function testGetOrdersNone() {
        $data = [
            'OrderHistoryList' => [],
        ];

        $expected = [];

        $this->queueResponse($data);
        $this->assertEquals($expected, $this->client->getOrders(self::CUSTOMER_ID, 1, 25, $this->recipient, self::AUTHENTICATION_TOKEN));
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrdersBadJson() {
        $this->queueResponse(self::BAD_JSON);
        $this->client->getOrders(self::CUSTOMER_ID, 1, 25, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

    /**
     * @expectedException Provide\ClientException
     */
    public function testGetOrdersOddJson() {
        $this->queueResponse(self::ODD_JSON);
        $this->client->getOrders(self::CUSTOMER_ID, 1, 25, $this->recipient, self::AUTHENTICATION_TOKEN);
    }

}
