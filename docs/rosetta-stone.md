# Translating between Mobile App functions and Provide API calls

Note: All Provide API calls must pass `applicationToken={AUTHENTICATIONCONTEXT}`
which is a hardcoded token generated specifically for each API client. For
clarity, I've left those out of the examples below. Currently, Sincerely's token
is `og33hpmgec2em4gaeumt2st4`. To test the various API calls, use cURL against
Provide's development server:

To **GET** a request:

```
curl -v --insecure 'https://lm48api.providecommerce.com/API/Customer/v1/JSON/DoesCustomerExistByEmail?email=test%40example.com&applicationToken=og33hpmgec2em4gaeumt2st4'
```

To **POST** a request:

```
curl -v --insecure -H "Content-Type: application/json" -d '{"Email":"test@example.com","Password":"testing123"}' 'https://lm48api.providecommerce.com/API/Customer/v1/JSON/CreateCustomer?email=test%40example.com&applicationToken=og33hpmgec2em4gaeumt2st4'
```

TO **PUT** a request:

```
curl -v --insecure -H "Content-Type: application/json" -X PUT -d '{"Foo":"Bar"}' 'https://lm48api.providecommerce.com/API/Customer/v1/JSON/UpdateCustomer?customerId=1234567890&authenticationToken=abcdefghijklmnopqrstuvwxyz1234567890&applicationToken=og33hpmgec2em4gaeumt2st4'
```

## Data Validation

The Provide API will throw errors if the data submitted does not meet certain minimal
requirements.

Field Name | Required? | Valid Regex | Min Length | Max Length | Notes
--- | :---: | --- | :---: | :---: | ---
FirstName | X | *restrictive* | 1 | 20 | Sincerely will use "X" if we don't have a viable first name
LastName | X | *restrictive* | 1 | 40 | Sincerely will use "X" if we don't have a viable last name
CompanyName | | *permissive* | 1 | 40 |
Address1 | X | *permissive* | 1 | 40 |
Address2 | | *permissive* | 1 | 40 |
City | X | *permissive* | 1 | 40 |
State | X | `^[a-zA-Z]{2}$` | 2 | 2 | Actual regex is more complex
Zip | X | `^[0-9]{5}$` | 5 | 5 |
CountryCode | X | `^[a-zA-Z]{2}$` | 2 | 2 |
Phone | X | `^[2-9][0-9]{9}$` | 10 | 10 | Actual regex is more complex, but Sincerely defaults to a common phone number


The "restrictive" regular expression `^(\[a-zA-Z0-9])([\x20-\x21\x26-\x29\x2c-\x39\x3f-\x5a\x61-\x7a])*$` accepts all printable ASCII characters except the following:

* `"` - *double quote*
* `#` - *pound sign*
* `$` - *dollar sign*
* `%` - *percent sign*
* `*` - *asterisk*
* `+` - *plus sign*
* `:` - *colon*
* `;` - *semicolon*
* `<` - *less than*
* `=` - *equal sign*
* `>` - *greater than*
* `[` - *open square bracket*
* `\` - *backslash*
* `]` - *close square bracket*
* `^` - *caret*
* `_` - *underscore*
* <code>`</code> - *backtick (grave accent)*
* `{` - *open curly bracket*
* `|` - *bar*
* `}` - *close curly bracket*
* `~` - *tilde*

Conversely, acceptable special characters include:

* ` ` - *space*
* `!` - *exclamation point*
* `&` - *ampersand*
* `'` - *single quote*
* `(` - *open parens*
* `)` - *close parens*
* `,` - *comma*
* `-` - *dash*
* `.` - *period*
* `/` - *slash*
* `?` - *question mark*
* `@` - *at sign*

Additionally, it imposes that the first character be alphanumeric.

The "permissive" regular expression `^[\x20-\x5a\x5f-\x7b\x7d\s]+$` accepts all printable
ASCII characters except the following 6:

* `[` - *left square bracket*
* `\` - *backslash*
* `]` - *right square bracket*
* `^` - *caret*
* `|` - *bar*
* `~` - *tilde*


## Check if user account exists

If user does not exist in Sincerely's database or if user exists without a Provide
token, we check if user exists in Provide's database.

`GET /DoesCustomerExistByEmail?email={EMAIL}`

Response

    {
        "CustomerExists": true
    }

## Login

Authenticate user against Provide's database (assuming account exists).

`GET /GetAuthenticationToken?email={EMAIL}&password={PASSWORD}`

Response on success

    {
        "AuthenticationContext":
        {
            "ApplicationToken":"String content",
            "AuthenticationToken":"String content"
        }
    }

Response on failure

    {
        "FaultType": "LoginFailed",
        "Message": null
    }


`GET /GetCustomerByEmail?email={EMAIL}&authenticationToken={AUTHENTICATIONTOKEN}`

Response

    {
        "Customer": 
        {
            "CustomerId": "String content",
            "Details":    
            {
                "Address1": "String content",
                "Address2": "String content",
                "City": "String content",
                "CompanyName": "String content",
                "CountryCode": "String content",
                "EmailOptIn": false,
                "FirstName": "String content",
                "LastName": "String content",
                "Phone1": "String content",
                "Phone2": "String content",
                "State": "String content",
                "Zip": "String content"
            },
            "Email": "String content"
        }
    }

At this point, Sincerely will associate both the AuthenticationToken and the
CustomerId with the user's email address in Sincerely's database, and all subsequent 
requests that require authentication for the customer will pass both the
`customerId` and the `authenticationToken`.

## Create new account

If user did not exist in Provide's database (regardless of whether user existed in
Sincerely's database), we need to create Provide account.

`POST /CreateCustomer`

Request Body

    {
    	"Email":"String content",
    	"Password":"String content"
    }

**Note:** The request body above is the password.

Response

    {
        "CustomerId": "String content"
    }

`GET /GetAuthenticationToken?email={EMAIL}&password={PASSWORD}`

Response

    {
        "Token": 
        {
           "ApplicationToken": "String content",
           "AuthenticationToken": "String content"
        }
    }

At this point, Sincerely will associate both the AuthenticationToken and the
CustomerId with the user's email address in Sincerely's database, and all subsequent 
requests that require authentication for the customer will pass both the
`customerId` and the `authenticationToken`.

## Logout

Sincerely will drop the Provide AuthenticationToken associated with the user in
Sincerely's database, but will maintain the CustomerId, which will force any
subsequent requests to re-authenticate against Provide's database via
`GET /GetAuthenticationToken?email={EMAIL}&password={PASSWORD}`


## Update Account

To update the customer's account information (i.e. Address, phone numbers, names, etc.)

`PUT /UpdateCustomer?customerId={CUSTOMERID}&authenticationToken={AUTHENTICATIONTOKEN}`

Request body

    {
    	"CustomerId":"String content",
    	"Details":
    	{
    		"FirstName":"String content",  (required)
    		"LastName":"String content",  (required)
    		"Address1":"String content",  (required)
    		"Address2":"String content",
    		"City":"String content",  (required)
    		"State":"String content",  (required,2 letter code)
    		"Zip":"String content",  (required)
    		"CountryCode":"String content",  (required, 2 letter code)
    		"Phone1":"String content",  (required)
    		"Phone2":"String content",
    		"CompanyName":"String content"
    	}
    }

Response

    {}

## Update Password

`PUT /UpdateCustomerPassword`

Request body

    {
        "CustomerId":"String content",
        "AuthenticationToken":"String content",
        "NewPassword":"String content"
    }

`GET /GetAuthenticationToken?email={EMAIL}&password={PASSWORD}`

Response

    {
        "Token": 
        {
           "ApplicationToken": "String content",
           "AuthenticationToken": "String content"
        }
    }

At this point, Sincerely will associate the new `AuthenticationToken` with the
user's email address in Sincerely's database.

## Forgot Password

First, check to see if the email belongs to a Provide customer

`GET /DoesCustomerExistByEmail?email={EMAIL}`

Response

    {
        "CustomerExists": true
    }

If the email belongs to a Provide customer, then we will trigger a
forgot password request that will cause Provide to send this customer an email.
Once the user resets their password, they can use the new password to log in to
the app using the flows described previously.

`POST /SendForgottenPasswordEmail`

Request body

    {
        "Email":"String content",
    }

Response

    { }

If the email does not belong to a Provide customer, but does belong to a
Sincerely customer, then Sincerely will trigger a forgot password request sent
from sincerely.com but with a ProFlowers email template.

## Get past recipients for a user

`GET /GetCustomerRecipients?customerId={CUSTOMERID}`

Response

    {
        "Recipients": 
        [{
            "Address1": "String content",
            "Address2": "String content",
            "City": "String content",
            "Company": "String content",
            "Country": "String content",
            "Email": "String content",
            "FirstName": "String content",
            "LastName": "String content",
            "LocationType": "String content",
            "Phone": "String content",
            "RecipientId": "String content",
            "RelationshipType": "String content",
            "State": "String content",
            "Zip": "String content"
        }]
   }

## Get payment methods for a user

`GET /GetPaymentMethod?customerId={CUSTOMERID}&authenticationToken={AUTHENTICATIONTOKEN}`

Response

    {
        "PaymentMethods": [
            {
                "__type": "SavedCreditCard:http://api.providecommerce.com/API/Payment/v1/",
                "PaymentId": "String content",
                "CardHolderName": "String content",
                "CardType": "String content",
                "ExpirationMonth": 12,
                "ExpirationYear": 2014,
                "LastFour": "String content"
            }
        ]
    }
    
Note: response needs other attributes to be useful (last4, cardType, etc)

## Create a new payment method for a user

Sincerely will tokenize a user's credit card using Provide's payment gateway
(CyberSource) and pass that token along to Provide. Sincerely will store the
Provide Payment Id in response, and pass that along with any order transactions.

`POST /CreatePaymentMethod?authenticationToken={AUTHENTICATIONTOKEN}`

Request body

    {
        "PaymentMethod":{
            "__type":"NewCreditCard:http://api.providecommerce.com/API/Payment/v1/",
            "CustomerId":"String content",
            "CardNumber":"String content",
            "ExpirationMonth":2147483647,
            "ExpirationYear":2147483647,
            "SecurityCode":"String content"
        }
    }

*Note: the `__type` attribute is currently required. `Token` must be a 16 digit string.*

Response

    {
        "PaymentId":"String content"
    }

## Remove a payment method for a user

`DELETE /DeletePaymentMethod?paymentId={PAYMENTID}&authenticationToken={AUTHENTICATIONTOKEN}`

## Verify Order

Calculate totals and various server-supplied values (shipping, etc). Request
body should be the same as for /CreateOrder, which submits an order

`PUT /UpdateOrderTotals?authenticationToken={AUTHENTICATIONTOKEN}`

Request body

    {
        "Order": {
            "Customer": {
                "CustomerId": ""
            },
            "Deliveries": [
                {
                    "DeliveryDate": "/Date(1390521600000)/",
                    "GiftMessage": {
                        "Message": "",
                    },
                    "LineItems": [
                        {
                            "ProductId": ""
                        }
                    ],
                    "Recipient": {
                        "Address1": "",
                        "Address2": "",
                        "City": "",
                        "CompanyName": "",
                        "CountryCode": "",
                        "Email": "",
                        "FirstName": "",
                        "LastName": "",
                        "LocationType": "Residential",
                        "Phone": "",
                        "State": "",
                        "Zip": ""
                    }
                }
            ],
            "PromoCodes": [
                {
                    "Code": "String content"
                }
            ]
        }
    }

Response

    {
        "Order": {
            "Customer": {
                "CustomerId": "",
                "Details": {
                    "Address1": "",
                    "Address2": "",
                    "City": "",
                    "CompanyName": "",
                    "CountryCode": "",
                    "EmailOptIn": false,
                    "FirstName": "",
                    "LastName": "",
                    "Phone1": "",
                    "Phone2": "",
                    "State": "",
                    "Zip": ""
                },
                "Email": ""
            },
            "Deliveries": [
                {
                    "DeliveryDate": "/Date(1392278400000-0800)/",
                    "Details": {
                        "Tax": 4.63,
                        "TaxRate": 0.0875,
                        "TaxableFreightTotal": 0,
                        "TaxableTotal": 52.95
                    },
                    "GiftMessage": {
                        "Message": "",
                        "Occasion": "",
                        "Signature": ""
                    },
                    "LineItems": [
                        {
                            "Details": {
                                "ItemStatus": null,
                                "Name": "",
                                "Price": 49.96,
                                "ShipDate": null,
                                "StrikePrice": 69.96,
                                "TrackingNumber": null
                            },
                            "LineNumber": null,
                            "ProductId": ""
                        }
                    ],
                    "Recipient": {
                        "Address1": "",
                        "Address2": "",
                        "City": "",
                        "CompanyName": "",
                        "CountryCode": "",
                        "Email": "",
                        "FirstName": "",
                        "LastName": "",
                        "LocationType": "",
                        "Phone": "",
                        "RecipientId": "",
                        "RelationshipType": "",
                        "State": "",
                        "Zip": ""
                    },
                    "SurchargeDetails": [
                        {
                            "Amount": 4.98,
                            "Category": "",
                            "Name": "Standard "
                        },
                        {
                            "Amount": 2.99,
                            "Category": "",
                            "Name": ""
                        }
                    ]
                }
            ],
            "Details": {
                "AccessoryTotal": 0,
                "GrandTotal": 62.56,
                "ReferenceCode": "SincerelyProflowersApp",
                "ShippingTotal": 4.98,
                "Subtotal": 49.96,
                "SurchargeTotal": 7.97,
                "Tax": 4.63
            },
            "OrderId": null,
            "PONumber": "",
            "Payments": [
                {
                    "Details": {
                        "Amount": 25.00
                    },
                    "PaymentMethod": {
                        "__type": "GiftCard:http://api.providecommerce.com/API/Payment/v1/",
                        "GiftCode": "can7akqqpmjhg9j93"
                    }
                }
            ],
            "PromoCodes": [
                {
                    "Code": "CAN7AKQQPMJHG9J93"
                }
            ],
            "SurchargeDetails": [
                {
                    "Amount": -12.49,
                    "Category": "Promotion",
                    "Name": "25% Off"
                }
            ]
        }
    }

*Notes:*

* SurchargeDetails represents a discount either a constant dollar difference, or a percent off
* Payments includes the results of a gift certificate applied to the order


## Submit Order

`POST /CreateOrder?customerId={CUSTOMERID}&authenticationToken={AUTHENTICATIONTOKEN}`

Request body

    {
        "Order": {
            "Customer": {
                "CustomerId": ""
            },
            "Deliveries": [
                {
                    "DeliveryDate": "/Date(1390521600000)/",
                    "GiftMessage": {
                        "Message": "",
                    },
                    "LineItems": [
                        {
                            "ProductId": ""
                        }
                    ],
                    "Recipient": {
                        "Address1": "",
                        "Address2": "",
                        "City": "",
                        "CompanyName": "",
                        "CountryCode": "",
                        "Email": "",
                        "FirstName": "",
                        "LastName": "",
                        "LocationType": "Residential",
                        "Phone": "",
                        "State": "",
                        "Zip": ""
                    }
                }
            ],
            "PromoCodes": [
                {
                    "Code": "String content"
                }
            ],
            "PONumber": "String content",
            "Payments": [
                {
                    "PaymentMethod": {
                        "__type": "SavedCreditCard:http://api.providecommerce.com/API/Payment/v1/",
                        "PaymentId": "String content"
                    }
                }
            ]
        }
    }

*Note: The chief difference between the request body of UpdateOrderTotals and
CreateOrder are the inclusion of `PONumber` and `Payments`.*


Response

    {
        "OrderId":"String content"
    }


## Other nice-to-haves

This may come post V-Day

* Get an existing Provide customer's addresses
* Get an existing Provide customer's order history
