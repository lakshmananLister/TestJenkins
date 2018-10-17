# PAPI - Provide Commerce API

## Host

e.g. api.providecommerce.com (or something similar)

TODO: create sandbox environment

## Media Type

[Collection+JSON application/vnd.collection+json](http://amundsen.com/media-types/collection/format/)

## Authentication

SSL + HTTP basic access auth

## Status Codes

These are the minimal 6 status codes the client should expect to receive from
the API server.

### 200 (OK)

Request was successful.

### 301 (Moved Permanently)

Used in response to a successful POST, redirecting the client to the URL of the
newly created resource. Can also be used in response to a GET, if the URL for a
resource has changed.

### 400 (Bad Request)

Something in the client's request was malformed. The response should include an
error message as defined by the Collection+JSON spec:

    {
        "collection": {
            "version": "1.0",
            "href": "/",
            "error": {
                "title": "Invalid whatever",
                "code": "11011",
                "message": "Your whatever was invalid, fix it"
            }
        }
    }

### 404 (Not Found)

Requested URL not found

### 409 (Conflict)

Used when trying to PUT a resource that has already been modified by another
client in the interim.

### 500 (Internal Server Error)

Something wrong on the server-side.

## Resources

All resources must support HTTP's HEAD, GET, and OPTIONS methods. Some resources
may additionally support HTTP's POST, PUT, PATCH, and DELETE methods, depending
on business rules and context, which should be discoverable via an OPTIONS
request.

### /

Provides a list of links to all API versions (that are accessible to the current
API-client)

Sample GET response:

    {
        "collection": {
            "version": "1.0",
            "href": "/",
            "links": [
                {
                    "href": "/v1",
                    "rel": "version"
                }
            ]
        }
    }

### /v1

Provides a list of all top-level collection resources for this version of the
API (that are accessible to the current API-client)

Sample GET response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1",
            "links": [
                {
                    "href": "/v1/users",
                    "rel": "collection"
                },
                {
                    "href": "/v1/orders",
                    "rel": "collection"
                },
                {
                    "href": "/v1/promotions",
                    "rel": "collection"
                },
                {
                    "href": "/v1/addresses",
                    "rel": "collection"
                },
                {
                    "href": "/v1/products",
                    "rel": "collection"
                }
            ]
        }
    }


### /v1/users

Provides a means to search for and create new users

(also supports POST)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/users",
            "queries": [
                {
                    "href": "/v1/users",
                    "rel": "search",
                    "prompt": "Search users",
                    "data": [
                        {
                            "prompt": "Email address of user",
                            "name": "email"
                        },
                        {
                            "prompt": "Password of user",
                            "name": "password"
                        },
                        {
                            "prompt": "First name of user",
                            "name": "givenName"
                        },
                        {
                            "prompt": "Last name of user",
                            "name": "familyName"
                        }
                    ]
                }
            ],
            "template": {
                "data": [
                    {
                        "prompt": "Email address of user",
                        "name": "email"
                    },
                    {
                        "prompt": "First name of user",
                        "name": "givenName"
                    },
                    {
                        "prompt": "Last name of user",
                        "name": "familyName"
                    }
                ]
            }
        }
    }

### /v1/users/{user_id}

Provides detailed information about a specific user

(also supports PUT, PATCH)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/users/12345",
            "items": [
                {
                    "href": "/v1/users/12345",
                    "data": [
                        {
                            "name": "email",
                            "value": "zaphod@example.com"
                        },
                        {
                            "name": "givenName",
                            "value": "Zaphod"
                        },
                        {
                            "name": "familyName",
                            "value": "Beeblebrox"
                        }
                    ],
                    "links": [
                        {
                            "href": "/v1/users/12345/payment-methods",
                            "rel": "collection"
                        },
                        {
                            "href": "/v1/users/12345/orders",
                            "rel": "collection"
                        },
                        {
                            "href": "/v1/users/12345/contacts",
                            "rel": "collection"
                        }
                    ]
                }
            ]
        }
    }

### /v1/users/{user_id}/payment-methods

Provides a summarized list of a user's payment methods (credit cards)

(also supports POST)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/users/12345/payment-methods",
            "items": [
                {
                    "href": "/v1/users/12345/payment-methods/1",
                    "data": [
                        {
                            "name": "name",
                            "value": "Zaphod X Beeblebrox"
                        },
                        {
                            "name": "last4",
                            "value": "1234"
                        },
                        {
                            "name": "type",
                            "value": "Mastercard"
                        },
                        {
                            "name": "expiration",
                            "value": "1215"
                        }
                    ]
                },
                {
                    "href": "/v1/users/12345/payment-methods/2",
                    "data": [
                        {
                            "name": "name",
                            "value": "Zaphod X Beeblebrox"
                        },
                        {
                            "name": "last4",
                            "value": "4321"
                        },
                        {
                            "name": "type",
                            "value": "Visa"
                        },
                        {
                            "name": "expiration",
                            "value": "1115"
                        }
                    ]
                }
            ],
            "template": {
                "data": [
                    {
                        "prompt": "Cardholder Name",
                        "name": "name"
                    },
                    {
                        "prompt": "Token from Payment Gateway",
                        "name": "token"
                    },
                    {
                        "prompt": "Last 4 Digits of Card",
                        "name": "last4"
                    },
                    {
                        "prompt": "Card Type",
                        "name": "type"
                    },
                    {
                        "prompt": "Expiration Date mmyy",
                        "name": "expiration"
                    },
                    {
                        "prompt": "Postal Code",
                        "name": "postalCode"
                    }
                ]
            }
        }
    }

### /v1/users/{user_id}/payment-methods/{payment_method_id}

Provides detailed information about a user's specific payment type

(also supports DELETE)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/users",
            "items": [
                {
                    "href": "/v1/users/12345/payment-methods/1",
                    "data": [
                        {
                            "name": "name",
                            "value": "Zaphod X Beeblebrox"
                        },
                        {
                            "name": "last4",
                            "value": "1234"
                        },
                        {
                            "name": "type",
                            "value": "Mastercard"
                        },
                        {
                            "name": "expiration",
                            "value": "1215"
                        },
                        {
                            "name": "postalCode",
                            "value": "90210"
                        }
                    ]
                }
            ]
        }
    }

### /v1/users/{user_id}/orders

Provides a summary list of a user's orders, with links to the order for more
detailed information

TODO: this should be paginated with links to next/previous pages

TODO: define how one would query/filter a user's orders

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/users/12345/orders",
            "items": [
                {
                    "href": "/v1/orders/98765",
                    "data": [
                        {
                            "name": "total",
                            "value": "200"
                        }
                    ]
                }
            ],
            "queries": []
        }
    }



### /v1/users/{user_id}/contacts

Provides a summary of a user's contacts (addressbook)

(also supports POST)

Sample GET Response:

TBD

### /v1/users/{user_id}/contacts/{contactid}

Provides detailed information about a user's contact

(also supports PUT, PATCH, DELETE)

Sample GET Response:

TBD

### /v1/orders

Provides a means to search for and create new orders

(also supports POST)

Note: For now, creating new orders are artificially constrained to a single
product with an implied quantity of 1.

The "validate" data element is a hack to allow pre-POSTing an order to get back
pricing information without actually submitting the order. If this field is
blank or omitted, the server should assume that the order is intended to be
submitted.

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/orders",
            "queries": [
                {
                    "href": "/v1/orders",
                    "rel": "search",
                    "prompt": "Search orders",
                    "data": [
                        {
                            "prompt": "Order Id",
                            "name": "orderId"
                        }
                    ]
                }
            ],
            "template": {
                "data": [
                    {
                        "prompt": "Delivery Date",
                        "name": "deliveryDate"
                    },
                    {
                        "prompt": "Promo Code",
                        "name": "promoCode"
                    },
                    {
                        "prompt": "Product",
                        "name": "product_href"
                    },
                    {
                        "prompt": "Recipient Address",
                        "name": "recipient_href"
                    },
                    {
                        "prompt": "Validate Order?",
                        "name": "validate"
                    }
                ]
            }
        }
    }

### /v1/orders/{order_id}

Provides detailed information about a specific order

TODO: need to spec out additional, fulfillment-originated fields

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/orders/987654321",
            "items": {
                "href": "/v1/orders/987654321",
                "data": [
                    {
                        "name": "orderId",
                        "value": "987654321"
                    },
                    {
                        "name": "deliveryDate",
                        "value": "2014-02-14"
                    },
                    {
                        "name": "promoCode",
                        "value": "greatdeal"
                    },
                    {
                        "name": "submitDate",
                        "value": "2014-02-13"
                    }
                ],
                "links": [
                    {
                        "href": "/v1/orders/987654321/items/1",
                        "rel": "item"
                    }
                ]
            }
        }
    }

### /v1/orders/{order_id}/items

Provides a summary list of the items in given order

(also supports POST in the future, when `/v1/orders` can maintain pre-submitted
state)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/orders/987654321/items",
            "items": [
                {
                    "href": "/v1/orders/987654321/items/1",
                    "data": [
                        {
                            "name": "quantity",
                            "value": 1
                        }
                    ],
                    "links": [
                        {
                            "href": "/v1/products/456",
                            "name": "product",
                            "rel": "item"
                        },
                        {
                            "href": "/v1/addresses/789",
                            "name": "address",
                            "rel": "item"
                        }
                    ]
                }
            ]
        }
    }


### /v1/orders/{order_id}/items/{item_id}

Provides detailed information about a specific item in an order

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/orders/987654321/items/1",
            "items": [
                {
                    "href": "/v1/orders/987654321/items/1",
                    "data": [
                        {
                            "name": "quantity",
                            "value": 1
                        }
                    ],
                    "links": [
                        {
                            "href": "/v1/products/456",
                            "name": "product",
                            "rel": "item"
                        },
                        {
                            "href": "/v1/addresses/789",
                            "name": "address",
                            "rel": "item"
                        }
                    ]
                }
            ]
        }
    }

### /v1/promotions

Provides a means to search for promotions

These likely will be hard-coded on client-side for initial launch

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/promotions",
            "queries": [
                {
                    "href": "/v1/promotions",
                    "rel": "search",
                    "prompt": "Search promotions",
                    "data": [
                        {
                            "prompt": "Promotion Code",
                            "name": "promoCode"
                        }
                    ]
                }
            ]
        }
    }

### /v1/promotions/{promotion_id}

Provides detailed information about a specific promotion

These likely will be hard-coded on client-side for initial launch

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/promotions/123",
            "items": [
                {
                    "href": "/v1/promotions/123",
                    "data": [
                        {
                            "name": "promoCode",
                            "value": "greatdeal"
                        },
                        {
                            "name": "description",
                            "value": "Get a great deal on a dozen roses, only $19.99"
                        }
                    ]
                }
            ]
        }
    }

### /v1/addresses

Provides a means to search for and create new addresses

(also supports POST)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/addresses",
            "queries": [
                {
                    "href": "/v1/addresses",
                    "rel": "search",
                    "prompt": "Search addresses",
                    "data": [
                        {
                            "prompt": "Address",
                            "name": "orderId"
                        }
                    ]
                }
            ],
            "template": {
                "data": [
                    {
                        "prompt": "Name",
                        "name": "name"
                    },
                    {
                        "prompt": "Street Address",
                        "name": "streetAddress"
                    },
                    {
                        "prompt": "Post Office Box Number",
                        "name": "postOfficeBoxNumber"
                    },
                    {
                        "prompt": "City",
                        "name": "addressLocality"
                    },
                    {
                        "prompt": "State",
                        "name": "addressRegion"
                    },
                    {
                        "prompt": "Country",
                        "name": "addressCountry"
                    },
                    {
                        "prompt": "Postal Code",
                        "name": "postalCode"
                    }
                ]
            }
        }
    }

### /v1/addresses/{address_id}

Provides detailed information about a specific address

(also supports PUT, PATCH)

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/addresses/789",
            "items": [
                {
                    "href": "/v1/addresses/789",
                    "data": [
                        {
                            "name": "name",
                            "value": "Trillian Astra"
                        },
                        {
                            "name": "streetAddress",
                            "value": "755 5th Avenue"
                        },
                        {
                            "name": "postOfficeBoxNumber",
                            "value": ""
                        },
                        {
                            "name": "addressLocality",
                            "value": "San Diego"
                        },
                        {
                            "name": "addressRegion",
                            "value": "CA"
                        },
                        {
                            "name": "addressCountry",
                            "value": "US"
                        },
                        {
                            "name": "postalCode",
                            "value": "92101"
                        }
                    ]
                }
            ]
        }
    }

### /v1/products

Provides a means to search for products

These likely will be hard-coded on client-side for initial launch

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/products",
            "queries": [
                {
                    "href": "/v1/products",
                    "rel": "search",
                    "prompt": "Search products",
                    "data": [
                        {
                            "prompt": "Product Name",
                            "name": "name"
                        }
                    ]
                }
            ]
        }
    }

### /v1/products/{product_id}

Provides detailed information about a specific product

These likely will be hard-coded on client-side for initial launch

Sample GET Response:

    {
        "collection": {
            "version": "1.0",
            "href": "/v1/products/456",
            "items": [
                {
                    "href": "/v1/products/456",
                    "data": [
                        {
                            "name": "name",
                            "value": "Dozen Roses"
                        },
                        {
                            "name": "description",
                            "value": "One dozen beautiful red roses"
                        }
                    ]
                }
            ]
        }
    }

