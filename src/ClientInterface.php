<?php
namespace Provide;

interface ClientInterface {

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
    public function validateCustomer(array $customer);

    /**
     * Does customer exist?
     * 
     * @param  string $email
     * 
     * @return bool
     */
    public function doesCustomerExist($email);

    /**
     * Create customer
     * 
     * @param  string $email
     * @param  string $password
     * 
     * @return array|bool Returns array with keys customer_id and authentication_token on success
     */
    public function createCustomer($email, $password);

    /**
     * Login customer
     * 
     * @param  string $email
     * @param  string $password
     * 
     * @return string|bool Returns authentication token required for all future API calls
     */
    public function login($email, $password);

    /**
     * Get customer details by email address
     * 
     * @param  string $id
     * @param  string $authentication_token
     * 
     * @return array|bool Returns associative array of customer info
     */
    public function getCustomerById($id, $authentication_token);

    /**
     * Get customer details by email address
     * 
     * @param  string $email
     * @param  string $authentication_token
     * 
     * @return array|bool Returns associative array of customer info
     */
    public function getCustomerByEmail($email, $authentication_token);

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
    public function updateCustomer($customer_id, array $customer, $authentication_token);

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
    public function getCustomerRecipients($customer_id, $authentication_token);

    /**
     * Send a forgot password email
     * 
     * @param  string $email
     * 
     * @return bool
     */
    public function sendForgotPasswordEmail($email);

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
    public function createPaymentMethod($customer_id, array $payment_method, $authentication_token);

    /**
     * Get available payment methods for customer
     *
     * @param  string $customer_id
     * @param  string $authentication_token
     *
     * @return array Returns a numerical array of associative arrays with the following keys:
     *               payment_id,
     *               token,
     *               cardholder_name,
     *               cc_type,
     *               expiry,
     *               lastfour,
     */
    public function getPaymentMethods($customer_id, $authentication_token);

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
    public function getProductAvailability($product_id, $postalcode = '');

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
    public function getOrderDetails($customer_id, $customer_email, array $order, array $address, $authentication_token);


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
    public function createOrder($customer_id, $customer_email, array $order, array $address, $authentication_token);

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
    public function getOrders($customer_id, $page_number, $page_size, $authentication_token);

}
