<?php

class MockLogger extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []) {
        error_log('[' . $level . '] ' . $message);
    }
}

class IntegrationTest extends PHPUnit_Framework_TestCase {
   
    const PROVIDE_API_PRD_URL = 'https://apiservice.providecommerce.com/';
    const PROVIDE_API_DEV_URL = 'https://lm79apiservice.providecommerce.com/';
    
    // The following product ids should have unlimited inventory
    // PFC – 30055275, 30178569, 5543, 41090, 6792
    // SB – 30008882, 30060521, 9724, 30005053, 30005179

    // PF token and product id
    const APPLICATION_TOKEN   = 'og33hpmgec2em4gaeumt2st4';
    const PROVIDE_PRODUCT_ID  = '30235061';

    // SB token and product id
    //const APPLICATION_TOKEN   = 'ygsqka2c0sbueqkz1venhefc';
    //const PROVIDE_PRODUCT_ID  = '30008882';

    const DEFAULT_POSTALCODE  = '94102';

    private $client;
    private $history = [];

    // Guest credentials are generated for each and every test in setUp()
    private $email;
    private $password;

    // Active account credentials come from Provide---this is actually Justin's "scrubbed" account
    private $active_email       = '47339782_@engmail01.proflowers.com';
    private $active_password    = '47339782';
    private $active_customer_id = 100034427959;

    private $recipient_address = [
        'firstname'     => 'Sincerely',
        'lastname'      => 'Office',
        'company'       => 'Sincerely',
        'street1'       => '800 Market St 6th Fl',
        'street2'       => '',
        'city'          => 'San Francisco',
        'state'         => 'CA',
        'postalcode'    => '94102',
        'countrycode'   => 'US',
        'phone'         => '',
        'location_type' => 'Residential',
    ];

    private $customer_details1 = [
        'firstname'   => 'Foo',
        'lastname'    => 'Bar',
        'company'     => 'Foobar Industries',
        'street1'     => '123 Foobar St',
        'street2'     => '',
        'city'        => 'San Franfoobar',
        'state'       => 'CA',
        'postalcode'  => '94102',
        'countrycode' => 'US',
        'phone'       => '4155551212',
    ];

    private $customer_details2 = [
        'firstname'   => 'Boo',
        'lastname'    => 'Far',
        'company'     => 'Boofar Industries',
        'street1'     => '567 BooFar St',
        'street2'     => '',
        'city'        => 'San Franboofar',
        'state'       => 'NY',
        'postalcode'  => '12603',
        'countrycode' => 'US',
        'phone'       => '9195551212',
    ];

    private $payment_method = [
        'card_number'   => '4111111111111111',
        'expiry'        => '0120', // We're good until 2020
        'security_code' => 123,
    ];


    public function setUp() {
        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push(\GuzzleHttp\Middleware::history($this->history));

        $this->client   = new \Provide\Client(new \GuzzleHttp\Client(['handler' => $handler]), new MockLogger, self::APPLICATION_TOKEN, self::PROVIDE_API_DEV_URL, false);
        $this->email    = 'test' . time() . '@example.com';
        $this->password = rand(100000, 999999);
    }

    private function debugHttp() {
        foreach ($this->history as $transaction) {

            print "---\n\n";

            print $transaction['request']->getMethod() . ' ' . $transaction['request']->getUri()  . "\n\n";

            if ($transaction['request']->getMethod() != 'GET') {
                print $transaction['request']->getBody()  . "\n\n";
            }

            print $transaction['response']->getBody()  . "\n\n";
        }
    }

    private function createCustomer() {
        $response = $this->client->createCustomer($this->email, $this->password);
        return [
            $response['customer_id'],
            $response['authentication_token'],
        ];
    }

    private function createActiveCustomer() {
        return [
            $this->active_customer_id,
            $this->client->login($this->active_email, $this->active_password),
        ];
    }

    public function testDoesCustomerExist() {
        $this->assertFalse($this->client->doesCustomerExist($this->email));
        $this->assertTrue($this->client->doesCustomerExist($this->active_email));
    }

    public function testCreateCustomer() {
        $response = $this->client->createCustomer($this->email, $this->password);

        $this->assertTrue(is_array($response));
        $this->assertArrayHasKey('customer_id', $response);
        $this->assertArrayHasKey('authentication_token', $response);
        $this->assertTrue((bool)$response['customer_id']);
        $this->assertTrue((bool)$response['authentication_token']);

        // does_customer_exist() still returns false here because this customer is a guest customer, and the only way to
        // validate them is by receiving an email and following the link in that email to activate the account, which we
        // cannot do in the context of an automated test, unfortunately. 
        $this->assertFalse($this->client->doesCustomerExist($this->email));
    }

    public function testLogin() {
        $this->assertFalse($this->client->login($this->email, $this->password));
        $this->assertTrue((bool)$this->client->login($this->active_email, $this->active_password));
    }

    public function testGetCustomerByIDAndByEmail() {
        list($customer_id, $authentication_token) = $this->createCustomer();

        $expected_keys = [
            'customer_id',
            'firstname',
            'lastname',
            'company',
            'street1',
            'street2',
            'city',
            'state',
            'postalcode',
            'countrycode',
            'phone',
        ];

        $response = $this->client->getCustomerById($customer_id, $authentication_token);
        $this->assertArraySubset($expected_keys, array_keys($response));

        $response = $this->client->getCustomerByEmail($this->email, $authentication_token);
        $this->assertArraySubset($expected_keys, array_keys($response));
    }

    public function testUpdateCustomer() {
        list($customer_id, $authentication_token) = $this->createCustomer();

        $this->assertTrue($this->client->updateCustomer($customer_id, $this->customer_details1, $authentication_token));

        // Now confirm active customer
        list($customer_id, $authentication_token) = $this->createActiveCustomer();
        $this->assertTrue($this->client->updateCustomer($customer_id, $this->customer_details1, $authentication_token));
        $response = $this->client->getCustomerById($customer_id, $authentication_token);

        $this->assertEquals($this->customer_details1['firstname'],   $response['firstname']);
        $this->assertEquals($this->customer_details1['lastname'],    $response['lastname']);
        $this->assertEquals($this->customer_details1['company'],     $response['company']);
        $this->assertEquals($this->customer_details1['street1'],     $response['street1']);
        $this->assertEquals($this->customer_details1['street2'],     $response['street2']);
        $this->assertEquals($this->customer_details1['city'],        $response['city']);
        $this->assertEquals($this->customer_details1['state'],       $response['state']);
        $this->assertEquals($this->customer_details1['postalcode'],  $response['postalcode']);
        $this->assertEquals($this->customer_details1['countrycode'], $response['countrycode']);
        $this->assertEquals($this->customer_details1['phone'],       $response['phone']);

        // Check a second, different change, in case the first change matched the existing state of the active customer
        $this->assertTrue($this->client->updateCustomer($customer_id, $this->customer_details2, $authentication_token));
        $response = $this->client->getCustomerById($customer_id, $authentication_token);

        $this->assertEquals($this->customer_details2['firstname'],   $response['firstname']);
        $this->assertEquals($this->customer_details2['lastname'],    $response['lastname']);
        $this->assertEquals($this->customer_details2['company'],     $response['company']);
        $this->assertEquals($this->customer_details2['street1'],     $response['street1']);
        $this->assertEquals($this->customer_details2['street2'],     $response['street2']);
        $this->assertEquals($this->customer_details2['city'],        $response['city']);
        $this->assertEquals($this->customer_details2['state'],       $response['state']);
        $this->assertEquals($this->customer_details2['postalcode'],  $response['postalcode']);
        $this->assertEquals($this->customer_details2['countrycode'], $response['countrycode']);
        $this->assertEquals($this->customer_details2['phone'],       $response['phone']);
    }

    public function testGetCustomerRecipients() {
        list($customer_id, $authentication_token) = $this->createCustomer();

        // Confirm with guest customer
        $response = $this->client->getCustomerRecipients($customer_id, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertEquals(0, count($response));


        // Confirm with active customer
        list($customer_id, $authentication_token) = $this->createActiveCustomer();
        $response = $this->client->getCustomerRecipients($customer_id, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertGreaterThan(0, count($response));

        $this->assertArrayHasKey('recipient_id', $response[0]);
        $this->assertArrayHasKey('firstname',    $response[0]);
        $this->assertArrayHasKey('lastname',     $response[0]);
        $this->assertArrayHasKey('company',      $response[0]);
        $this->assertArrayHasKey('street1',      $response[0]);
        $this->assertArrayHasKey('street2',      $response[0]);
        $this->assertArrayHasKey('city',         $response[0]);
        $this->assertArrayHasKey('state',        $response[0]);
        $this->assertArrayHasKey('postalcode',   $response[0]);
        $this->assertArrayHasKey('countrycode',  $response[0]);
        $this->assertArrayHasKey('phone',        $response[0]);
    }

    public function testSendForgotPasswordEmail() {
        list($customer_id, $authentication_token) = $this->createCustomer();
        $this->assertTrue($this->client->sendForgotPasswordEmail($this->email));
    }

    public function testCreatePaymentMethod() {
        // Confirm with guest customer
        list($customer_id, $authentication_token) = $this->createCustomer();
        $response = $this->client->createPaymentMethod($customer_id, $this->payment_method, $authentication_token);
        $this->assertTrue((bool)$response);
    }

    public function testGetPaymentMethods() {
        // Confirm with guest customer
        list($customer_id, $authentication_token) = $this->createCustomer();
        $response = $this->client->getPaymentMethods($customer_id, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertEquals(0, count($response));

        // Confirm with active customer (first ensure we have a payment method)
        list($customer_id, $authentication_token) = $this->createActiveCustomer();
        $response = $this->client->createPaymentMethod($customer_id, $this->payment_method, $authentication_token);
        $this->assertTrue((bool)$response);

        $response = $this->client->getPaymentMethods($customer_id, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertGreaterThan(0, count($response));

        $this->assertArrayHasKey('payment_id',      $response[0]);
        $this->assertArrayHasKey('token',           $response[0]);
        $this->assertArrayHasKey('cardholder_name', $response[0]);
        $this->assertArrayHasKey('cc_type',         $response[0]);
        $this->assertArrayHasKey('expiry',          $response[0]);
        $this->assertArrayHasKey('lastfour',        $response[0]);
    }

    public function testGetProductAvailability() {
        $response = $this->client->getProductAvailability(self::PROVIDE_PRODUCT_ID, self::DEFAULT_POSTALCODE);
        $this->assertTrue(is_array($response));
        $this->assertGreaterThan(0, count($response));
    }

    public function testGetOrderDetails() {
        list($customer_id, $authentication_token) = $this->createCustomer();

        $delivery_dates = $this->client->getProductAvailability(self::PROVIDE_PRODUCT_ID, self::DEFAULT_POSTALCODE);
        $delivery_date = end($delivery_dates); // Choose the last available delivery date

        $order = [
            'delivery_date'        => $delivery_date,
            'latest_delivery_date' => $delivery_date,
            'gift_message'         => 'Foobar Snafu Radar Laser',
            'product_id'           => self::PROVIDE_PRODUCT_ID,
            'promo_code'           => '',
        ];


        $response = $this->client->getOrderDetails($customer_id, $this->email, $order, $this->recipient_address, $authentication_token);

        $expected_keys = [
            'item_amount',
            'shipping_amount',
            'tax_amount',
            'discount_amount',
            'total_amount',
            'promo_code',
            'delivery_date',
        ];

        $this->assertArraySubset($expected_keys, array_keys($response));
    }

    public function testCreateOrder() {
        list($customer_id, $authentication_token) = $this->createCustomer();

        $payment_token = $this->client->createPaymentMethod($customer_id, $this->payment_method, $authentication_token);
        $delivery_dates = $this->client->getProductAvailability(self::PROVIDE_PRODUCT_ID, self::DEFAULT_POSTALCODE);
        $delivery_date = end($delivery_dates); // Choose the last available delivery date

        $order = [
            'delivery_date'        => $delivery_date,
            'gift_message'         => 'Foobar Snafu Radar Laser',
            'product_id'           => self::PROVIDE_PRODUCT_ID,
            'promo_code'           => '',
            'payment_token'        => $payment_token,
            'po_number'            => date('YmdHis') . rand(100000, 999999),
        ];

        $response = $this->client->createOrder($customer_id, $this->email, $order, $this->recipient_address, $authentication_token);

        $this->assertTrue((bool)$response);
    }

    public function testGetOrders() {
        // Confirm with guest customer
        list($customer_id, $authentication_token) = $this->createCustomer();
        $response = $this->client->getOrders($customer_id, 1, 25, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertEquals(0, count($response));

        // Confirm with active customer
        list($customer_id, $authentication_token) = $this->createActiveCustomer();
        $response = $this->client->getOrders($customer_id, 1, 25, $authentication_token);
        $this->assertTrue(is_array($response));
        $this->assertGreaterThan(0, count($response));

        $this->assertArrayHasKey('id',         $response[0]);
        $this->assertArrayHasKey('date',       $response[0]);
        $this->assertArrayHasKey('recipients', $response[0]);

        $recipients = $response[0]['recipients'];
        $this->assertTrue(is_array($recipients));
        $this->assertGreaterThan(0, count($recipients));

        $this->assertArrayHasKey('firstname',     $recipients[0]);
        $this->assertArrayHasKey('lastname',      $recipients[0]);
        $this->assertArrayHasKey('company',       $recipients[0]);
        $this->assertArrayHasKey('gift_message',  $recipients[0]);
        $this->assertArrayHasKey('delivery_date', $recipients[0]);
        $this->assertArrayHasKey('items',         $recipients[0]);

        $items = $recipients[0]['items'];
        $this->assertTrue(is_array($items));
        $this->assertGreaterThan(0, count($items));

        $this->assertArrayHasKey('product_id', $items[0]);
        $this->assertArrayHasKey('quantity',   $items[0]);
        $this->assertArrayHasKey('name',       $items[0]);
        $this->assertArrayHasKey('image_url',  $items[0]);
    }

    /*
    
    // Useful for debugging gift message formatting
    public function testGiftMessageFormat() {
        list($customer_id, $authentication_token) = $this->createActiveCustomer();

        $payment_token = $this->client->createPaymentMethod($customer_id, $this->payment_method, $authentication_token);
        $delivery_dates = $this->client->getProductAvailability(self::PROVIDE_PRODUCT_ID, self::DEFAULT_POSTALCODE);
        $delivery_date = end($delivery_dates); // Choose the last available delivery date

        $order = [
            'delivery_date'        => $delivery_date,
            'gift_message'         => str_repeat(str_repeat("x", 40), 3) ."x",
            'product_id'           => self::PROVIDE_PRODUCT_ID,
            'promo_code'           => '',
            'payment_token'        => $payment_token,
            'po_number'            => '123456789',
        ];

        $order_id = $this->client->createOrder($customer_id, $this->email, $order, $this->recipient_address, $authentication_token);

        $orders = $this->client->getOrders($customer_id, 1, 25, $authentication_token);

        foreach ($orders as $order) {
            if ($order['id'] == $order_id) {
                print $order_id . "\n" . $order['recipients'][0]['gift_message'];
            }
        }
    }
    */
}
