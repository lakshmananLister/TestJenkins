<?php
require __DIR__ . '/../vendor/autoload.php';

class MockLogger extends \Psr\Log\AbstractLogger {
    public function log($level, $message, array $context = []) {
        print '[' . $level . '] ' . $message . "\n";
    }
}

$sharisberries_app_token = 'ygsqka2c0sbueqkz1venhefc';
$proflowers_app_token    = 'og33hpmgec2em4gaeumt2st4';

$client = new \Provide\Client(new \GuzzleHttp\Client, new MockLogger, $proflowers_app_token);
$scenario_function = $argv[1];
$scenario_function($argv, $client, 'output');

function prompt($message = 'prompt: ', $hidden = false) {
    echo $message;

    if ($hidden) {
        system('stty -echo');
    }

    $value = rtrim(fgets(STDIN), PHP_EOL);
    
    if ($hidden) {
        system('stty echo');
        echo PHP_EOL;
    }

    return $value;
}

function output($response) {
    print json_encode($response, JSON_PRETTY_PRINT) . "\n";
}

function noop () {}

function doesCustomerExist($argv, $client, $output = 'noop') {
    $email = $argv[2];
    $output($client->doesCustomerExist($email));
}

function login($argv, $client, $output = 'noop') {
    $email = $argv[2];
    $password = prompt('password: ', true);

    if ($auth_token = $client->login($email, $password)) {
        $output($auth_token);
    } else {
        exit("login failed\n");
    }

    if ($customer = $client->getCustomerByEmail($email, $auth_token)) {
        $output($customer);
    } else {
        exit("getCustomerByEmail failed\n");
    }

    return [$customer['customer_id'], $auth_token];
}

/**
 * @param  [type] $argv   [2] = email
 *                        [3] = page_number, one-indexed, defaults to 1
 *                        [4] = page_size, defaults to 10
 * @param  Provide\Client $client
 * @param  callable       $output
 * 
 * @return 
 */
function getOrders($argv, $client, $output = 'noop') {
    list ($customer_id, $auth_token) = login($argv, $client);

    $page_number = isset($argv[3]) ? $argv[3] : 1;
    $page_size   = isset($argv[4]) ? $argv[4] : 25;
    
    $output($client->getOrders($customer_id, $page_number, $page_size, $auth_token));
}

// test_create_customer
// =============================================================================
//var_dump($client->createCustomer($email, 'testing123'));

/*


$customer_id = $customer['customer_id'];
unset($customer['customer_id']);

print "---------------------------------------------------------------------\n";
print "test updateCustomer()\n";
$new_customer = [
    'firstname'   => 'Sincerely',
    'lastname'    => 'Customer',
    'company'     => '',
    'street1'     => '800 Market St',
    'street2'     => '6th Fl',
    'city'        => 'San Francisco',
    'state'       => 'CA',
    'postalcode'  => '94102',
    'countrycode' => 'US',
    'phone'       => '',
];

try {
    if ($client->updateCustomer($customer_id, $new_customer, $auth_token)) {
        print "passed (1)\n";
        print json_encode($client->getCustomerByEmail($email, $auth_token)) . "\n";
    } else {
        exit("failed\n");
    }
 
    if ($client->updateCustomer($customer_id, $customer, $auth_token)) {
        print "passed (2)\n";
        print json_encode($client->getCustomerByEmail($email, $auth_token)) . "\n";
    } else {
        exit("failed\n");
    }

} catch (Provide\ClientException $e) {
    exit($e->getMessage() . "\n");
}


print "---------------------------------------------------------------------\n";
print "test getCustomerRecipients()\n";
try {
    print json_encode($client->getCustomerRecipients($customer_id, $auth_token)) . "\n";
} catch (Provide\ClientException $e) {
    exit($e->getMessage() . "\n");
}


// test_send_forgot_password_email
// =============================================================================
//var_dump($client->sendForgotPasswordEmail('justin@sincerely.com'));


// test_update_password
// =============================================================================
//$client->updateCustomerPassword($customer_id, 'foobar', $auth_token);

// test_create_payment_method() {
$payment_method = [
    'card_number'   => '4012888888881881',
    'expiry'        => '1214',
    'security_code' => '123',
];

try {
    //$client->createPaymentMethod($customer_id, $payment_method, $auth_token);
} catch (Provide\ClientException $e) {
    //print $e->getMessage();
}

// test_get_payment_methods
// =============================================================================
//print_r($client->getPaymentMethods($customer_id, $auth_token));


// test_delete_payment_method
// =============================================================================
//$client->deletePaymentMethod('1179154', $auth_token);
//print_r($client->get_payment_methods($customer_id, $auth_token));

*/
// test_get_product_availability
// =============================================================================

/**
 * @param  [type] $argv   [2] = email
 *                        [3] = product id
 *                        [4] = postalcode, defaults to 94102
 * @param  Provide\Client $client
 * @param  callable       $output
 * 
 * @return 
 */
function getProductAvailability($argv, $client, $output = 'noop') {
    $product_id = $argv[3];
    $postalcode = isset($argv[4]) ? $argv[4] : 94102;
    $output($client->getProductAvailability($product_id, $postalcode));
}

/*
// test_get_order_details
// =============================================================================
$order = [
    'delivery_date'  => '2014-04-17',
    'gift_message'   => 'Yello!',
    'gift_signature' => '',
    'product_id'     => '30132802',
    'promo_code'     => '',
];

$address = [
    'street1'       => '18 Abbey St',
    'street2'       => '',
    'city'          => 'San Francisco',
    'company'       => 'Sincerely',
    'countrycode'   => 'US',
    'email'         => 'justin@sincerely.com',
    'firstname'     => 'Justin',
    'lastname'      => 'Watt',
    'location_type' => 'Residential',
    'phone'         => '4158667123',
    'state'         => 'CA',
    'postalcode'    => '94114',
];

try {
    //print_r($client->getOrderDetails($customer_id, $order, $address, $auth_token));
} catch (Provide\ClientException $e) {
    //print $e->getMessage();
}


// test_create_order
// =============================================================================
$order = [
    'delivery_date'  => '2014-04-17',
    'gift_message'   => 'Yello!',
    'gift_signature' => '',
    'product_id'     => '30132802',
    'promo_code'     => '',
    'po_number'      => rand(1,10000000),
    'payment_id'     => $payment_id,
];

$address = [
    'street1'       => '18 Abbey St',
    'street2'       => '',
    'city'          => 'San Francisco',
    'company'       => 'Sincerely',
    'countrycode'   => 'US',
    'email'         => 'justin@sincerely.com',
    'firstname'     => 'Justin',
    'lastname'      => 'Watt',
    'location_type' => 'Residential',
    'phone'         => '4158667123',
    'state'         => 'CA',
    'postalcode'    => '94114',
];

try {
    //print_r($client->createOrder($customer_id, $order, $address, $auth_token));
} catch (Provide\ClientException $e) {
    //print $e->getMessage();
}
*/
