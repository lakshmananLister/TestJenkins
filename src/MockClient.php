<?php
namespace Provide;

class MockClient implements ClientInterface {

    /**
     * Mimics Provide's customer/recipient address validation, without actually hitting their API, so we can get some
     * feedback in advance, before actually trying to create an order (and failing)
     * 
     * @param  array  $customer Associative array with the following keys:
     *                          firstname,
     *                          lastname,
     *                          company,
     *                          street1,
     *                          street2,
     *                          city,
     *                          state,
     *                          postalcode,
     *                          countrycode,
     *                          phone,
     * 
     * @return bool If successful, returns true, otherwise, throws an exception on the first failure.
     *
     * @throws ClientException
     */
    public function validateCustomer(array $customer) {
        return true;
    }

    /**
     * Does customer exist?
     * 
     * @param  string $email
     * 
     * @return bool
     */
    public function doesCustomerExist($email) {
        return false;
    }

    /**
     * Create customer
     * 
     * @param  string $email
     * @param  string $password
     * 
     * @return array|bool Returns array with keys customer_id and authentication_token on success
     */
    public function createCustomer($email, $password) {
        return [
            'customer_id'          => 1,
            'authentication_token' => 'abc123',
        ];
    }

    /**
     * Login customer
     * 
     * @param  string $email
     * @param  string $password
     * 
     * @return string|bool Returns authentication token required for all future API calls
     */
    public function login($email, $password) {
        if ($password == 'bad_password') {
            return false;
        } else {
            return 'abc123';
        }
    }

    /**
     * Get customer details by email address
     * 
     * @param  string $email
     * @param  string $authentication_token
     * 
     * @return array|bool Returns associative array of customer info
     */
    public function getCustomerById($id, $authentication_token) {
        return [
            'id'          => $id,
            'email'       => 'test@example.com',
            'street1'     => '800 Market St',
            'street2'     => '',
            'city'        => 'San Francisco',
            'company'     => '',
            'countrycode' => 'US',
            'email_optin' => true,
            'firstname'   => 'Testy',
            'lastname'    => 'Tester',
            'phone1'      => '000-000-0000',
            'phone2'      => '',
            'state'       => 'CA',
            'postalcode'  => '94102',
        ];
    }

    /**
     * Get customer details by email address
     * 
     * @param  string $email
     * @param  string $authentication_token
     * 
     * @return array|bool Returns associative array of customer info
     */
    public function getCustomerByEmail($email, $authentication_token) {
        return [
            'id'          => '1',
            'email'       => $email,
            'street1'     => '800 Market St',
            'street2'     => '',
            'city'        => 'San Francisco',
            'company'     => '',
            'countrycode' => 'US',
            'email_optin' => true,
            'firstname'   => 'Testy',
            'lastname'    => 'Tester',
            'phone1'      => '000-000-0000',
            'phone2'      => '',
            'state'       => 'CA',
            'postalcode'  => '94102',
        ];
    }

    /**
     * Update customer details
     *
     * @param  string $customer_id
     * 
     * @param  array  $customer Associative array with the following keys:
     *                          firstname, (required)
     *                          lastname, (required)
     *                          company,
     *                          street1, (required)
     *                          street2,
     *                          city, (required)
     *                          state,
     *                          postalcode,
     *                          countrycode, (required)
     *                          phone,
     * 
     * @param  string $authentication_token
     * 
     * @return bool
     */
    public function updateCustomer($customer_id, array $customer, $authentication_token) {
        return true;
    }

    /**
     * Get list of recipients stored in Provide's "addressbook" for the customer
     * 
     * @param  string $customer_id
     * @param  string $authentication_token
     * 
     * @return array  Returns a numerical array of associative arrays with the following keys:
     *                recipient_id
     *                firstname
     *                lastname
     *                company
     *                street1
     *                street2
     *                city
     *                state
     *                postalcode
     *                countrycode
     *                phone
     */
    public function getCustomerRecipients($customer_id, $authentication_token) {
        return [];
    }

    /**
     * Send a forgot password email
     * 
     * @param  string $email
     * 
     * @return bool
     */
    public function sendForgotPasswordEmail($email) {
        return true;
    }

    /**
     * Create payment method for customer
     * 
     * @param  string $customer_id
     * @param  array  $payment_method Associative array with the following keys:
     *                                card_number,
     *                                expiration_month,
     *                                expiration_year,
     *                                security_code,
     * 
     * @return string Payment method token
     */
    public function createPaymentMethod($customer_id, array $payment_method, $authentication_token) {
        return 1;
    }

    /**
     * Get available payment methods for customer
     *
     * @param string $customer_id
     * @param string $authentication_token
     *
     * @return array Returns a numerical array of associative arrays with the following keys:
     *               payment_id,
     *               token,
     *               cardholder_name,
     *               cc_type,
     *               expiry,
     *               lastfour,
     */
    public function getPaymentMethods($customer_id, $authentication_token) {
        return [
            [
                'payment_id'      => 1,
                'token'           => '2020202',
                'cardholder_name' => 'Testy Tester',
                'cc_type'         => 'Visa',
                'expiry'          => '122020',
                'lastfour'        => '1234',
            ],
        ];
    }

    /**
     * Get list of availability dates for a given product and a given postalcode
     * 
     * @param  string $product_id
     * @param  string $postalcode Optional
     * 
     * @return array Numeric array of strings representing dates with format yyyy-mm-dd
     *
     * @throws ClientException
     */
    public function getProductAvailability($product_id, $postalcode = '') {
        return [
            date('Y-m-d'),
        ];
    }

    /**
     * Get order details (before submitting order)
     * 
     * @param  string $customer_id
     * 
     * @param  string $customer_email
     * 
     * @param  array  $order                Associative array with the following keys:
     *                                      delivery_date, // yyyy-mm-dd
     *                                      gift_message,
     *                                      product_id,    // provide's product id
     *                                      promo_code,    // optional
     * 
     * @param  array  $address              Associative array with the following keys:
     *                                      street1,
     *                                      street2,
     *                                      city,
     *                                      company,
     *                                      countrycode,
     *                                      email,
     *                                      firstname,
     *                                      lastname,
     *                                      location_type, (must be one of: Residential,Business,Hospital,FuneralHome,Apartment,Dormitory,Other,POBox)
     *                                      phone,
     *                                      state,
     *                                      postalcode,
     *
     * @param  string $authentication_token
     * 
     * @return array|bool An associative array with the following keys:
     *                    item_amount,
     *                    shipping_amount,
     *                    tax_amount,
     *                    discount_amount,
     *                    total_amount,
     *                    promo_code_success, (optional, only if promo_code was included in $order array param)
     */
    public function getOrderDetails($customer_id, $customer_email, array $order, array $address, $authentication_token) {
        $return = [
            'item_amount'     => 1999,
            'shipping_amount' => 499,
            'tax_amount'      => 299,
            'discount_amount' => 199,
            'total_amount'    => 2598,
        ];

        if (isset($order['promo_code']) && $order['promo_code']) {
            $return['promo_code_success'] = true;
        }

        return $return;
    }

    /**
     * Create order
     * 
     * @param  string $customer_id
     * 
     * @param  string $customer_email
     * 
     * @param  array  $order                Associative array with the following keys:
     *                                      delivery_date, // yyyy-mm-dd
     *                                      gift_message,
     *                                      product_id,    // provide's product id
     *                                      promo_code,    // optional
     *                                      payment_id,    // provide's payment
     *                                      po_number,     // sincerely's order id, for tracking purposes
     * 
     * @param  array  $address              Associative array with the following keys:
     *                                      street1,
     *                                      street2,
     *                                      city,
     *                                      company,
     *                                      countrycode,
     *                                      email,
     *                                      firstname,
     *                                      lastname,
     *                                      location_type, (must be one of: Residential,Business,Hospital,FuneralHome,Apartment,Dormitory,Other,POBox)
     *                                      phone,
     *                                      state,
     *                                      postalcode,
     *
     * @param  string $authentication_token
     * 
     * @return string|bool order_id
     */
    public function createOrder($customer_id, $customer_email, array $order, array $address, $authentication_token) {
        return 123456789;
    }

    /**
     * Get list of a customer's orders
     * 
     * @param  string $customer_id
     * @param  int    $page_number
     * @param  int    $page_size
     * @param  string $authentication_token
     * 
     * @return array Numeric array of associative arrays with the following keys:
     */
    public function getOrders($customer_id, $page_number, $page_size, $authentication_token) {
        return array();
    }
}
