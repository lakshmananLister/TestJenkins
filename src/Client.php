<?php
namespace Provide;

class Client implements ClientInterface {

    private $http_client;
    private $logger;
    private $base_uri;
    private $application_token;
    private $verify_ssl;
    private $gift_message_line_length = 44; // 44 characters is the default for ProFlowers

    /**
     * PF allows 8 lines of text which is enough to support "virtual" newlines--created by padding the gift message with
     * spaces to trigger Provide to break the lines after the correct amount of characters allowed per line.
     *
     * SB allows only 3 lines of text, so we set this value to false in the constructor, which replaces all whitespace
     * characters, including newlines, with a single space, and trims the result. This should ensure that messages stand
     * a chance at getting through while still being readable---and closer to what the sender intended.
     * 
     * @var boolean
     */
    private $supports_virtual_newlines = true;

    private static $default_headers = [
        'Content-Type' => 'application/json',
    ];

    const DEFAULT_BASE_URI      = 'https://apiservice.providecommerce.com/'; // Eventually add "API/" and remove from below per https://github.com/guzzle/guzzle/issues/1132
    const DEFAULT_FIRSTNAME     = 'Sincerely';
    const DEFAULT_LASTNAME      = 'Customer';
    const DEFAULT_STREET1       = '4840 Eastgate Mall';
    const DEFAULT_STREET2       = '';
    const DEFAULT_CITY          = 'San Diego';
    const DEFAULT_STATE         = 'CA';
    const DEFAULT_POSTALCODE    = '92121';
    const DEFAULT_COUNTRYCODE   = 'US';
    const DEFAULT_PHONE_NUMBER  = '4153602333'; // Sincerely's dead letter phone number, no dashes!
    const DEFAULT_ERROR_MESSAGE = 'We encountered an error.';
    const DEFAULT_NAME          = 'X';
    const LOG_EXCEPTIONS_AS     = 'debug';

    const API_CUSTOMER_CREATE          = '/API/Customer/v1/JSON/CreateCustomer';
    const API_CUSTOMER_EXISTS          = '/API/Customer/v1/JSON/DoesCustomerExistByEmail';
    const API_CUSTOMER_LOGIN           = '/API/Customer/v1/JSON/GetAuthenticationToken';
    const API_CUSTOMER_GET_BY_ID       = '/API/Customer/v1/JSON/GetCustomer';
    const API_CUSTOMER_GET_BY_EMAIL    = '/API/Customer/v1/JSON/GetCustomerByEmail';
    const API_CUSTOMER_UPDATE          = '/API/Customer/v1/JSON/UpdateCustomer';
    const API_CUSTOMER_GET_RECIPIENTS  = '/API/Customer/v1/JSON/GetCustomerRecipients';
    const API_CUSTOMER_FORGOT_PASSWORD = '/API/Customer/v1/JSON/SendForgottenPasswordEmail';
    const API_PAYMENT_METHOD_CREATE    = '/API/Payment/v1/JSON/CreatePaymentMethod';
    const API_PAYMENT_METHOD_GET       = '/API/Payment/v1/JSON/GetPaymentMethods';
    const API_PRODUCT_GET_AVAILABILITY = '/API/Product/v1/JSON/GetProductAvailability';
    const API_ORDER_VERIFY             = '/API/Order/v1/JSON/UpdateOrderTotals';
    const API_ORDER_CREATE             = '/API/Order/v1/JSON/CreateOrder';
    const API_ORDER_GET                = '/API/Order/v1/JSON/GetOrders';

    /**
     * Constructor
     *
     * @param \GuzzleHttp\Client       $http_client
     * @param \Psr\Log\LoggerInterface $logger
     * @param string                   $application_token
     * @param string                   $base_uri          Optional, if not specified defaults to self::DEFAULT_BASE_URI
     * @param boolean                  $verify_ssl        Optional, if not specified defaults to true
     */
    public function __construct(\GuzzleHttp\Client $http_client, \Psr\Log\LoggerInterface $logger, $application_token, $base_uri = '', $verify_ssl = true) {
        $this->http_client       = $http_client;
        $this->logger            = $logger;
        $this->application_token = $application_token;
        $this->base_uri          = $base_uri ? $base_uri : self::DEFAULT_BASE_URI;
        $this->verify_ssl        = $verify_ssl;

        // If the app is Shari's Berries/Gifts, set the line length to 40
        if ($application_token == 'ygsqka2c0sbueqkz1venhefc' || $application_token == 'qnmouwequkosai05c2xemh2a') {
            $this->gift_message_line_length = 40;
            $this->supports_virtual_newlines = false;
        }
    }

    /**
     * Send an HTTP request to the Provide API and return the JSON response
     *
     * @param  string        $method    GET, POST, PUT
     * @param  string        $uri
     * @param  array         $params    Passed as query string for GET, json_encoded body for POST/PUT, with the
     *                                  exception of the applicationToken which is removed from the body
     *                                  and passed as a query string parameter (as required by the Provide API)
     * @param  callable|null $is_valid  Optional callable used to validate the json, function expects a
     *                                  single parameter, an array of the decoded json response, and should return
     *                                  a boolean based on the json's validity, e.g.
     *
     *                                      function($json) {
     *                                          return isset($json['CustomerExists']);
     *                                      }
     *
     * @return array                    Array representation of the JSON response
     *
     * @throws \GuzzleHttp\Exception\BadResponseException
     */
    private function httpRequest($method, $uri, array $params = [], callable $is_valid = null) {
        $options = [
            'base_uri' => $this->base_uri,
            'headers'  => static::$default_headers,
            'verify'   => $this->verify_ssl,
        ];

        switch ($method) {
            case 'GET':
                $params['applicationToken'] = $this->application_token;
                $options['query'] = $params;
                break;

            case 'POST':
            case 'PUT':
                $options['query'] = ['applicationToken' => $this->application_token];

                // For POST and PUT requests, Provide only looks for the authenticationToken in the query string, not the body
                if (isset($params['authenticationToken'])) {
                    $options['query']['authenticationToken'] = $params['authenticationToken'];
                    unset($params['authenticationToken']);
                }

                $options['body'] = json_encode($params);
                break;
        }

        $request = new \GuzzleHttp\Psr7\Request($method, $uri);
        $response = $this->http_client->send($request, $options);

        $calling_function = debug_backtrace()[1]['function'];

        $json = $this->handleResponseJson($calling_function, $request, $response);

        if ($is_valid && !$is_valid($json)) {
            $this->handleUnexpectedResponse($calling_function, $request, $response);
        }

        return $json;
    }

    /**
     * Run a custom function on every element of an array, stops at the first element that fails the check and returns
     * false, otherwise, returns true
     *
     * @param  array    $array
     * @param  callable $is_valid Function expecting two parameters, the key and value, and should return a bool, e.g.
     *
     *                      function($key, $value) {
     *                          return is_array($value);
     *                      }
     *
     * @return bool
     */
    private function arrayElementCheck(array $array, callable $is_valid) {
        foreach ($array as $key => $value) {
            if (!$is_valid($key, $value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Correct user-entered zipcode, if possible
     *
     * Currently, Provide does not accept zip+4
     *
     * @param  string $zip_code
     *
     * @return string
     */
    private function fixZipCode($zip_code) {
        $zip_code = preg_replace('/[^0-9]/', '', $zip_code);
        $zip_code = substr($zip_code, 0, 5);
        if (strlen($zip_code) != 5) {
            $zip_code = '';
        }
        return $zip_code;
    }

    /**
     * Correct user-entered phone number, if possible
     *
     * Currently, only accepts 10 digit US phone numbers. Leading 1s or 0s are stripped.
     * Unsatisfactory phone numbers are replaced with the DEFAULT_PHONE_NUMBER
     *
     * @param  string $phone
     *
     * @return string
     */
    private function fixPhone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Removing leading 0 or 1, not allowed
        if (isset($phone[0]) && ($phone[0] === '0' || $phone[0] === '1')) {
            $phone = substr($phone, 1);
        }

        if (strlen($phone) != 10) {
            $phone = self::DEFAULT_PHONE_NUMBER;
        }
        return $phone;
    }

    /**
     * Remove any +-prefixes from the username of an email address
     *
     * Note: this assumes a simplistic email address model with a single at-sign,
     * addresses like "very.unusual.@.unusual.com"@example.com may not work...
     *
     * @param  string $email
     *
     * @return string
     */
    private function fixEmail($email) {
        @list($username, $domain) = explode('@', $email, 2);
        @list($username)          = explode('+', $username, 2);
        return $username . '@' . $domain;
    }

    /**
     * Fix missing names, required for customers and recipients
     *
     * @param  string  $name
     *
     * @return string
     */
    private function fixName($name) {
        if (!$name) {
            return self::DEFAULT_NAME;
        }

        return $name;
    }

    /**
     * For now, just ensure that the company name is only 30 characters long
     *
     * @param  string  $name
     *
     * @return string
     */
    private function fixCompanyName($name) {
        return substr($name, 0, 30);
    }

    /**
     * If customer address fields are missing, fill in with default address.
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
     *                          phone, (technically required, but we default to constant value if param is empty)
     *
     * @return array
     */
    private function fixCustomerAddress(array $customer) {
        if (!$customer['firstname'] && !$customer['lastname']) {
            $customer['firstname'] = self::DEFAULT_FIRSTNAME;
            $customer['lastname']  = self::DEFAULT_LASTNAME;
        }

        if (!$customer['street1'] && !$customer['city']) {
            $customer['street1']     = self::DEFAULT_STREET1;
            $customer['street2']     = self::DEFAULT_STREET2;
            $customer['city']        = self::DEFAULT_CITY;
            $customer['state']       = self::DEFAULT_STATE;
            $customer['postalcode']  = self::DEFAULT_POSTALCODE;
            $customer['countrycode'] = self::DEFAULT_COUNTRYCODE;
        }

        if (!$customer['phone']) {
            $customer['phone'] = self::DEFAULT_PHONE_NUMBER;
        }

        return $customer;
    }

    /**
     * Convert a yyyy-mm-dd style date (assumed to be in the America/Los_Angeles timezone) to a millisecond UTC timestamp
     * in a format expected by Provide: /Date(1390521600000)/
     *
     * @param  string $date_string Format: yyyy-mm-dd
     *
     * @return string
     */
    private function dateStringToJsonDate($date_string) {
        // Add time of midnight if it's missing (as we expect for delivery dates)
        if (strlen($date_string) == 10) {
            $date_string .= ' 00:00:00';
        }

        // Even though the date (for delivery) is within a US time zone, which can be a day behind the UTC date, we
        // interpret this US date as a UTC timestamp, which is what the Provide API is expecting.
        if (!$date_time = \DateTime::createFromFormat('Y-m-d H:i:s', $date_string, new \DateTimeZone('UTC'))) {
            throw new ClientException('Invalid Date');
        }

        $date_string = $date_time->format('U') * 1000; // Multiply by 1000 to add milliseconds

        return '/Date(' . $date_string . ')/';
    }

    /**
     * Convert a millisecond UTC timestamp in the /Date(1390521600000-800)/ format to a yyyy-mm-dd format in the
     * America/Los_Angeles timezone
     *
     * See http://stackoverflow.com/questions/16749778/php-date-format-date1365004652303-0500 for more details.
     *
     * @param  string $json_date Format e.g. /Date(1390521600000-800)/
     *
     * @return string
     */
    private function jsonDateToDateString($json_date) {
        // We just want the first numeric part, and we don't care about any timezone offset
        preg_match('/[0-9]+/', $json_date, $matches);
        $timestamp = (int)round($matches[0] / 1000); // Divide by 1000 to remove milliseconds

        // Even though the timestamp is technically a UTC time, it's encoding a US-based datetime
        if (!$date_time = \DateTime::createFromFormat('U', $timestamp, new \DateTimeZone('UTC'))) {
            throw new ClientException('Invalid Date');
        }

        return $date_time->format('Y-m-d');
    }

    /**
     * Pad the lines of a gift message to ensure they wrap correctly in the printed card.
     * 
     * The formatting of PF/SB gift messages is extremely limited. They expect the message to be a single line of
     * text, without line breaks. They then insert their own line breaks, according to each app's line length restrictions
     * (currently 44 characters for PF, 40 for SB). Additionally any line breaks in the message are silently removed (though
     * they do act like zero-width spaces). Rather than replicate that limited behavior in the app, this method converts
     * normal, multi-line text from the app into Provide-friendly text, with each line padded with spaces to ensure that
     * Provide breaks the lines as the right places.
     *
     * Additionally, we're also replacing common accented characters with their non-accented ASCII equivalents, to ensure
     * that more gift messages get through without being rejected by Provide.
     * 
     * @param  string $message
     * @param  int    $line_length
     * @param  bool   $supports_virtual_newlines
     * 
     * @return string
     */
    public function fixGiftMessage($message, $line_length, $supports_virtual_newlines) {
        // In order to ensure that more gift messages are accepted, we do a basic substituion of accented characters
        // with their ASCII equivalents. We also replace ^|~ with spaces, as those special characters are not allowed.
        $search  = explode(',', 'Ñ,ñ,ç,æ,œ,á,é,í,ó,ú,à,è,ì,ò,ù,ä,ë,ï,ö,ü,ÿ,â,ê,î,ô,û,å,ø,Ø,Å,Á,À,Â,Ä,È,É,Ê,Ë,Í,Î,Ï,Ì,Ò,Ó,Ô,Ö,Ú,Ù,Û,Ü,Ÿ,Ç,Æ,Œ,^,|,~,‘,’,“,”');
        $replace = explode(',', 'N,n,c,ae,oe,a,e,i,o,u,a,e,i,o,u,a,e,i,o,u,y,a,e,i,o,u,a,o,O,A,A,A,A,A,E,E,E,E,I,I,I,I,O,O,O,O,U,U,U,U,Y,C,AE,OE, , , ,\',\',","');
        $message = str_replace($search, $replace, $message);


        if ($supports_virtual_newlines) {
            // Remove any leading newlines (but not spaces, as they may be intentional)
            $message = ltrim($message, "\n");

            // Remove any trailing spaces/newlines
            $message = rtrim($message);

            $output_lines = [];

            foreach (explode("\n", $message) as $message_line) {
                foreach (explode("\n", wordwrap($message_line, $line_length, "\n", true)) as $wrapped_message_line) {
                    $output_lines[] = str_pad($wrapped_message_line, $line_length);
                }
            }

            $message = implode("\n", $output_lines);

        } else {
            // Convert all whitespace characters (space, newlines, etc) into single space
            $message = trim(preg_replace('/\s+/', ' ', $message));
        }

        return $message;
    }

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
        // This regular expression accepts all printable ASCII characters except the following:
        // [ - left square bracket
        // \ - backslash
        // ] - right square bracket
        // ^ - caret
        // | - bar
        // ~ - tilde
        $good_characters = '
            /^
                [
                    \x20-\x5a
                    \x5f-\x7b
                    \x7d
                    \s
                ]+
            $/x
        ';

        // This regular expression accepts all printable ASCII characters except the following:
        // " - double quote
        // # - pound sign
        // $ - dollar sign
        // % - percent sign
        // * - asterisk
        // + - plus sign
        // : - colon
        // ; - semicolon
        // < - less than
        // = - equal sign
        // > - greater than
        // [ - open square bracket
        // \ - backslash
        // ] - close square bracket
        // ^ - caret
        // _ - underscore
        // ` - backtick
        // { - open curly bracket
        // | - bar
        // } - close curly bracket
        // ~ - tilde
        //
        // Conversely, acceptable special characters include:
        // ' ' - space
        // ! - exclamation point
        // & - ampersand
        // ' - single quote
        // ( - open parens
        // ) - close parens
        // , - comma
        // - - dash
        // . - period
        // / - slash
        // ? - question mark
        // @ - at sign
        //
        // Additionally, it imposes that the first character be alphanumeric
        $good_name_characters = '
            /^
                (
                    [a-zA-Z0-9]
                )
                (
                    [
                        \x20-\x21
                        \x26-\x29
                        \x2c-\x39
                        \x3f-\x5a
                        \x61-\x7a
                    ]
                )*
            $/x
        ';

        // FIRST NAME
        if (!$customer['firstname']) {
            throw new ClientException('Please enter a first name.');
        }

        if (strlen($customer['firstname']) > 20) {
            throw new ClientException('The first name is longer than 20 characters.');
        }

        if (!preg_match($good_name_characters, $customer['firstname'])) {
            throw new ClientException('The first name contains invalid characters.');
        }

        // LAST NAME
        if (!$customer['lastname']) {
            throw new ClientException('Please enter a last name.');
        }

        if (strlen($customer['lastname']) > 40) {
            throw new ClientException('The last name is longer than 40 characters.');
        }

        if (!preg_match($good_name_characters, $customer['lastname'])) {
            throw new ClientException('The last name contains invalid characters.');
        }

        // COMPANY
        if ($customer['company'] && !preg_match($good_characters, $customer['company'])) {
            throw new ClientException('The company name contains invalid characters.');
        }

        if (strlen($customer['company']) > 40) {
            throw new ClientException('The company name is longer than 40 characters.');
        }

        // STREET 1
        if (!$customer['street1']) {
            throw new ClientException('Please enter a street address.');
        }

        if (!preg_match($good_characters, $customer['street1'])) {
            throw new ClientException('The first line of the street address contains invalid characters.');
        }

        if (strlen($customer['street1']) > 40) {
            throw new ClientException('The first line of the street address is longer than 40 characters.');
        }

        if (preg_match('/^\s*P\.?\s*O\.?\s*Box/i', $customer['street1'])) {
            throw new ClientException('The address cannot be a PO Box.');
        }

        // STREET 2
        if ($customer['street2'] && !preg_match($good_characters, $customer['street2'])) {
            throw new ClientException('The second line of the street address contains invalid characters.');
        }

        if (strlen($customer['street2']) > 40) {
            throw new ClientException('The second line of the street address is longer than 40 characters.');
        }

        if (preg_match('/^\s*P\.?\s*O\.?\s*Box/i', $customer['street2'])) {
            throw new ClientException('The address cannot be a PO Box.');
        }

        // CITY
        if (!$customer['city']) {
            throw new ClientException('Please enter a city.');
        }

        if (strlen($customer['city']) > 40) {
            throw new ClientException('The city is longer than 40 characters.');
        }

        if (!preg_match($good_characters, $customer['city'])) {
            throw new ClientException('The city contains invalid characters.');
        }

        // COUNTRY, STATE, POSTALCODE
        if (!preg_match('/^[A-Z]{2}$/', $customer['countrycode'])) {
            throw new ClientException('Please select a country.');
        }

        if ($customer['countrycode'] == 'US') {
            if (!$customer['state'] || !preg_match('/^[A-Z]{2}$/', $customer['state'])) {
                throw new ClientException('Please select a state.');
            }

            // Only US zipcodes, without +4 extension are accepted
            $postalcode = substr(preg_replace('/[^0-9]/', '', $customer['postalcode']), 0, 5);
            if (!$postalcode || strlen($postalcode) != 5) {
                throw new ClientException('Please enter a valid US ZIP Code.');
            }
        }

        // PHONE
        // Currently we aren't enforcing the collection of a phone number in the app,
        // and instead supplying the Provide API with default, so there's no point
        // in validating it here

        return true;
    }

    /**
     * Does customer exist?
     *
     * @param  string $email
     *
     * @return bool
     *
     * @throws ClientException
     */
    public function doesCustomerExist($email) {
        try {
            $json = $this->httpRequest(
                'GET',
                self::API_CUSTOMER_EXISTS,
                [
                    'email' => $this->fixEmail($email),
                ],
                function($json) {
                    return isset($json['CustomerExists']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        return (bool)$json['CustomerExists'];
    }

    /**
     * Create customer (and log them in)
     *
     * @param  string $email
     * @param  string $password
     *
     * @return array|bool Returns array with keys customer_id and authentication_token on success
     *
     * @throws ClientException
     */
    public function createCustomer($email, $password) {
        try {
            $json = $this->httpRequest(
                'POST',
                self::API_CUSTOMER_CREATE,
                [
                    'Email'                   => $this->fixEmail($email),
                    'Password'                => $password,
                    'SendUpgradeAccountEmail' => true,
                ],
                function($json) {
                    return isset($json['CustomerId']) && isset($json['AuthenticationContext']['AuthenticationToken']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        return [
            'customer_id'          => $json['CustomerId'],
            'authentication_token' => $json['AuthenticationContext']['AuthenticationToken'],
        ];
    }

    /**
     * Login customer
     *
     * @param  string $email
     * @param  string $password
     *
     * @return string|bool Returns authentication token required for all future API calls, false on login failure
     *
     * @throws ClientException
     */
    public function login($email, $password) {
        try {
            $json = $this->httpRequest(
                'GET',
                self::API_CUSTOMER_LOGIN,
                [
                    'email'    => $this->fixEmail($email),
                    'password' => $password,
                ],
                function($json) {
                    return isset($json['AuthenticationContext']['AuthenticationToken']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {

            $json = json_decode($e->getResponse()->getBody(), true);

            if (is_null($json)) {
                $json = ['FaultType' => ''];
            }

            // Don't log LoginFailed response Exception, it's expected
            if ($json['FaultType'] == 'LoginFailed') {
                return false;
            }

            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        return $json['AuthenticationContext']['AuthenticationToken'];
    }

    /**
     * Get customer details by customer id
     *
     * @param  string $id
     * @param  string $authentication_token
     *
     * @return array      Returns associative array of customer info with the following keys:
     *                    customer_id
     *                    firstname
     *                    lastname
     *                    company
     *                    street1
     *                    street2
     *                    city
     *                    state
     *                    postalcode
     *                    countrycode
     *                    phone
     *
     * @throws ClientException
     */
    public function getCustomerById($id, $authentication_token) {
        $params = [
            'customerId'          => $id,
            'authenticationToken' => $authentication_token,
        ];

        return $this->getCustomer(self::API_CUSTOMER_GET_BY_ID, $params);
    }

    /**
     * Get customer details by email address
     *
     * @param  string $email
     * @param  string $authentication_token
     *
     * @return array      Returns associative array of customer info with the following keys:
     *                    customer_id
     *                    firstname
     *                    lastname
     *                    company
     *                    street1
     *                    street2
     *                    city
     *                    state
     *                    postalcode
     *                    countrycode
     *                    phone
     *
     * @throws ClientException
     */
    public function getCustomerByEmail($email, $authentication_token) {
        $params = [
            'email'               => $this->fixEmail($email),
            'authenticationToken' => $authentication_token,
        ];

        return $this->getCustomer(self::API_CUSTOMER_GET_BY_EMAIL, $params);
    }

    /**
     * Generalize implementation for getCustomerById and getCustomerByEmail
     *
     * @param  string $url    Relative URL resource to call, e.g. 'Customer/v1/JSON/GetCustomer'
     * @param  array  $params Associative array of parameters to pass with get request
     *
     * @return array      Returns associative array of customer info with the following keys:
     *                    customer_id
     *                    firstname
     *                    lastname
     *                    company
     *                    street1
     *                    street2
     *                    city
     *                    state
     *                    postalcode
     *                    countrycode
     *                    phone
     *
     * @throws ClientException
     */
    private function getCustomer($url, array $params) {
        // There may be other keys in the response, but these are the ones we want to ensure exist
        $expected_keys = [
            'FirstName'   => '',
            'LastName'    => '',
            'CompanyName' => '',
            'Address1'    => '',
            'Address2'    => '',
            'City'        => '',
            'State'       => '',
            'Zip'         => '',
            'CountryCode' => '',
            'Phone1'      => '',
        ];

        try {
            $json = $this->httpRequest(
                'GET',
                $url,
                $params,
                function($json) use ($expected_keys) {
                    return
                            isset($json['Customer']) &&
                            isset($json['Customer']['CustomerId']) &&
                            // We use array_key_exists('Details',...) instead of isset() here because the Details array could be null (for guest customers)
                            array_key_exists('Details', $json['Customer']) &&
                            (
                                is_null($json['Customer']['Details']) ||
                                // !array_diff_key means all of the keys in expected_keys exist in $json['Customer']['Details']
                                !array_diff_key($expected_keys, $json['Customer']['Details'])
                            );
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON response:
        // {
        //     "Customer": {
        //         "CustomerId": "String content",
        //         "Details": {
        //             "Address1": "String content",
        //             "Address2": "String content",
        //             "City": "String content",
        //             "CompanyName": "String content",
        //             "CountryCode": "String content",
        //             "EmailOptIn": Bool,
        //             "FirstName": "String content",
        //             "LastName": "String content",
        //             "Phone1": "String content",
        //             "Phone2": "String content",
        //             "State": "String content",
        //             "Zip": "String content"
        //         },
        //         "Email": "String content"
        //     }
        // }

        // If the Details array is null (for guest customers), then we alter the json array to include the expected array keys
        if (is_null($json['Customer']['Details'])) {
            $json['Customer']['Details'] = $expected_keys;
        }

        return [
            'customer_id' => $json['Customer']['CustomerId'],
            'firstname'   => $json['Customer']['Details']['FirstName'],
            'lastname'    => $json['Customer']['Details']['LastName'],
            'company'     => $json['Customer']['Details']['CompanyName'],
            'street1'     => $json['Customer']['Details']['Address1'],
            'street2'     => $json['Customer']['Details']['Address2'],
            'city'        => $json['Customer']['Details']['City'],
            'state'       => $json['Customer']['Details']['State'],
            'postalcode'  => $json['Customer']['Details']['Zip'],
            'countrycode' => $json['Customer']['Details']['CountryCode'],
            'phone'       => $json['Customer']['Details']['Phone1'],
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
     *                          phone, (technically required, but we default to constant value if param is empty)
     *
     * @param  string $authentication_token
     *
     * @return bool
     *
     * @throws ClientException If we get an unexpected ValidationFailed or other error from Provide.
     */
    public function updateCustomer($customer_id, array $customer, $authentication_token) {
        // Populate with default name/address if not set
        $customer = $this->fixCustomerAddress($customer);

        try {
            $json = $this->httpRequest(
                'PUT',
                self::API_CUSTOMER_UPDATE,
                [
                    'authenticationToken' => $authentication_token,
                    'CustomerId'          => (string)$customer_id,
                    'Details'             => [
                        'FirstName'   => (string)$this->fixName($customer['firstname']),
                        'LastName'    => (string)$this->fixName($customer['lastname']),
                        'CompanyName' => (string)$this->fixCompanyName($customer['company']),
                        'Address1'    => (string)$customer['street1'],
                        'Address2'    => (string)$customer['street2'],
                        'City'        => (string)$customer['city'],
                        'State'       => (string)$customer['state'],
                        'Zip'         => (string)$this->fixZipCode($customer['postalcode']),
                        'CountryCode' => (string)$customer['countrycode'],
                        'Phone1'      => (string)$this->fixPhone($customer['phone']),
                    ],
                ]
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

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
     *
     * @throws ClientException
     */
    public function getCustomerRecipients($customer_id, $authentication_token) {
        try {
            $json = $this->httpRequest(
                'GET',
                self::API_CUSTOMER_GET_RECIPIENTS,
                [
                    'customerId'          => (string)$customer_id,
                    'authenticationToken' => $authentication_token,
                ],
                function($json) {
                    return isset($json['Recipients']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON response:
        // {
        //     "Recipients": [
        //         {
        //             "Address1": "String content",
        //             "Address2": "String content",
        //             "BirthDate": "String content",
        //             "City": "String content",
        //             "CompanyName": "String content",
        //             "CountryCode": "String content",
        //             "Email": "String content",
        //             "FirstName": "String content",
        //             "LastName": "String content",
        //             "LocationType": "String content",
        //             "Phone": "String content",
        //             "RecipientId": "String content",
        //             "RelationshipType": "String content",
        //             "State": "String content",
        //             "Zip": "String content",
        //             "RelationshipId": "String content"
        //         }
        //     ]
        // }

        // There may be other keys in the response, but these are the ones we want to ensure exist
        $expected_keys = [
            'RecipientId' => '',
            'FirstName'   => '',
            'LastName'    => '',
            'CompanyName' => '',
            'Address1'    => '',
            'Address2'    => '',
            'City'        => '',
            'State'       => '',
            'Zip'         => '',
            'CountryCode' => '',
            'Phone'       => '',
        ];

        $recipients = [];
        foreach ($json['Recipients'] as $recipient) {
            if ($missing_keys = array_diff_key($expected_keys, $recipient)) {
                continue;
            }

            $recipients[] = [
                'recipient_id' => (string)$recipient['RecipientId'],
                'firstname'    => (string)$recipient['FirstName'],
                'lastname'     => (string)$recipient['LastName'],
                'company'      => (string)$recipient['CompanyName'],
                'street1'      => (string)$recipient['Address1'],
                'street2'      => (string)$recipient['Address2'],
                'city'         => (string)$recipient['City'],
                'state'        => (string)$recipient['State'],
                'postalcode'   => (string)$recipient['Zip'],
                'countrycode'  => $recipient['CountryCode'] ? (string)$recipient['CountryCode'] : 'US', // Compensate for Provide bug returning CountryCode as null
                'phone'        => (string)$recipient['Phone'],
            ];
        }

        return $recipients;
    }

    /**
     * Send a forgot password email
     *
     * @param  string $email
     *
     * @return bool
     *
     * @throws ClientException
     */
    public function sendForgotPasswordEmail($email) {
        try {
            $json = $this->httpRequest(
                'POST',
                self::API_CUSTOMER_FORGOT_PASSWORD,
                [
                    'Email' => $this->fixEmail($email)
                ]
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        return true;
    }

    /**
     * Create payment method for customer
     *
     * @param  string $customer_id
     * @param  array  $payment_method Associative array with the following keys:
     *                                card_number,
     *                                expiry, (format: mmyy)
     *                                security_code,
     * @param $authentication_token
     *
     * @return string Payment method token to use with future calls to createOrder()
     *
     * @throws ClientException If we get an unexpected ValidationFailed or other error from Provide.
     */
    public function createPaymentMethod($customer_id, array $payment_method, $authentication_token) {
        // Kind of a hack to get a default address
        $customer = $this->fixCustomerAddress([
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
        ]);

        try {
            $json = $this->httpRequest(
                'POST',
                self::API_PAYMENT_METHOD_CREATE,
                [
                    'authenticationToken' => $authentication_token,
                    'PaymentMethod'       => [
                        '__type'          => 'NewCreditCard:http://api.providecommerce.com/API/Payment/v1/',
                        'CustomerId'      => (string)$customer_id,
                        'CardNumber'      => (string)$payment_method['card_number'],
                        'ExpirationMonth' => (string)substr($payment_method['expiry'], 0, 2),
                        'ExpirationYear'  => (string)'20' . substr($payment_method['expiry'], 2), // this is good another ~86 years
                        'SecurityCode'    => (string)$payment_method['security_code'], // aka cvv, cvc, ccvc, etc
                        'FirstName'       => $customer['firstname'],
                        'LastName'        => $customer['lastname'],
                        'Address1'        => $customer['street1'],
                        'Address2'        => $customer['street2'],
                        'City'            => $customer['city'],
                        'State'           => $customer['state'],
                        'Zip'             => $customer['postalcode'],
                        'CountryCode'     => $customer['countrycode'],
                        'Phone'           => $customer['phone'],
                    ],
                ],
                function($json) {
                    return isset($json['PaymentMethod']) && is_array($json['PaymentMethod']) && isset($json['PaymentMethod']['Token']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        return $json['PaymentMethod']['Token'];
    }

    /**
     * Get available payment methods for customer
     *
     * @param string $customer_id
     * @param string $authentication_token
     *
     * @return array Returns a numerical array of associative arrays with the following keys:
     *               payment_id, (this is necessary to migrate existing payment_ids stored in Sincerely DB to tokens, eventually it can be removed)
     *               token, (to use with future calls to createOrder())
     *               cardholder_name,
     *               cc_type,
     *               expiry,
     *               lastfour,
     *
     * @throws ClientException
     */
    public function getPaymentMethods($customer_id, $authentication_token) {
        try {
            $json = $this->httpRequest(
                'GET',
                self::API_PAYMENT_METHOD_GET,
                [
                    'customerId'          => $customer_id,
                    'authenticationToken' => $authentication_token,
                ],
                function($json) {
                    return
                        isset($json['PaymentMethods']) &&
                        is_array($json['PaymentMethods']) &&
                        // ensure that all elements of $json['PaymentMethods'] are arrays
                        $this->arrayElementCheck($json['PaymentMethods'], function($key, $value) {return is_array($value);});
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON response:
        //
        // {
        //     "PaymentMethods": [
        //         {
        //             "__type": "SavedCreditCard:http://api.providecommerce.com/API/Payment/v1/",
        //             "PaymentId": "1178288",
        //             "Token": "a5c75f7a60db5145ecb82a3bf54b69c1",
        //             "CardHolderName": "test",
        //             "CardType": "Visa",
        //             "ExpirationMonth": 12,
        //             "ExpirationYear": 2014,
        //             "LastFour": "1234"
        //         }
        //     ]
        // }

        // There may be other keys in the response, but these are the ones we want to ensure exist
        $expected_keys = [
            'PaymentId'       => '',
            'Token'           => '',
            'CardHolderName'  => '',
            'ExpirationMonth' => '',
            'ExpirationYear'  => '',
            'LastFour'        => '',
        ];

        $return = [];
        foreach ($json['PaymentMethods'] as $payment_method) {
            // I'd tend to use handleUnexpectedResponse() here, but I'm unsure as to whether there might be different
            // types of payment methods returned in the future, so instead I'll just continue if it doesn't look like a
            // credit card.
            if ($missing_keys = array_diff_key($expected_keys, $payment_method)) {
                continue;
            }

            // Don't return expired credit cards
            if ($payment_method['ExpirationYear'] < date('Y') || ($payment_method['ExpirationYear'] == date('Y') && $payment_method['ExpirationMonth'] < date('n'))) {
                continue;
            }

            $return[] = [
                'payment_id'      => $payment_method['PaymentId'],
                'token'           => $payment_method['Token'],
                'cardholder_name' => $payment_method['CardHolderName'],
                'cc_type'         => $payment_method['CardType'],
                'expiry'          => str_pad($payment_method['ExpirationMonth'], 2, "0", STR_PAD_LEFT) . substr($payment_method['ExpirationYear'], -2),
                'lastfour'        => $payment_method['LastFour'],
            ];
        }

        return $return;
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
        $params = [
            'productIds' => $product_id, // YES, productIds is plural on purpose, we're just only sending a single product_id
        ];

        if ($postalcode) {
            $params['recipeintZipCode'] = $this->fixZipCode($postalcode); // YES, recipeintZipCode is misspelled on purpose, we've reported it to Provide
        }

        try {
            $json = $this->httpRequest(
                'GET',
                self::API_PRODUCT_GET_AVAILABILITY,
                $params,
                function($json) {
                    return isset($json['ProductAvailabilities']) && is_array($json['ProductAvailabilities']);
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON response:
        //
        // {
        //     "ProductAvailabilities": [
        //         {
        //             "Date": "/Date(1398409200000-0700)/"
        //         },
        //         {
        //             "Date": "/Date(1398495600000-0700)/"
        //         },
        //         {
        //             "Date": "/Date(1398668400000-0700)/"
        //         }
        //     ]
        // }

        $product_availabilities = [];
        foreach ($json['ProductAvailabilities'] as $product_availability) {
            if (!isset($product_availability['Date'])) {
                continue;
            }
            $product_availabilities[] = $this->jsonDateToDateString($product_availability['Date']);
        }

        return $product_availabilities;
    }

    /**
     * Helper function for getOrderDetails() and createOrder() to build the necessary Customer Details array
     * (which is necessary in order to make orders for Provide guest accounts succeed)
     *
     * @param  string $customer_id
     * @param  string $customer_email
     * @param  string $authentication_token
     *
     * @return array
     */
    private function getCustomerJson($customer_id, $customer_email, $authentication_token) {
        $customer = $this->fixCustomerAddress($this->getCustomerById($customer_id, $authentication_token));

        return [
            'CustomerId' => (string)$customer_id,
            'Email' => $this->fixEmail($customer_email),
            'Details' => [
                'FirstName'   => $customer['firstname'],
                'LastName'    => $customer['lastname'],
                'CompanyName' => $customer['company'],
                'Address1'    => $customer['street1'],
                'Address2'    => $customer['street2'],
                'City'        => $customer['city'],
                'State'       => $customer['state'],
                'Zip'         => $customer['postalcode'],
                'CountryCode' => $customer['countrycode'],
                'Phone1'      => $customer['phone'],
            ],
        ];
    }

    /**
     * Get order details (before submitting order)
     *
     * If a "promo_code" is included in the $order array, and we get an error from Provide that looks like the promo
     * code was invalid, then we automatically try the request again and return the results, including in the response a
     * promo_code_success flag set to true/false, respectively.
     *
     * @param  string $customer_id
     *
     * @param  string $customer_email       Due to the intricacies of supporting Provide's guest accounts, we must also
     *                                      pass in the email addresses in order for the request to be valid in their eyes.
     *
     * @param  array  $order                Associative array with the following keys:
     *                                      delivery_date, (yyyy-mm-dd)
     *                                      latest_delivery_date, (yyyy-mm-dd, the latest date to try if the delivery_date is unavailable)
     *                                      gift_message,  (optional, empty string is ok)
     *                                      product_id,    (provide's product id)
     *                                      promo_code,    (optional, empty string is ok)
     *
     * @param  array  $address              Associative array with the following keys:
     *                                      firstname,
     *                                      lastname,
     *                                      company,
     *                                      street1,
     *                                      street2,
     *                                      city,
     *                                      state,
     *                                      postalcode,
     *                                      countrycode,
     *                                      phone, (technically required, but we default to constant value if param is empty)
     *                                      location_type, (must be one of: Residential,Business,Hospital,FuneralHome,Apartment,Dormitory,Other,POBox)
     *
     * @param  string $authentication_token
     *
     * @return array An associative array with the following keys:
     *               item_amount,     (integer value in cents, e.g. $19.95 is returned as 1995)
     *               shipping_amount, (integer value in cents)
     *               tax_amount,      (integer value in cents)
     *               discount_amount, (integer value in cents)
     *               total_amount,    (integer value in cents)
     *               promo_code,      (if promo code failed, this will be blank in the return,
     *                                otherwise it will match the promo code that was passed in)
     *               delivery_date,   (first available date available for delivery, between the
     *                                delivery_date and the latest_delivery_date)
     *
     * @throws ClientException If we get an unexpected ValidationFailed or other error from Provide.
     */
    public function getOrderDetails($customer_id, $customer_email, array $order, array $address, $authentication_token) {
        $params = [
            'authenticationToken' => $authentication_token,
            'Order' => [
                'Customer' => $this->getCustomerJson($customer_id, $customer_email, $authentication_token),
                'Deliveries' => [
                    [
                        'DeliveryDate' => $this->dateStringToJsonDate($order['delivery_date']),
                        'GiftMessage' => [
                            'Message'   => (string)$this->fixGiftMessage($order['gift_message'], $this->gift_message_line_length, $this->supports_virtual_newlines),
                        ],
                        'LineItems' => [
                            [
                                'ProductId' => (string)$order['product_id'],
                            ],
                        ],
                        'Recipient' => [
                            'FirstName'    => (string)$this->fixName($address['firstname']),
                            'LastName'     => (string)$this->fixName($address['lastname']),
                            'CompanyName'  => (string)$this->fixCompanyName($address['company']),
                            'Address1'     => (string)$address['street1'],
                            'Address2'     => (string)$address['street2'],
                            'City'         => (string)$address['city'],
                            'State'        => (string)$address['state'],
                            'Zip'          => (string)$this->fixZipCode($address['postalcode']),
                            'CountryCode'  => (string)$address['countrycode'],
                            'LocationType' => (string)$address['location_type'],
                            'Email'        => (string)'',
                            'Phone'        => (string)$this->fixPhone($address['phone']),
                        ],
                    ],
                ],
            ],
        ];

        // Passing in a promo_code is optional
        if ($order['promo_code']) {
            $params['Order']['PromoCodes'] = [
                [
                    'Code' => (string)$order['promo_code'],
                ],
            ];
        }

        try {
            $json = $this->httpRequest(
                'PUT',
                self::API_ORDER_VERIFY,
                $params,
                function($json) {
                    return
                        isset($json['Order']['Deliveries'][0]['LineItems'][0]['Details']['Price']) && // item_amount
                        isset($json['Order']['Details']['Tax'])                                    && // tax_amount
                        isset($json['Order']['Deliveries'][0]['SurchargeDetails'])                 && // shipping_amount
                        isset($json['Order']['SurchargeDetails'])                                  && // discount_amount
                        isset($json['Order']['Payments'])                                          && // discount_amount
                        isset($json['Order']['Details']['GrandTotal'])                             && // total_amount
                        is_array($json['Order']['Deliveries'][0]['SurchargeDetails'])              && // shipping_amount
                        is_array($json['Order']['SurchargeDetails'])                               && // discount_amount
                        is_array($json['Order']['Payments'])                                       && // discount_amount
                        $this->arrayElementCheck($json['Order']['Deliveries'][0]['SurchargeDetails'], function($key, $value) {return isset($value['Amount']);}) &&
                        $this->arrayElementCheck($json['Order']['SurchargeDetails'],                  function($key, $value) {return isset($value['Amount']);}) &&
                        $this->arrayElementCheck($json['Order']['Payments'],                          function($key, $value) {return isset($value['Details']['Amount']);});
                }
            );

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            list($fault_type, $error_message, $property_name) = $this->parseBadResponseException($e);

            // We can recover from some validation failures by trying again
            if ($fault_type == 'ValidationFailed') {

                // If we have a promo code, then we remove the promo code and try again
                if (stripos($error_message, 'Promo Code') !== false) {
                    if ($order['promo_code']) {
                        $order['promo_code'] = '';
                        return $this->getOrderDetails($customer_id, $customer_email, $order, $address, $authentication_token);
                    }
                }

                // If the latest_delivery_date is still "in the future", increment the delivery_date and try again
                if (stripos($error_message, 'ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDate') !== false ||
                    stripos($error_message, 'The products and accessories you selected cannot be fulfilled together') !== false) {

                    $delivery_date_time = strtotime($order['delivery_date']);
                    if (strtotime($order['latest_delivery_date']) > $delivery_date_time) {
                        $order['delivery_date'] = date('Y-m-d', strtotime('+1 day', $delivery_date_time));
                        return $this->getOrderDetails($customer_id, $customer_email, $order, $address, $authentication_token);
                    }
                }
            }

            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON Request Body:
        // {
        //     "Order": {
        //         "Customer": {
        //             "CustomerId": "",
        //             "Email": "",
        //             "Details": {
        //                  "FirstName": "",
        //                  "LastName": "",
        //                  "CompanyName": "",
        //                  "Address1": "",
        //                  "Address2": "",
        //                  "City": "",
        //                  "State": "",
        //                  "Zip": "",
        //                  "CountryCode": "",
        //                  "Phone1": ""
        //             }
        //         },
        //         "Deliveries": [
        //             {
        //                 "DeliveryDate": "/Date(1390521600000)/",
        //                 "GiftMessage": {
        //                     "Message": "",
        //                 },
        //                 "LineItems": [
        //                     {
        //                         "ProductId": ""
        //                     }
        //                 ],
        //                 "Recipient": {
        //                     "Address1": "",
        //                     "Address2": "",
        //                     "City": "",
        //                     "CompanyName": "",
        //                     "CountryCode": "",
        //                     "Email": "",
        //                     "FirstName": "",
        //                     "LastName": "",
        //                     "LocationType": "Residential",
        //                     "Phone": "",
        //                     "State": "",
        //                     "Zip": ""
        //                 }
        //             }
        //         ],
        //         "PromoCodes": [
        //             {
        //                 "Code": "String content"
        //             }
        //         ]
        //     }
        // }

        $return = [
            'item_amount'     => 0,
            'shipping_amount' => 0,
            'tax_amount'      => 0,
            'discount_amount' => 0,
            'total_amount'    => 0,
            'promo_code'      => $order['promo_code'],    // Included so we know whether the promo code was accepted or not
            'delivery_date'   => $order['delivery_date'], // Included so we know whether the delivery date was incremented or not
        ];

        // We only support one item per order (presently)
        $return['item_amount'] = (int)bcmul($json['Order']['Deliveries'][0]['LineItems'][0]['Details']['Price'], 100);

        // There can be multiple "shipping" charge lines
        foreach ($json['Order']['Deliveries'][0]['SurchargeDetails'] as $surcharge_detail) {
            $return['shipping_amount'] += (int)bcmul($surcharge_detail['Amount'], 100);
        }

        $return['tax_amount'] = (int)bcmul($json['Order']['Details']['Tax'], 100);

        // promos "discounts" are negative numbers
        foreach ($json['Order']['SurchargeDetails'] as $surcharge_detail) {
            $return['discount_amount'] += (int)bcmul(-$surcharge_detail['Amount'], 100);
        }

        // Gift certificate "discounts" are positive numbers.
        // Note: as Gift certificates are technically "payments" they are not included in the GrandTotal calculation.
        // Since we want our total_amount to really represent "amount owed" we subtract payments_total from the API's GrandTotal.
        $payments_total = 0;
        foreach ($json['Order']['Payments'] as $payment) {
            if (!isset($payment['Details']['Amount'])) {
                $this->handleUnexpectedResponse(__FUNCTION__, $request, $response);
            }

            $payment_amount = (int)bcmul($payment['Details']['Amount'], 100);

            $return['discount_amount'] += $payment_amount;
            $payments_total            += $payment_amount;
        }

        $return['total_amount'] = (int)bcmul($json['Order']['Details']['GrandTotal'], 100) - $payments_total;

        return $return;
    }

    /**
     * Create order
     *
     * @param  string $customer_id
     *
     * @param  string $customer_email       Due to the intricacies of supporting Provide's guest accounts, we must also
     *                                      pass in the email address in order for the request to be valid in their eyes.
     *
     * @param  array  $order                Associative array with the following keys:
     *                                      delivery_date, (yyyy-mm-dd)
     *                                      gift_message,  (optional, empty string is ok)
     *                                      product_id,    (provide's product id)
     *                                      promo_code,    (optional, empty string is ok)
     *                                      payment_token, (provide's payment token)
     *                                      po_number,     (sincerely's order id, for tracking purposes)
     *
     * @param  array  $address              Associative array with the following keys:
     *                                      firstname,
     *                                      lastname,
     *                                      company,
     *                                      street1,
     *                                      street2,
     *                                      city,
     *                                      state,
     *                                      postalcode,
     *                                      countrycode,
     *                                      location_type, (must be one of: Residential,Business,Hospital,FuneralHome,Apartment,Dormitory,Other,POBox)
     *                                      phone, (technically required, but we default to constant value if param is empty)
     *
     * @param  string $authentication_token
     *
     * @return string order_id
     *
     * @throws ClientException If we get an unexpected ValidationFailed or other error from Provide.
     */
    public function createOrder($customer_id, $customer_email, array $order, array $address, $authentication_token) {
        $params = [
            'authenticationToken' => $authentication_token,
            'Order' => [
                'Customer' => $this->getCustomerJson($customer_id, $customer_email, $authentication_token),
                'Deliveries' => [
                    [
                        'DeliveryDate' => $this->dateStringToJsonDate($order['delivery_date']),
                        'GiftMessage' => [
                            'Message'   => (string)$this->fixGiftMessage($order['gift_message'], $this->gift_message_line_length, $this->supports_virtual_newlines),
                        ],
                        'LineItems' => [
                            [
                                'ProductId' => (string)$order['product_id'],
                            ],
                        ],
                        'Recipient' => [
                            'FirstName'    => (string)$this->fixName($address['firstname']),
                            'LastName'     => (string)$this->fixName($address['lastname']),
                            'CompanyName'  => (string)$this->fixCompanyName($address['company']),
                            'Address1'     => (string)$address['street1'],
                            'Address2'     => (string)$address['street2'],
                            'City'         => (string)$address['city'],
                            'State'        => (string)$address['state'],
                            'Zip'          => (string)$this->fixZipCode($address['postalcode']),
                            'CountryCode'  => (string)$address['countrycode'],
                            'LocationType' => (string)$address['location_type'],
                            'Email'        => (string)'',
                            'Phone'        => (string)$this->fixPhone($address['phone']),
                        ],
                    ],
                ],
                'PONumber' => (string)$order['po_number'],
                'Payments' => [
                    [
                        "PaymentMethod" => [
                            "__type" => "TokenizedPaymentMethod:http://api.providecommerce.com/API/Payment/v1/",
                            "Token"  => (string)$order['payment_token'],
                        ],
                    ],
                ],
            ],
        ];

        // Passing in a promo_code is optional
        if ($order['promo_code']) {
            $params['Order']['PromoCodes'] = [
                [
                    'Code' => (string)$order['promo_code'],
                ],
            ];
        }

        try {
            $json = $this->httpRequest(
                'POST',
                self::API_ORDER_CREATE,
                $params,
                function($json) {
                    return isset($json['OrderId']);
                }
            );

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            list($fault_type, $error_message, $property_name) = $this->parseBadResponseException($e);

            // If we have a promo code, and the fault appears to be promo code related,
            // then we remove the promo code and try again without it.
            if ($order['promo_code'] && $fault_type == 'ValidationFailed' && stripos($error_message, 'Promo Code') !== false) {
                $order['promo_code'] = '';
                return $this->createOrder($customer_id, $customer_email, $order, $address, $authentication_token);

            } else {
                $this->handleBadResponseException(__FUNCTION__, $e);
            }
        }

        // Sample JSON Request Body (same as get_order_totals, plus PONumnber and Payments):
        // {
        //     "Order": {
        //         "Customer": {
        //             "CustomerId": "",
        //             "Email": "",
        //             "Details": {
        //                  "FirstName": "",
        //                  "LastName": "",
        //                  "CompanyName": "",
        //                  "Address1": "",
        //                  "Address2": "",
        //                  "City": "",
        //                  "State": "",
        //                  "Zip": "",
        //                  "CountryCode": "",
        //                  "Phone1": ""
        //             }
        //         },
        //         "Deliveries": [
        //             {
        //                 "DeliveryDate": "/Date(1390521600000)/",
        //                 "GiftMessage": {
        //                     "Message": "",
        //                 },
        //                 "LineItems": [
        //                     {
        //                         "ProductId": ""
        //                     }
        //                 ],
        //                 "Recipient": {
        //                     "Address1": "",
        //                     "Address2": "",
        //                     "City": "",
        //                     "CompanyName": "",
        //                     "CountryCode": "",
        //                     "Email": "",
        //                     "FirstName": "",
        //                     "LastName": "",
        //                     "LocationType": "Residential",
        //                     "Phone": "",
        //                     "State": "",
        //                     "Zip": ""
        //                 }
        //             }
        //         ],
        //         "PromoCodes": [
        //             {
        //                 "Code": "String content"
        //             }
        //         ],
        //         "PONumber": "String content",
        //         "Payments": [
        //             {
        //                 "PaymentMethod": {
        //                     "__type": "TokenizedPaymentMethod:http://api.providecommerce.com/API/Payment/v1/",
        //                     "Token": "String content"
        //                 }
        //             }
        //         ]
        //     }
        // }
        return $json['OrderId'];
    }

    /**
     * Get list of a customer's orders
     *
     * Note: by default we only return orders relevant to the brand associated with the client's applicationToken
     * 
     * @param  string $customer_id
     * @param  int    $page_number          One-indexed
     * @param  int    $page_size            Value must be between 1 and 25
     * @param  string $authentication_token
     * 
     * @return array Numeric array of associative arrays (orders) with the following keys:
     *               [
     *                   {
     *                       "id": "",
     *                       "date": "",
     *                       "recipients": [
     *                           {
     *                               "firstname": "",
     *                               "lastname": "",
     *                               "company": "",
     *                               "gift_message": "",
     *                               "delivery_date": "",
     *                               "items": [
     *                                   {
     *                                       "product_id": "",
     *                                       "quantity": 1,
     *                                       "name": "",
     *                                       "image_url": ""
     *                                   }
     *                               ]
     *                           }
     *                       ]
     *                   }
     *               ]
     */
    public function getOrders($customer_id, $page_number, $page_size, $authentication_token) {
        try {
            $json = $this->httpRequest(
                'GET',
                self::API_ORDER_GET,
                [
                    'customerId'          => $customer_id,
                    'pageNumber'          => $page_number,
                    'pageSize'            => $page_size,
                    'authenticationToken' => $authentication_token,
                    'filterOrdersByBrand' => 'true',
                ],
                function($json) {
                    return
                        isset($json['OrderHistoryList']) && is_array($json['OrderHistoryList']) &&
                        $this->arrayElementCheck($json['OrderHistoryList'], function($key, $value) {
                            return isset($value['OrderId']) && isset($value['Details']['OrderDate']) && isset($value['Deliveries']) && is_array($value['Deliveries']);
                        });
                }
            );
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $this->handleBadResponseException(__FUNCTION__, $e);
        }

        // Sample JSON response body:
        // {
        //     "OrderHistoryList": [
        //         {
        //             "Customer": {
        //                 "CustomerId": "",
        //                 "Details": {
        //                     "Address1": "",
        //                     "Address2": "",
        //                     "BirthDate": null,
        //                     "City": "",
        //                     "CompanyName": "",
        //                     "CountryCode": "",
        //                     "EmailOptIn": true,
        //                     "FirstName": "",
        //                     "LastName": "",
        //                     "Phone1": "",
        //                     "Phone2": "",
        //                     "State": "",
        //                     "Zip": ""
        //                 },
        //                 "Email": ""
        //             },
        //             "Deliveries": [
        //                 {
        //                     "DeliveryDate": "/Date(1415088000000-0800)/",
        //                     "Details": {
        //                         "Tax": 0,
        //                         "TaxRate": 0.0825,
        //                         "TaxableFreightTotal": 0,
        //                         "TaxableTotal": 0,
        //                         "ShipMethod": "",
        //                         "Subtotal": 19.99,
        //                         "SuppressTracking": false,
        //                         "FlexibleDate": null,
        //                         "OccasionDate": null
        //                     },
        //                     "GiftMessage": {
        //                         "Message": "",
        //                         "Occasion": null,
        //                         "Signature": "",
        //                         "OccasionId": null
        //                     },
        //                     "LineItems": [
        //                         {
        //                             "Accessories": [
        // 
        //                             ],
        //                             "Details": {
        //                                 "CarrierName": null,
        //                                 "CarrierPhone": null,
        //                                 "ItemStatus": null,
        //                                 "Name": "6 Fancy Strawberries",
        //                                 "Price": 19.99,
        //                                 "ShipDate": null,
        //                                 "SkuId": "101699",
        //                                 "StrikePrice": 0,
        //                                 "TrackingNumber": null,
        //                                 "TrackingUrl": null,
        //                                 "ImageUrl": "https://cimages.prvd.com/is/image/ProvideCommerce/GFB_12_BRR10006_W6_Test_HOL_SQ?$SSSProductImage$",
        //                                 "PersonalizedImageUrl": "https://cimages.prvd.com/is/image/ProvideCommerce/GFB_12_BRR10006_W6_Test_HOL_SQ?$SSSProductImage$",
        //                                 "PriceAfterDiscount": 19.99,
        //                                 "FulfillmentType": "Unknown",
        //                                 "SmallImageUrl": "https://cimages.prvd.com/is/image/ProvideCommerce/GFB_12_BRR10006_W6_Test_HOL_SQ?$SSSProductImage$",
        //                                 "FulfillmentOrderId": null
        //                             },
        //                             "LineNumber": null,
        //                             "ProductId": "30141704",
        //                             "Quantity": 1,
        //                             "OrderDeliveryLineItemId": "3",
        //                             "SurchargeDetails": [
        // 
        //                             ]
        //                         }
        //                     ],
        //                     "Recipient": {
        //                         "Address1": "",
        //                         "Address2": "",
        //                         "BirthDate": null,
        //                         "City": "",
        //                         "CompanyName": "",
        //                         "CountryCode": "",
        //                         "Email": "",
        //                         "FirstName": "",
        //                         "LastName": "",
        //                         "LocationType": "Residential",
        //                         "Phone": "",
        //                         "RecipientId": null,
        //                         "RelationshipType": null,
        //                         "State": "",
        //                         "Zip": "",
        //                         "RelationshipId": null,
        //                         "SaveToCustomer": true
        //                     },
        //                     "SurchargeDetails": [
        //                         {
        //                             "Amount": 14.99,
        //                             "Category": "Shipping",
        //                             "Name": " Delivery",
        //                             "OrderSurchargeId": "0"
        //                         }
        //                     ],
        //                     "IsFlexibleDelivery": false,
        //                     "IsDeliveryExpedited": false,
        //                     "ServiceLevelCode": 0,
        //                     "ServiceType": "DeliveryDate",
        //                     "DeliveryTime": "Afternoon",
        //                     "OrderDeliveryId": "92745473",
        //                     "AdditionalDeliveryDetails": null
        //                 }
        //             ],
        //             "Details": {
        //                 "AccessoryTotal": 0,
        //                 "GrandTotal": 34.98,
        //                 "OrderDate": "/Date(1413911966463-0700)/",
        //                 "ShippingTotal": 14.99,
        //                 "Subtotal": 19.99,
        //                 "SurchargeTotal": 14.99,
        //                 "Tax": 0,
        //                 "PartnerCode": "SSS",
        //                 "OrderType": "Standard",
        //                 "FulfillmentType": "Unknown",
        //                 "AlternateOrderId": null,
        //                 "IsCanceled": false,
        //                 "IsGuestOrder": true
        //             },
        //             "OrderId": "330051556609",
        //             "OrderInformation": "",
        //             "PONumber": "",
        //             "Payments": [
        // 
        //             ],
        //             "PromoCodes": [
        // 
        //             ],
        //             "ReferenceCode": "sincerelysharisberriesapp",
        //             "SurchargeDetails": [
        // 
        //             ],
        //             "OriginalOrderId": "",
        //             "SiteSplitId": "1"
        //         }
        //     ]
        // }

        $return = [];

        foreach ($json['OrderHistoryList'] as $json_order) {
            $order = [];
            $order['id'] = $json_order['OrderId'];
            $order['date'] = $this->jsonDateToDateString($json_order['Details']['OrderDate']);

            $order['recipients'] = [];
            foreach ($json_order["Deliveries"] as $json_delivery) {
                $recipient = [];
                $recipient['firstname']     = $json_delivery['Recipient']['FirstName'];
                $recipient['lastname']      = $json_delivery['Recipient']['LastName'];
                $recipient['company']       = $json_delivery['Recipient']['CompanyName'];
                $recipient['gift_message']  = trim($json_delivery['GiftMessage']['Message'] . "\n" . $json_delivery['GiftMessage']['Signature']);
                $recipient['delivery_date'] = $this->jsonDateToDateString($json_delivery['DeliveryDate']);

                $recipient['items'] = [];
                foreach ($json_delivery['LineItems'] as $json_item) {
                    $item = [];
                    $item['product_id'] = $json_item['ProductId'];
                    $item['quantity']   = $json_item['Quantity'];
                    $item['name']       = $json_item['Details']['Name'];
                    $item['image_url']  = $json_item['Details']['ImageUrl'];

                    $recipient['items'][] = $item;
                }

                // Only include this recipient if it has items
                if (count($recipient['items'])) {
                    $order['recipients'][] = $recipient;
                }
            }

            // Only include this order if it has recipients
            if (count($order['recipients'])){
                 $return[] = $order;
            }
        }

        return $return;
    }

    /**
     * Parse a BadResponseException from Provide
     *
     * Handles both new and old-style error responses, and only returns the first
     * error of a multi-error ValidationFailed FaultType.
     *
     * @param  \GuzzleHttp\Exception\BadResponseException $e
     *
     * @return array Returns a numeric array with 3 values: 0) fault_type; 1) error_message; 2) property_name
     */
    private function parseBadResponseException(\GuzzleHttp\Exception\BadResponseException $e) {
        $fault_type    = '';
        $error_message = '';
        $property_name = '';

        $json = json_decode($e->getResponse()->getBody(), true);

        if (is_null($json)) {
            $json = [
                'FaultType'    => 'BadResponse',
                'ErrorMessage' => 'Provide returned a response that could not be parsed as JSON',
            ];
        }

        // Support legacy-style error messages
        // {
        //     "FaultType": "ValidationFailed",
        //     "Message": "Validation failed for Delivery: ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDateNoDatesAvailable"
        // }
        $fault_type    = isset($json['FaultType']) ? $json['FaultType'] : 'NoFaultType';
        $error_message = isset($json['Message'])   ? $json['Message']   : '';
        $property_name = '';

        // New-style error responses may contain more than one validation error, but for simplicity, we only concern ourselves with the first.
        // {
        //     "FaultType": "ValidationFailed",
        //     "Message": "ValidationErrors",
        //     "ValidationErrors": [
        //         {
        //             "ErrorMessage": "The products and accessories you selected cannot be fulfilled together. Sorry, please select a different combination.",
        //             "PropertyName": "Order.Deliveries[0].LineItems"
        //         },
        //         {
        //             "ErrorMessage": "Delivery is not available to the selected state.",
        //             "PropertyName": "Order.Deliveries[0].Recipient.State"
        //         }
        //     ],
        //     "ReferenceId": "fdf5d3fc-7be5-49d3-9af5-23714b0e2202"
        // }
        //
        if ($fault_type == 'ValidationFailed' && $error_message == 'ValidationErrors' && isset($json['ValidationErrors']) && count($json['ValidationErrors'])) {
            $error_message = $json['ValidationErrors'][0]['ErrorMessage'];
            $property_name = $json['ValidationErrors'][0]['PropertyName'];
        }

        return [
            $fault_type,
            $error_message,
            $property_name,
        ];
    }

    /**
     * Handle all BadResponseExceptions and log and throw ClientException exceptions
     *
     * @param  string                                     $function
     * @param  \GuzzleHttp\Exception\BadResponseException $e
     *
     * @return null
     *
     * @throws ClientException
     * @throws MissingPhoneNumberException
     */
    private function handleBadResponseException($function, \GuzzleHttp\Exception\BadResponseException $e) {
        $error_message_to_return = self::DEFAULT_ERROR_MESSAGE;

        error_log($e->getResponse()->getBody());

        list($fault_type, $error_message, $property_name) = $this->parseBadResponseException($e);

        $this->logRequestAndResponse($function, $fault_type, $error_message, $e->getRequest(), $e->getResponse());

        if ($fault_type == 'ValidationFailed') {
            // e.g. {"FaultType":"ValidationFailed","Message":"Validation failed for Delivery: ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDate"}
            // e.g. {"FaultType":"ValidationFailed","Message":"Validation failed for Delivery: ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDateNoDatesAvailable"}
            if (stripos($error_message, 'ValidateDelivery_CannotDeliveryAllProductsOnDeliveryDate') !== false ||
                stripos($error_message, 'The products and accessories you selected cannot be fulfilled together') !== false) {
                throw new ClientException("Sorry, the product you selected is no longer available for delivery on that date. Please choose another.");
            }

            // e.g. {"FaultType":"ValidationFailed","Message":"Validation failed for Delivery: ValidateDelivery_InvalidState"}
            if (stripos($error_message, 'ValidateDelivery_InvalidState') !== false ||
                stripos($error_message, 'Delivery is not available to the selected state') !== false) {
                throw new ClientException("Sorry, we do not currently support delivery to that state. Please choose another recipient.");
            }

            // e.g. {"FaultType":"ValidationFailed","Message":"Unable to process Payment"}
            if (stripos($error_message, 'Payment') !== false) {
                throw new ClientException("Sorry, we're having trouble processing the payment. Perhaps try another credit card?");
            }

            // e.g.
            // {"FaultType":"ValidationFailed","Message":"\r\n
            // Customer First Name cannot be blank.\r\n
            // Customer Last Name cannot be blank.\r\n
            // Customer Address cannot be blank.\r\n
            // Customer city cannot be blank.\r\n
            // Customer state cannot be blank.\r\n
            // Customer zip cannot be blank.\r\n
            // Customer Country cannot be blank.\r\n
            // Customer Phone cannot be blank."}
            if (stripos($error_message, 'Customer') !== false) {
                // If the Provide customer is *only* missing a phone number then we can throw a specific exception to
                // alert the client to update the phone number and retry.
                // e.g. {"FaultType":"ValidationFailed","Message":"\u000d\u000aCustomer Phone cannot be blank."}
                if (strtolower(trim($error_message)) == 'customer phone cannot be blank.') {
                    throw new MissingPhoneNumberException('Sorry, your billing address is incomplete. Please update it in "Settings" > "My Profile".');

                } elseif (strtolower(trim($error_message)) == 'customer address cannot be a po box.') {
                    throw new ClientException('Sorry, your billing address cannot be a PO Box. Please update it in "Settings" > "My Profile".');

                } else {
                    throw new ClientException('Sorry, your billing address is incomplete. Please update it in "Settings" > "My Profile".');
                }
            }

            // We only want to pass along the Message field for ValidationFailed errors
            if ($error_message) {
                $error_message_to_return .= ' ' . $error_message;
            }

        } elseif ($fault_type == 'LoginFailed') {
            // e.g. {"FaultType":"LoginFailed","Message":null,"ValidationErrors":null}
            // Technically, Snapi should catch the LoginFailedException, and return an INVALID_SESSION response, just as
            // if SnapiResponse::auth() had failed, but Bryan is concerned that the apps might not handle this gracefully
            // for all endpoints, so until then, this "helpful" error message will be returned to the user.
            // https://trello.com/c/eCfZVkHq/197-confirm-that-all-apps-respond-appropriately-to-an-invalid-session-error-from-any-snapi-endpoint-and-if-not-update-them
            throw new LoginFailedException('Sorry, it looks like your password has changed. Please logout in "Settings" and then log back in.');
        }

        throw new ClientException($error_message_to_return);
    }

    /**
     * Consistent handling of JSON parsed in response from Provide
     *
     * @param  string                    $function
     * @param  \GuzzleHttp\Psr7\Request  $request
     * @param  \GuzzleHttp\Psr7\Response $response
     *
     * @return array
     */
    private function handleResponseJson($function, \GuzzleHttp\Psr7\Request $request, \GuzzleHttp\Psr7\Response $response) {
        $json = json_decode($response->getBody(), true);

        if (is_null($json)) {
            $this->handleUnexpectedResponse($function, $request, $response);
        } else {
            return $json;
        }
    }

    /**
     * Log and throw ClientException for unexpected JSON structure in response
     *
     * @param  string                    $function
     * @param  \GuzzleHttp\Psr7\Request  $request
     * @param  \GuzzleHttp\Psr7\Response $response
     *
     * @return null
     *
     * @throws ClientException
     */
    private function handleUnexpectedResponse($function, \GuzzleHttp\Psr7\Request $request, \GuzzleHttp\Psr7\Response $response) {
        $this->logRequestAndResponse($function, 'UnexpectedResponse', 'The JSON response did not contain what we were expecting', $request, $response);
        throw new ClientException(self::DEFAULT_ERROR_MESSAGE);
    }

    /**
     * Consistent (and safe) filtering and logging of requests and responses
     *
     * @todo less extreme redaction of 'createCustomer', 'createPaymentMethod', 'login'
     *
     * @param  string                    $function
     * @param  string                    $fault_type
     * @param  string                    $error_message
     * @param  \GuzzleHttp\Psr7\Request  $request
     * @param  \GuzzleHttp\Psr7\Response $response
     *
     * @return null
     */
    private function logRequestAndResponse($function, $fault_type, $error_message, \GuzzleHttp\Psr7\Request $request, \GuzzleHttp\Psr7\Response $response) {
        if (in_array($function, ['createCustomer', 'createPaymentMethod', 'login', 'create_customer', 'create_payment_method'])) {
            $request = $this->headersToString($request->getHeaders()) . "\n\nREDACTED";
        } else {
            $request = $this->headersToString($request->getHeaders()) . "\n\n" . $request->getBody();
        }

        $response = $this->headersToString($response->getHeaders()) . "\n\n" . $response->getBody();

        $details = [
            'fault_type' => $fault_type,
            'message'    => $error_message,
            'request'    => $request,
            'response'   => $response,
        ];

        $this->logger->log(self::LOG_EXCEPTIONS_AS, 'Provide\Client: ' . $function, $details);
    }

    /**
     * Turn PSR-7 style header arrays into a string
     *
     * @param  array  $headers
     *
     * @return string
     */
    private function headersToString(array $headers) {
        $headers_string = '';
        foreach ($headers as $name => $values) {
            $headers_string .= $name . ': ' . implode(', ', $values) . "\n";
        }
        return $headers_string;
    }
}
